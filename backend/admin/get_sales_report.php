<?php

declare(strict_types=1);

require_once __DIR__ . '/common.php';

$admin = admin_require_auth('admin');

/**
 * A "sale" is one reservation that was actually settled at the booth. The
 * booth writes to parking_transactions and the older records live in
 * payments, so both are folded into a single row per reservation the same way
 * get_payments.php does it -- otherwise a reservation carrying rows in both
 * tables would be counted twice.
 */
function sales_paid_source_sql(): string
{
    return "
        SELECT
            COALESCE(pt.paid_at, p.paid_at) AS paid_at,
            COALESCE(pt.total_payment, p.amount, 0) AS amount
        FROM reservations r
        LEFT JOIN parking_transactions pt ON pt.reservation_id = r.id
        LEFT JOIN payments p ON p.reservation_id = r.id
        WHERE COALESCE(pt.paid_at, p.paid_at) IS NOT NULL
          AND UPPER(COALESCE(pt.payment_status, p.payment_status, '')) = 'PAID'
    ";
}

/** Start of the bucket a date belongs to, so windows land on clean edges. */
function sales_period_start(DateTimeImmutable $date, string $granularity): DateTimeImmutable
{
    if ($granularity === 'year') {
        return $date->setDate((int) $date->format('Y'), 1, 1);
    }

    if ($granularity === 'month') {
        return $date->setDate((int) $date->format('Y'), (int) $date->format('n'), 1);
    }

    return $date;
}

function sales_period_key(DateTimeImmutable $date, string $granularity): string
{
    if ($granularity === 'year') {
        return $date->format('Y');
    }

    if ($granularity === 'month') {
        return $date->format('Y-m');
    }

    return $date->format('Y-m-d');
}

function sales_period_labels(DateTimeImmutable $date, string $granularity): array
{
    if ($granularity === 'year') {
        return ['label' => $date->format('Y'), 'fullLabel' => $date->format('Y')];
    }

    if ($granularity === 'month') {
        return ['label' => $date->format('M'), 'fullLabel' => $date->format('F Y')];
    }

    return ['label' => $date->format('M j'), 'fullLabel' => $date->format('D, M j, Y')];
}

