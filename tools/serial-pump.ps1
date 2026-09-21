<#
    Serial co-process for the SNDRA Park slot-sensor bridge.

    PHP on this machine has no dio, com_dotnet or sockets extension, and
    stream_select() on Windows only works on sockets -- so a PHP process cannot
    read a COM port with a real timeout, and a plain fgets() on an unplugged
    board blocks forever with the process alive and silent.

    This helper owns the port instead and relays whole lines on stdout, which
    PHP reads with an ordinary blocking fgets(). That buys three things PHP
    cannot get on its own:

      * a real ReadTimeout, so a quiet port emits a synthetic idle line every
        second and the PHP read never blocks for long
      * a detectable unplug, because losing the port raises an exception, this
        process exits, and PHP sees EOF on the pipe and reconnects
      * an explicit DtrEnable, so the Uno auto-reset is a deliberate event we
        wait out rather than a mystery two seconds of garbage

    Downlink arrives through a drop-file rather than stdin. proc_open pipes on
    Windows are anonymous pipes and [Console]::In.Peek() blocks, so a
    bidirectional stdio pipe here is a trap.

    Started by backend/cli/serial-bridge.php; not usually run by hand.
#>

param(
    [string] $Port = 'COM3',
    [int]    $Baud = 115200,
    [string] $DownlinkFile = '',
    [int]    $ParentPid = 0
)

$ErrorActionPreference = 'Stop'

if ([string]::IsNullOrWhiteSpace($DownlinkFile)) {
    $DownlinkFile = Join-Path (Split-Path -Parent $PSScriptRoot) 'storage\cache\serial-downlink.txt'
}

try {
    $serial = New-Object System.IO.Ports.SerialPort $Port, $Baud, 'None', 8, 'One'
    $serial.NewLine = "`n"
    $serial.ReadTimeout = 1000
    $serial.WriteTimeout = 1000
    $serial.DtrEnable = $true
    $serial.Open()
}
catch {
    [Console]::Error.WriteLine("could not open $Port : $($_.Exception.Message)")
    exit 2
}

# Opening the port with DTR asserted resets an Uno. Everything it says for the
# next couple of seconds is bootloader noise; the bridge's checksum would reject
# it anyway, but discarding it here keeps the reject counter honest.
Start-Sleep -Milliseconds 2500
try { $serial.DiscardInBuffer() } catch { }

$lastDownlinkWrite = [DateTime]::MinValue

try {
    while ($true) {
        # Die with the bridge that started us. A COM port is exclusive on
        # Windows, so an orphaned pump silently holds the port and every later
        # bridge loops on "link closed, reconnecting" forever. Killing the
        # bridge abruptly skips its proc_terminate, and a broken stdout pipe is
        # not reliably raised here, so the parent is checked outright.
        if ($ParentPid -gt 0) {
            if (-not (Get-Process -Id $ParentPid -ErrorAction SilentlyContinue)) {
                [Console]::Error.WriteLine('bridge process gone, releasing port')
                exit 0
            }
        }

        try {
            $line = $serial.ReadLine()
            if (-not [string]::IsNullOrWhiteSpace($line)) {
                [Console]::Out.WriteLine($line.Trim())
            }
        }
        catch [TimeoutException] {
            # Nothing said in a second. Emit a line anyway so the PHP side's
            # blocking read returns and its watchdog can tick. The bridge
            # rejects this as unparseable, which is the intent.
            [Console]::Out.WriteLine('SP1|LOCAL|0|IDLE||00')
        }

        # Push the staged LCD frame down whenever it changes.
        if (Test-Path -LiteralPath $DownlinkFile) {
            $written = (Get-Item -LiteralPath $DownlinkFile).LastWriteTimeUtc

            if ($written -gt $lastDownlinkWrite) {
                $lastDownlinkWrite = $written
                $frame = (Get-Content -LiteralPath $DownlinkFile -Raw -ErrorAction SilentlyContinue)

                if (-not [string]::IsNullOrWhiteSpace($frame)) {
                    try { $serial.WriteLine($frame.Trim()) } catch { }
                }
            }
        }
    }
}
catch {
    # The port went away: unplugged, driver reset, or the board was reflashed.
    # Exiting is the signal -- PHP sees EOF and reconnects with backoff.
    [Console]::Error.WriteLine("link lost on $Port : $($_.Exception.Message)")
    exit 3
}
finally {
    if ($serial -and $serial.IsOpen) { $serial.Close() }
}
