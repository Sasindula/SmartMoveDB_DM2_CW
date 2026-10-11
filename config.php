<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$_SESSION['csrf_token'] ??= bin2hex(random_bytes(32));

// Oracle connection
$oracle_conn = null;
$smLocalFile=__DIR__.'/config.local.php';
$smLocal=is_file($smLocalFile) ? require $smLocalFile : [];
if (!is_array($smLocal)) { http_response_code(503); exit('config.local.php must return an array.'); }
$smOracleDsn=getenv('SMARTMOVE_ORACLE_DSN') ?: ($smLocal['oracle_dsn'] ?? 'oci:dbname=//localhost:1521/XEPDB1;charset=AL32UTF8');
$smOracleUser=getenv('SMARTMOVE_ORACLE_USER') ?: ($smLocal['oracle_user'] ?? 'SMARTMOVE');
$smOraclePassword=getenv('SMARTMOVE_ORACLE_PASSWORD');
if ($smOraclePassword===false) $smOraclePassword=$smLocal['oracle_password'] ?? '';
$smMongoUri=getenv('SMARTMOVE_MONGO_URI') ?: ($smLocal['mongo_uri'] ?? 'mongodb://localhost:27017');


try {
    $oracle_conn = new PDO(
        $smOracleDsn,
        $smOracleUser,
        $smOraclePassword,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_CASE => PDO::CASE_LOWER
        ]
    );
} catch (PDOException $e) {
    error_log('Oracle connection: ' . $e->getMessage());
    http_response_code(503);
    exit('Oracle connection failed. Check the PHP error log.');
}

// MongoDB connection
$mongo_conn = null;
$mongo_database = 'SmartMoveDB';

if (class_exists('MongoDB\\Driver\\Manager')) {
    try {
        $mongo_conn = new MongoDB\Driver\Manager(
            $smMongoUri,
            [
                'serverSelectionTimeoutMS' => 3000,
                'connectTimeoutMS' => 3000
            ]
        );
    } catch (Throwable $e) {
        error_log('MongoDB connection: ' . $e->getMessage());
    }
} else {
    error_log('MongoDB PHP extension is not installed or enabled.');
}

// Compatibility for Maintenance module
$mongo_manager = $mongo_conn;

function escapeHtml($value): string
{
    return htmlspecialchars(
        (string)($value ?? ''),
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

function log_activity(string $action, string $username): void
{
    global $mongo_conn, $mongo_database;

    if ($mongo_conn === null) {
        return;
    }

    try {
        $bulk = new MongoDB\Driver\BulkWrite();

        $bulk->insert([
            'user' => $username,
            'action' => $action,
            'timestamp' => new MongoDB\BSON\UTCDateTime()
        ]);

        $mongo_conn->executeBulkWrite(
            $mongo_database . '.activities',
            $bulk
        );
    } catch (Throwable $e) {
        error_log('Activity logging: ' . $e->getMessage());
    }
}
