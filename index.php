<?php

declare(strict_types=1);

/**
 * Project entry point.
 *
 * .htaccess sets DirectoryIndex index.php with Options -Indexes, and there was
 * no index.php here -- so the obvious URL, http://localhost/sndraPark/, answered
 * 403 Forbidden. Anyone who did not already know to type the full path to
 * frontend/pages/index.html was simply locked out of the front door.
 */

$scriptName = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
$basePath = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');

header('Location: ' . $basePath . '/frontend/pages/index.html', true, 302);
exit;