try {
    $connection = admin_db();

    $granularity = strtolower(admin_clean_text($_GET['granularity'] ?? 'month'));

    if (!in_array($granularity, ['day', 'month', 'year'], true)) {
        $granularity = 'month';
    }

    // How many buckets a window holds by default: a month of days, a year of
    // months, five years of years.
    $spans = ['day' => 30, 'month' => 12, 'year' => 5];
    $intervals = ['day' => 'P1D', 'month' => 'P1M', 'year' => 'P1Y'];
    $formats = ['day' => '%Y-%m-%d', 'month' => '%Y-%m', 'year' => '%Y'];

    $requestedSpan = (int) ($_GET['periods'] ?? 0);
    $span = $requestedSpan > 0 ? min($requestedSpan, 120) : $spans[$granularity];
    $step = new DateInterval($intervals[$granularity]);
    $format = $formats[$granularity];

    // Anchored on the database clock so the report agrees with the timestamps
    // the booth wrote, even if PHP and MySQL disagree about the timezone.
    $todayRow = $connection->query("SELECT CURDATE() AS today")->fetch_assoc() ?: [];
    $today = new DateTimeImmutable((string) ($todayRow['today'] ?? date('Y-m-d')));

    $windowEnd = $today;
    $windowStart = sales_period_start($today, $granularity);

    for ($offset = 1; $offset < $span; $offset++) {
        $windowStart = $windowStart->sub($step);
    }

    // The matching window immediately before this one, for the trend readout.
    $previousStart = $windowStart;

    for ($offset = 0; $offset < $span; $offset++) {
        $previousStart = $previousStart->sub($step);
    }

    $previousEnd = $windowStart->sub(new DateInterval('P1D'));

    $statement = $connection->prepare("
        SELECT
            DATE_FORMAT(sale.paid_at, '{$format}') AS period_key,
            COALESCE(SUM(sale.amount), 0) AS revenue,
            COUNT(*) AS transactions
        FROM (" . sales_paid_source_sql() . ") AS sale
        WHERE DATE(sale.paid_at) BETWEEN ? AND ?
        GROUP BY period_key
        ORDER BY period_key ASC
    ");

    $rangeStart = $previousStart->format('Y-m-d');
    $rangeEnd = $windowEnd->format('Y-m-d');
    $statement->bind_param('ss', $rangeStart, $rangeEnd);
    $statement->execute();
    $bucketResult = $statement->get_result();

    $buckets = [];

    while ($row = $bucketResult->fetch_assoc()) {
        $buckets[(string) $row['period_key']] = [
            'revenue' => (float) $row['revenue'],
            'transactions' => (int) $row['transactions']
        ];
    }

    // Every bucket in the window is emitted, including the empty ones, so a
    // quiet day still reads as a zero instead of vanishing from the axis.
    $series = [];
    $cursor = $windowStart;

    for ($index = 0; $index < $span; $index++) {
        $key = sales_period_key($cursor, $granularity);
        $labels = sales_period_labels($cursor, $granularity);
        $revenue = round((float) ($buckets[$key]['revenue'] ?? 0), 2);
        $transactions = (int) ($buckets[$key]['transactions'] ?? 0);

        $series[] = [
            'periodKey' => $key,
            'label' => $labels['label'],
            'fullLabel' => $labels['fullLabel'],
            'revenue' => $revenue,
            'transactions' => $transactions,
            'averageTicket' => $transactions > 0 ? round($revenue / $transactions, 2) : 0.0
        ];

        $cursor = $cursor->add($step);
    }

    $windowRevenue = 0.0;
    $windowTransactions = 0;
    $bestPeriod = null;

    foreach ($series as $entry) {
        $windowRevenue += $entry['revenue'];
        $windowTransactions += $entry['transactions'];

        if ($bestPeriod === null || $entry['revenue'] > $bestPeriod['revenue']) {
            $bestPeriod = $entry;
        }
    }

    // Previous window total: taken from the same result set, so the trend
    // comparison costs no extra query.
    $previousRevenue = 0.0;
    $previousTransactions = 0;
    $cursor = $previousStart;

    while ($cursor <= $previousEnd) {
        $key = sales_period_key($cursor, $granularity);
        $previousRevenue += (float) ($buckets[$key]['revenue'] ?? 0);
        $previousTransactions += (int) ($buckets[$key]['transactions'] ?? 0);
        $cursor = $cursor->add($step);
    }

    $growth = null;

    if ($previousRevenue > 0) {
        $growth = round((($windowRevenue - $previousRevenue) / $previousRevenue) * 100, 1);
    }

    // Headline totals stand on their own calendar periods, independent of the
    // chart window, because "sales today" should not move when the owner
    // switches the chart over to years.
    $headlineRow = $connection->query("
        SELECT
            COALESCE(SUM(CASE WHEN DATE(sale.paid_at) = CURDATE() THEN sale.amount ELSE 0 END), 0) AS today_revenue,
            SUM(CASE WHEN DATE(sale.paid_at) = CURDATE() THEN 1 ELSE 0 END) AS today_transactions,
            COALESCE(SUM(CASE WHEN DATE_FORMAT(sale.paid_at, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m') THEN sale.amount ELSE 0 END), 0) AS month_revenue,
            SUM(CASE WHEN DATE_FORMAT(sale.paid_at, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m') THEN 1 ELSE 0 END) AS month_transactions,
            COALESCE(SUM(CASE WHEN YEAR(sale.paid_at) = YEAR(CURDATE()) THEN sale.amount ELSE 0 END), 0) AS year_revenue,
            SUM(CASE WHEN YEAR(sale.paid_at) = YEAR(CURDATE()) THEN 1 ELSE 0 END) AS year_transactions,
            COALESCE(SUM(sale.amount), 0) AS lifetime_revenue,
            COUNT(*) AS lifetime_transactions,
            COALESCE(SUM(CASE WHEN DATE(sale.paid_at) = DATE_SUB(CURDATE(), INTERVAL 1 DAY) THEN sale.amount ELSE 0 END), 0) AS yesterday_revenue,
            COALESCE(SUM(CASE WHEN DATE_FORMAT(sale.paid_at, '%Y-%m') = DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 1 MONTH), '%Y-%m') THEN sale.amount ELSE 0 END), 0) AS last_month_revenue,
            COALESCE(SUM(CASE WHEN YEAR(sale.paid_at) = YEAR(CURDATE()) - 1 THEN sale.amount ELSE 0 END), 0) AS last_year_revenue
        FROM (" . sales_paid_source_sql() . ") AS sale
    ")->fetch_assoc() ?: [];

    // Money already earned at the booth but not collected yet: the vehicle is
    // out and the transaction is still not marked Paid.
    $outstandingRow = $connection->query("
        SELECT
            COUNT(*) AS pending_count,
            COALESCE(SUM(COALESCE(pt.total_payment, p.amount, 0)), 0) AS pending_amount
        FROM reservations r
        LEFT JOIN parking_transactions pt ON pt.reservation_id = r.id
        LEFT JOIN payments p ON p.reservation_id = r.id
        WHERE pt.actual_time_out IS NOT NULL
          AND UPPER(COALESCE(pt.payment_status, p.payment_status, 'UNPAID')) <> 'PAID'
    ")->fetch_assoc() ?: [];

    $lifetimeRevenue = (float) ($headlineRow['lifetime_revenue'] ?? 0);
    $lifetimeTransactions = (int) ($headlineRow['lifetime_transactions'] ?? 0);

    // Operations, scoped to the same window as the chart. Revenue alone does
    // not say whether the lot is busy, how long a car stays, which floors earn
    // their space, or how the money arrives -- these do.
    $operationsStatement = $connection->prepare("
        SELECT
            COUNT(*) AS completed_stays,
            COALESCE(AVG(NULLIF(pt.total_hours_stayed, 0)), 0) AS average_hours,
            COALESCE(MAX(pt.total_hours_stayed), 0) AS longest_hours,
            COUNT(DISTINCT CONCAT(r.parking_floor, '|', r.parking_slot)) AS slots_used,
            SUM(CASE WHEN COALESCE(r.is_walk_in, 0) = 1 THEN 1 ELSE 0 END) AS walk_in_count
        FROM reservations r
        JOIN parking_transactions pt ON pt.reservation_id = r.id
        WHERE pt.actual_time_out IS NOT NULL
          AND DATE(pt.actual_time_out) BETWEEN ? AND ?
    ");
    $windowStartDate = $windowStart->format('Y-m-d');
    $operationsStatement->bind_param('ss', $windowStartDate, $rangeEnd);
    $operationsStatement->execute();
    $operationsRow = $operationsStatement->get_result()->fetch_assoc() ?: [];

    $activeSlotsRow = $connection->query("
        SELECT COUNT(*) AS total FROM parking_slots WHERE is_active = 1 AND status <> 'Inactive'
    ")->fetch_assoc() ?: [];
    $activeSlots = (int) ($activeSlotsRow['total'] ?? 0);
    $slotsUsed = (int) ($operationsRow['slots_used'] ?? 0);

    $floorStatement = $connection->prepare("
        SELECT
            COALESCE(NULLIF(TRIM(r.parking_floor), ''), 'Unassigned') AS floor_name,
            COUNT(*) AS transactions,
            COALESCE(SUM(COALESCE(pt.total_payment, 0)), 0) AS revenue
        FROM reservations r
        JOIN parking_transactions pt ON pt.reservation_id = r.id
        WHERE pt.paid_at IS NOT NULL
          AND DATE(pt.paid_at) BETWEEN ? AND ?
        GROUP BY floor_name
        ORDER BY revenue DESC
    ");
    $floorStatement->bind_param('ss', $windowStartDate, $rangeEnd);
    $floorStatement->execute();
    $floorResult = $floorStatement->get_result();

    $revenueByFloor = [];

    while ($row = $floorResult->fetch_assoc()) {
        $revenueByFloor[] = [
            'label' => (string) $row['floor_name'],
            'transactions' => (int) $row['transactions'],
            'revenue' => round((float) $row['revenue'], 2)
        ];
    }

    $methodStatement = $connection->prepare("
        SELECT
            COALESCE(NULLIF(TRIM(pt.payment_method), ''), 'Unrecorded') AS method,
            COUNT(*) AS transactions,
            COALESCE(SUM(pt.total_payment), 0) AS revenue
        FROM parking_transactions pt
        WHERE pt.paid_at IS NOT NULL
          AND DATE(pt.paid_at) BETWEEN ? AND ?
        GROUP BY method
        ORDER BY revenue DESC
    ");
    $methodStatement->bind_param('ss', $windowStartDate, $rangeEnd);
    $methodStatement->execute();
    $methodResult = $methodStatement->get_result();

    $paymentMethods = [];

    while ($row = $methodResult->fetch_assoc()) {
        $paymentMethods[] = [
            'label' => (string) $row['method'],
            'transactions' => (int) $row['transactions'],
            'revenue' => round((float) $row['revenue'], 2)
        ];
    }

    // No-shows in the window, which is the counterweight to the revenue line:
    // every one of them is a slot that was held and never paid for.
    $noShowStatement = $connection->prepare("
        SELECT
            SUM(CASE WHEN LOWER(COALESCE(barcode_status, 'active')) = 'expired' THEN 1 ELSE 0 END) AS expired_count,
            COUNT(*) AS total_count
        FROM reservations
        WHERE DATE(created_at) BETWEEN ? AND ?
    ");
    $noShowStatement->bind_param('ss', $windowStartDate, $rangeEnd);
    $noShowStatement->execute();
    $noShowRow = $noShowStatement->get_result()->fetch_assoc() ?: [];
    $noShowTotal = max(1, (int) ($noShowRow['total_count'] ?? 0));

    $completedStays = (int) ($operationsRow['completed_stays'] ?? 0);

    $operations = [
        'completedStays' => $completedStays,
        'averageDwellHours' => round((float) ($operationsRow['average_hours'] ?? 0), 2),
        'longestDwellHours' => round((float) ($operationsRow['longest_hours'] ?? 0), 2),
        'activeSlots' => $activeSlots,
        'slotsUsed' => $slotsUsed,
        'slotCoveragePercent' => $activeSlots > 0 ? round(($slotsUsed / $activeSlots) * 100, 1) : 0.0,
        'turnsPerSlot' => $slotsUsed > 0 ? round($completedStays / $slotsUsed, 2) : 0.0,
        'walkInCount' => (int) ($operationsRow['walk_in_count'] ?? 0),
        'walkInSharePercent' => $completedStays > 0
            ? round(((int) ($operationsRow['walk_in_count'] ?? 0) / $completedStays) * 100, 1)
            : 0.0,
        'noShowCount' => (int) ($noShowRow['expired_count'] ?? 0),
        'noShowRatePercent' => round(((int) ($noShowRow['expired_count'] ?? 0) / $noShowTotal) * 100, 1),
        'revenueByFloor' => $revenueByFloor,
        'paymentMethods' => $paymentMethods
    ];

    admin_success('Sales report loaded successfully.', [
        'granularity' => $granularity,
        'range' => [
            'start' => $windowStart->format('Y-m-d'),
            'end' => $windowEnd->format('Y-m-d'),
            'periods' => $span,
            'previousStart' => $previousStart->format('Y-m-d'),
            'previousEnd' => $previousEnd->format('Y-m-d')
        ],
        'headline' => [
            'today' => [
                'label' => $today->format('M j, Y'),
                'revenue' => round((float) ($headlineRow['today_revenue'] ?? 0), 2),
                'transactions' => (int) ($headlineRow['today_transactions'] ?? 0),
                'previousRevenue' => round((float) ($headlineRow['yesterday_revenue'] ?? 0), 2)
            ],
            'month' => [
                'label' => $today->format('F Y'),
                'revenue' => round((float) ($headlineRow['month_revenue'] ?? 0), 2),
                'transactions' => (int) ($headlineRow['month_transactions'] ?? 0),
                'previousRevenue' => round((float) ($headlineRow['last_month_revenue'] ?? 0), 2)
            ],
            'year' => [
                'label' => $today->format('Y'),
                'revenue' => round((float) ($headlineRow['year_revenue'] ?? 0), 2),
                'transactions' => (int) ($headlineRow['year_transactions'] ?? 0),
                'previousRevenue' => round((float) ($headlineRow['last_year_revenue'] ?? 0), 2)
            ],
            'lifetime' => [
                'revenue' => round($lifetimeRevenue, 2),
                'transactions' => $lifetimeTransactions,
                'averageTicket' => $lifetimeTransactions > 0
                    ? round($lifetimeRevenue / $lifetimeTransactions, 2)
                    : 0.0
            ],
            'outstanding' => [
                'count' => (int) ($outstandingRow['pending_count'] ?? 0),
                'amount' => round((float) ($outstandingRow['pending_amount'] ?? 0), 2)
            ]
        ],
        'window' => [
            'revenue' => round($windowRevenue, 2),
            'transactions' => $windowTransactions,
            'averageTicket' => $windowTransactions > 0 ? round($windowRevenue / $windowTransactions, 2) : 0.0,
            'previousRevenue' => round($previousRevenue, 2),
            'previousTransactions' => $previousTransactions,
            'growthPercent' => $growth,
            'bestPeriod' => $bestPeriod
        ],
        'operations' => $operations,
        'series' => $series
    ]);
} catch (Throwable $exception) {
    admin_log('get-sales-report-failed', ['error' => $exception->getMessage()]);
    admin_error('Failed to load the sales report.', 500, [
        'details' => $exception->getMessage()
    ]);
}
