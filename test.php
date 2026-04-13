<?php
// Standalone diagnostic — no dependencies
header('Content-Type: application/json');

$checks = [];
$checks['php_version'] = PHP_VERSION;
$checks['sqlite3_extension'] = extension_loaded('sqlite3');
$checks['sqlite3_class'] = class_exists('SQLite3');
$checks['pdo_sqlite'] = extension_loaded('pdo_sqlite');

$dataDir = __DIR__ . '/data';
$checks['data_dir_exists'] = is_dir($dataDir);
$checks['data_dir_writable'] = is_writable($dataDir);
$checks['parent_dir_writable'] = is_writable(__DIR__);

// Try creating data dir if missing
if (!$checks['data_dir_exists']) {
    $checks['mkdir_attempt'] = @mkdir($dataDir, 0755, true);
    $checks['data_dir_exists'] = is_dir($dataDir);
    $checks['data_dir_writable'] = is_writable($dataDir);
}

// Try opening SQLite if extension available
if ($checks['sqlite3_class']) {
    try {
        if ($checks['data_dir_writable']) {
            $db = new SQLite3($dataDir . '/test.db');
            $db->exec('CREATE TABLE IF NOT EXISTS test (id INTEGER)');
            $checks['sqlite_works'] = true;
            $db->close();
            @unlink($dataDir . '/test.db');
        } else {
            $checks['sqlite_works'] = 'skipped — data dir not writable';
        }
    } catch (Throwable $e) {
        $checks['sqlite_works'] = false;
        $checks['sqlite_error'] = $e->getMessage();
    }
} else {
    $checks['sqlite_works'] = false;
    $checks['sqlite_error'] = 'SQLite3 class not available';
}

$checks['loaded_extensions'] = get_loaded_extensions();

echo json_encode($checks, JSON_PRETTY_PRINT);
