<?php

declare(strict_types=1);

use Symfony\Component\Dotenv\Dotenv;

$kimaiDirectory = dirname(__DIR__, 4);

$loader = require $kimaiDirectory . '/vendor/autoload.php';
$loader->addPsr4('KimaiPlugin\\KimaiLexwareSyncBundle\\', dirname(__DIR__) . '/');

if (is_file($kimaiDirectory . '/.env')) {
    (new Dotenv())->bootEnv($kimaiDirectory . '/.env');
}

$databaseUrl = getenv('TEST_DATABASE_URL');
if (!is_string($databaseUrl) || $databaseUrl === '') {
    $databaseUrl = 'mysql://kimai:kimai@sqldb/kimai_test?charset=utf8mb4&serverVersion=8.3.0';
}

// Kimai ships an empty APP_SECRET and fills it during installation. Security features such as
// the remember me signature refuse to work without one, so tests get a fixed value of their own.
$overrides = [
    'APP_ENV' => 'test',
    'APP_DEBUG' => '1',
    'APP_SECRET' => 'kimai-lexware-sync-test-secret',
    'DATABASE_URL' => $databaseUrl,
];

foreach ($overrides as $name => $value) {
    $_ENV[$name] = $_SERVER[$name] = $value;
    putenv($name . '=' . $value);
}

$arguments = [];
foreach (is_array($_SERVER['argv'] ?? null) ? $_SERVER['argv'] : [] as $argument) {
    if (is_string($argument)) {
        $arguments[] = $argument;
    }
}

$requestedSuite = null;
foreach ($arguments as $index => $argument) {
    if ($argument === '--testsuite' && isset($arguments[$index + 1])) {
        $requestedSuite = $arguments[$index + 1];
    } elseif (str_starts_with($argument, '--testsuite=')) {
        $requestedSuite = substr($argument, strlen('--testsuite='));
    }
}

// The unit suite deliberately needs nothing but the autoloader, so that a contributor without a
// database can still run it.
if ($requestedSuite === 'unit') {
    return;
}

$parsed = parse_url($databaseUrl);
if (!is_array($parsed) || !isset($parsed['host'], $parsed['path'])) {
    throw new RuntimeException('TEST_DATABASE_URL is not a usable database URL: ' . $databaseUrl);
}

$database = ltrim((string) $parsed['path'], '/');
$hint = 'Run `just test-database` once to create and migrate it.';

try {
    $connection = new PDO(
        sprintf('mysql:host=%s;port=%d;dbname=%s', (string) $parsed['host'], (int) ($parsed['port'] ?? 3306), $database),
        isset($parsed['user']) ? (string) $parsed['user'] : 'root',
        isset($parsed['pass']) ? (string) $parsed['pass'] : '',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $statement = $connection->query("SHOW TABLES LIKE 'kimai2_ext_lexware_order_confirmation'");
} catch (PDOException $exception) {
    throw new RuntimeException(sprintf("Cannot reach the test database '%s': %s\n%s", $database, $exception->getMessage(), $hint), 0, $exception);
}

if ($statement === false || $statement->fetchAll() === []) {
    throw new RuntimeException(sprintf("The test database '%s' has no plugin tables yet.\n%s", $database, $hint));
}
