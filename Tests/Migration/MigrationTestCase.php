<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Tests\Migration;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Migration tests deliberately change a schema rather than rolling their changes back, so each
 * one gets a scratch database of its own. It is built from the structure of the regular test
 * database, with every plugin table removed again, which leaves exactly what a customer has
 * before installing this plugin: a working Kimai, and no trace of us.
 */
abstract class MigrationTestCase extends TestCase
{
    protected const FIRST_VERSION = 'KimaiLexwareSyncBundle\Migrations\Version20260904120000';
    protected const CONTACT_NAME_VERSION = 'KimaiLexwareSyncBundle\Migrations\Version20260905160000';

    protected string $scratchDatabase;
    protected \PDO $connection;

    private string $host;
    private int $port;
    private string $user;
    private string $password;
    private string $templateDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $url = getenv('TEST_DATABASE_URL');
        $parsed = parse_url(\is_string($url) && $url !== '' ? $url : 'mysql://kimai:kimai@sqldb/kimai_test');
        if (!\is_array($parsed) || !isset($parsed['host'], $parsed['path'])) {
            self::markTestSkipped('No usable TEST_DATABASE_URL for the migration tests.');
        }

        $this->host = (string) $parsed['host'];
        $this->port = (int) ($parsed['port'] ?? 3306);
        $this->user = isset($parsed['user']) ? (string) $parsed['user'] : 'root';
        $this->password = isset($parsed['pass']) ? (string) $parsed['pass'] : '';
        $this->templateDatabase = ltrim((string) $parsed['path'], '/');
        // The process identifier keeps two concurrent runs apart. With a shared name they drop
        // and recreate each other's database halfway through, which fails in a way that looks
        // like a broken migration rather than a collision.
        $this->scratchDatabase = $this->templateDatabase . '_migration_' . getmypid();

        $this->createScratchDatabaseFromTemplate();
        $this->connection = new \PDO(
            \sprintf('mysql:host=%s;port=%d;dbname=%s', $this->host, $this->port, $this->scratchDatabase),
            $this->user,
            $this->password,
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
        );
    }

    protected function tearDown(): void
    {
        unset($this->connection);
        $this->administer('DROP DATABASE IF EXISTS `' . $this->scratchDatabase . '`;');
        parent::tearDown();
    }

    protected function migrate(string $version = 'latest'): void
    {
        $kimaiDirectory = \dirname(__DIR__, 5);
        $configuration = \dirname(__DIR__) . '/../Migrations/doctrine_migrations.yaml';

        $process = new Process(
            [
                'php',
                'bin/console',
                'doctrine:migrations:migrate',
                $version,
                '--no-interaction',
                '--allow-no-migration',
                '--configuration=' . $configuration,
            ],
            $kimaiDirectory,
            [
                'APP_ENV' => 'dev',
                'DATABASE_URL' => \sprintf(
                    'mysql://%s:%s@%s:%d/%s?charset=utf8mb4&serverVersion=8.3.0',
                    $this->user,
                    $this->password,
                    $this->host,
                    $this->port,
                    $this->scratchDatabase
                ),
            ],
            null,
            120
        );
        $process->run();

        self::assertSame(
            0,
            $process->getExitCode(),
            'Migrating to ' . $version . " failed:\n" . $process->getOutput() . $process->getErrorOutput()
        );
    }

    /**
     * @return list<string>
     */
    protected function tableNames(?string $pattern = null): array
    {
        $statement = $this->connection->query($pattern === null ? 'SHOW TABLES' : "SHOW TABLES LIKE '" . $pattern . "'");
        if ($statement === false) {
            return [];
        }

        $names = array_values(array_map(strval(...), $statement->fetchAll(\PDO::FETCH_COLUMN)));
        sort($names);

        return $names;
    }

    /**
     * Doctrine writes its own bookkeeping table when it runs the plugin's migrations for the
     * first time. It is created by the migration machinery rather than by a migration, and its
     * name follows Kimai's convention for a plugin, so it is not part of what a table name
     * assertion is about.
     *
     * @param list<string> $before
     *
     * @return list<string>
     */
    protected function tablesCreatedSince(array $before): array
    {
        $created = array_values(array_diff($this->tableNames(), $before, ['bundle_migration_lexware_sync']));
        sort($created);

        return $created;
    }

    /**
     * @return list<string>
     */
    protected function columnNames(string $table): array
    {
        $statement = $this->connection->query('SHOW COLUMNS FROM `' . $table . '`');
        if ($statement === false) {
            return [];
        }

        return array_values(array_map(strval(...), $statement->fetchAll(\PDO::FETCH_COLUMN)));
    }

    private function createScratchDatabaseFromTemplate(): void
    {
        $this->administer(\sprintf(
            'DROP DATABASE IF EXISTS `%s`; CREATE DATABASE `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;',
            $this->scratchDatabase,
            $this->scratchDatabase
        ));

        $dump = new Process([
            'mysqldump',
            '--host=' . $this->host,
            '--port=' . $this->port,
            '--user=' . $this->user,
            '--password=' . $this->password,
            '--no-data',
            '--skip-comments',
            $this->templateDatabase,
        ], null, null, null, 120);
        $dump->mustRun();

        $restore = new Process([
            'mysql',
            '--host=' . $this->host,
            '--port=' . $this->port,
            '--user=' . $this->user,
            '--password=' . $this->password,
            $this->scratchDatabase,
        ], null, null, $dump->getOutput(), 120);
        $restore->mustRun();

        $connection = new \PDO(
            \sprintf('mysql:host=%s;port=%d;dbname=%s', $this->host, $this->port, $this->scratchDatabase),
            $this->user,
            $this->password,
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
        );

        $connection->exec('SET FOREIGN_KEY_CHECKS = 0');
        $statement = $connection->query("SHOW TABLES LIKE 'kimai2_ext_lexware_%'");
        $pluginTables = $statement === false ? [] : $statement->fetchAll(\PDO::FETCH_COLUMN);
        foreach ($pluginTables as $table) {
            $connection->exec('DROP TABLE IF EXISTS `' . (string) $table . '`');
        }
        $connection->exec('DROP TABLE IF EXISTS `bundle_migration_lexware_sync`');
        $connection->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    private function administer(string $statements): void
    {
        $connection = new \PDO(
            \sprintf('mysql:host=%s;port=%d', $this->host, $this->port),
            $this->user,
            $this->password,
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
        );

        $connection->exec($statements);
    }
}
