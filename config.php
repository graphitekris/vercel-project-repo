<?php
declare(strict_types=1);

/* Load .env */
$envFile = __DIR__ . '/.env';
if (is_readable($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        if (strpos($line, '=') === false) continue;
        [$key, $value] = explode('=', $line, 2);
        $key   = trim($key);
        $value = trim($value);
        $len = strlen($value);
        if ($len >= 2 && ($value[0] === '"' || $value[0] === "'") && $value[0] === $value[$len - 1]) {
            $value = substr($value, 1, -1);
        }
        $_ENV[$key] = $value;
        putenv("$key=$value");
    }
}

function env(string $key, $default = null) {
    $v = $_ENV[$key] ?? getenv($key);
    return ($v === false || $v === null || $v === '') ? $default : $v;
}

date_default_timezone_set(env('APP_TIMEZONE', 'Asia/Manila'));

$debug = (env('APP_DEBUG', 'false') === 'true');
ini_set('display_errors', $debug ? '1' : '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

$logDir = __DIR__ . '/logs';
if (!is_dir($logDir)) { @mkdir($logDir, 0775, true); }
ini_set('error_log', $logDir . '/error.log');

set_exception_handler(function (Throwable $e) use ($debug) {
    error_log('Uncaught: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(500);
    if ($debug) {
        echo '<pre style="background:#1c2128;color:#f44747;padding:20px;border-radius:8px;">';
        echo htmlspecialchars($e->getMessage()) . "\n\n";
        echo htmlspecialchars($e->getTraceAsString());
        echo '</pre>';
    } else {
        echo '<!DOCTYPE html><html><head><title>Error</title></head><body style="font-family:sans-serif;background:#0d1117;color:#c9d1d9;padding:60px;">';
        echo '<h1 style="color:#f0f6fc;">Something went wrong</h1>';
        echo '<p>The system encountered an error. Please try again, or contact your administrator.</p>';
        echo '<p><a href="index.php" style="color:#0098ff;">Return to home</a></p>';
        echo '</body></html>';
    }
    exit;
});

$dsn = 'mysql:host=' . env('DB_HOST', '127.0.0.1')
     . ';dbname='    . env('DB_NAME', 'stockflow')
     . ';charset=utf8mb4';

try {
    $pdo = new PDO($dsn, env('DB_USER', 'root'), env('DB_PASS', ''), [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    $offset = (new DateTime('now', new DateTimeZone(date_default_timezone_get())))->format('P');
    $pdo->exec("SET time_zone = '{$offset}'");
} catch (PDOException $e) {
    error_log('DB connect failed: ' . $e->getMessage());
    http_response_code(500);
    exit($debug ? 'DB failed: ' . htmlspecialchars($e->getMessage()) : 'Database connection failed.');
}

function db(): PDO {
    global $pdo;
    if (!$pdo instanceof PDO) throw new RuntimeException('Database connection not initialised.');
    return $pdo;
}