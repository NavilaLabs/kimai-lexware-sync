<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Tests\Contract;

use App\Configuration\SystemConfiguration;
use KimaiPlugin\KimaiLexwareSyncBundle\Client\LexwareApiClient;
use KimaiPlugin\KimaiLexwareSyncBundle\Configuration\LexwareSyncConfiguration;
use KimaiPlugin\KimaiLexwareSyncBundle\Tests\Support\FunctionalTestCase;
use Symfony\Component\HttpClient\HttpClient;

/**
 * These tests talk to the real Lexware API of a dedicated test account. They exist to notice
 * that Lexware changed something, which no amount of recorded fixtures can tell us. They are
 * never part of a normal run: they are slow, they need credentials, and the writing ones leave
 * documents behind that Lexware offers no way to delete.
 */
abstract class LexwareContractTestCase extends FunctionalTestCase
{
    private ?LexwareApiClient $apiClient = null;

    protected function setUp(): void
    {
        $key = $this->apiKey();
        if ($key === '') {
            self::markTestSkipped('Set LEXWARE_TEST_API_KEY to run the contract tests against the Lexware test account.');
        }

        parent::setUp();
        $this->apiClient = null;
        $this->service(SystemConfiguration::class)->set('lexware_sync.api_key', $key);

        // The client paces its own requests, but only within one instance, and every test builds
        // a fresh one. Lexware allows two requests per second across the whole account, so the
        // gap between two tests has to be kept by hand.
        usleep(1_000_000);
    }

    /**
     * The real HTTP client on purpose: the fake one wired into the test container is exactly
     * what these tests exist to bypass.
     */
    protected function client(): LexwareApiClient
    {
        // One client per test, because it paces its own requests and Lexware allows only two
        // per second. A fresh instance per call would forget that pacing and hit the limit.
        return $this->apiClient ??= new LexwareApiClient(HttpClient::create(), $this->service(LexwareSyncConfiguration::class));
    }

    /**
     * Everything this test suite creates carries this marker, so a human looking at the test
     * account can tell machine made documents from real ones at a glance.
     */
    protected function marker(): string
    {
        // Lexware rejects a title longer than 25 characters, which a contract run found the
        // hard way on 2026-09-05.
        return 'CONTRACT TEST ' . date('m-d H:i');
    }

    /**
     * The first order confirmation of the test account, fetched in full. Several tests need one
     * as a starting point, and none of them can assume the account holds any.
     *
     * @return array<string, mixed>
     */
    protected function anOrderConfirmation(): array
    {
        $page = $this->client()->listOrderConfirmationVoucherPage(0);
        $content = $this->nestedList($page, 'content', 'voucher list');

        if ($content === []) {
            self::markTestSkipped('The test account holds no order confirmations to work with.');
        }

        return $this->client()->getOrderConfirmation($this->stringField($content[0], 'id', 'voucher list entry'));
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return list<array<string, mixed>>
     */
    protected function nestedList(array $payload, string $key, string $context): array
    {
        self::assertArrayHasKey($key, $payload, \sprintf('%s: Lexware no longer returns "%s".', $context, $key));
        self::assertIsArray($payload[$key], \sprintf('%s: "%s" is no longer a list.', $context, $key));

        $entries = [];
        foreach ($payload[$key] as $entry) {
            self::assertIsArray($entry, \sprintf('%s: an entry of "%s" is no longer an object.', $context, $key));
            $entries[] = $entry;
        }

        return $entries;
    }

    /**
     * @param array<string, mixed> $payload
     */
    protected function stringField(array $payload, string $key, string $context): string
    {
        self::assertArrayHasKey($key, $payload, \sprintf('%s: Lexware no longer returns "%s".', $context, $key));
        self::assertIsString($payload[$key], \sprintf('%s: "%s" is no longer a string.', $context, $key));

        return $payload[$key];
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, string> $expectations
     */
    protected function assertFieldTypes(array $payload, array $expectations, string $context): void
    {
        foreach ($expectations as $path => $expectedType) {
            $value = $payload;
            foreach (explode('.', $path) as $segment) {
                self::assertIsArray($value, \sprintf('%s: the path "%s" does not exist any more.', $context, $path));
                self::assertArrayHasKey($segment, $value, \sprintf('%s: Lexware no longer returns "%s".', $context, $path));
                $value = $value[$segment];
            }

            self::assertSame(
                $expectedType,
                get_debug_type($value),
                \sprintf('%s: Lexware changed the type of "%s".', $context, $path)
            );
        }
    }

    private function apiKey(): string
    {
        foreach (['LEXWARE_TEST_API_KEY', 'LEXWARE_API_KEY'] as $name) {
            $value = getenv($name);
            if (\is_string($value) && $value !== '') {
                return $value;
            }
        }

        return '';
    }
}
