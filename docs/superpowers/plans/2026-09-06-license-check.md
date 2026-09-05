# License Check Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the plugin side of a license check that refuses both conversions unless a license has been confirmed, and ship it switched off until the licensing service exists.

**Architecture:** A `LicenseGate` interface with two implementations, bound by one alias in `services.yaml`. The real implementation reads a signed artefact from Kimai's system configuration, verifies it with Ed25519 on every read, and fetches a new one from the licensing service when nothing usable is stored. Both processors consult the gate and throw when refused.

**Tech Stack:** PHP 8.2, Symfony 6.4 components shipped with Kimai 2.65, `ext-sodium` for Ed25519, `ext-openssl` already in use elsewhere, PHPUnit 10. No new Composer dependencies.

**Spec:** `docs/superpowers/specs/2026-09-06-license-check-design.md`

## Global Constraints

- No Composer dependencies of our own. Only what Kimai already ships.
- `declare(strict_types=1)` in every file.
- Classes `final` unless Doctrine needs to derive a proxy. No Doctrine entity is added here.
- Strict comparison, `===` and `!==`, always.
- English everywhere: identifiers, comments, commit messages, documentation.
- No em dash and no double hyphen as punctuation in any project file.
- No abbreviations in identifiers or prose. `configuration`, not `config`.
- Avoid comments. A comment is justified only where a non obvious external constraint needs explaining.
- New classes live in the namespace `KimaiPlugin\KimaiLexwareSyncBundle\Service\License`.
- The plugin must keep working with the license check switched off, which is the state it ships in.
- Every task ends with `just check` passing: code style, static analysis at level 9 with zero findings, and all local suites green.

---

### Task 1: Parse the signed artefact

**Files:**
- Create: `Service/License/LicenseToken.php`
- Create: `Service/License/MalformedLicenseToken.php`
- Test: `Tests/Service/License/LicenseTokenTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: `LicenseToken::parse(string $raw): self` throwing `MalformedLicenseToken`; readonly properties `signedContent: string` and `signature: string`; accessors `isLicensed(): bool`, `customer(): string`, `version(): string`, `issuedAt(): ?\DateTimeImmutable`, `recheckAfter(): ?\DateTimeImmutable`, `validUntil(): ?\DateTimeImmutable`, `reason(): ?string`, `raw(): string`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Tests\Service\License;

use KimaiPlugin\KimaiLexwareSyncBundle\Service\License\LicenseToken;
use KimaiPlugin\KimaiLexwareSyncBundle\Service\License\MalformedLicenseToken;
use PHPUnit\Framework\TestCase;

final class LicenseTokenTest extends TestCase
{
    public function testAWellFormedTokenExposesEveryField(): void
    {
        $token = LicenseToken::parse(self::tokenFor([
            'licensed' => true,
            'customer' => 'Example GmbH',
            'version' => '1.0.0',
            'issued_at' => '2026-09-06T10:00:00+02:00',
            'recheck_after' => '2026-09-13T10:00:00+02:00',
            'valid_until' => '2026-10-06T10:00:00+02:00',
        ]));

        self::assertTrue($token->isLicensed());
        self::assertSame('Example GmbH', $token->customer());
        self::assertSame('1.0.0', $token->version());
        self::assertEquals(new \DateTimeImmutable('2026-09-13T10:00:00+02:00'), $token->recheckAfter());
        self::assertEquals(new \DateTimeImmutable('2026-10-06T10:00:00+02:00'), $token->validUntil());
        self::assertNull($token->reason());
    }

    public function testARefusalCarriesItsReason(): void
    {
        $token = LicenseToken::parse(self::tokenFor([
            'licensed' => false,
            'customer' => 'Example GmbH',
            'version' => '1.0.0',
            'reason' => 'expired',
        ]));

        self::assertFalse($token->isLicensed());
        self::assertSame('expired', $token->reason());
    }

    public function testTheSignedContentIsTheEncodedBodyAndNotTheWholeToken(): void
    {
        $raw = self::tokenFor(['licensed' => true, 'version' => '1.0.0']);
        $token = LicenseToken::parse($raw);

        self::assertSame(explode('.', $raw)[0], $token->signedContent);
        self::assertSame($raw, $token->raw());
    }

    /**
     * @return array<int, array{0: string}>
     */
    public static function unusableTokens(): array
    {
        return [
            'empty' => [''],
            'one part' => ['abc'],
            'three parts' => ['a.b.c'],
            'body is not base64url' => ['!!!.' . self::encode('signature')],
            'body is not json' => [self::encode('not json') . '.' . self::encode('signature')],
            'body is a json list' => [self::encode('[1,2,3]') . '.' . self::encode('signature')],
            'signature is not base64url' => [self::encode('{"licensed":true}') . '.!!!'],
        ];
    }

    /**
     * @dataProvider unusableTokens
     */
    public function testAnUnusableTokenIsRefusedWithAnException(string $raw): void
    {
        $this->expectException(MalformedLicenseToken::class);

        LicenseToken::parse($raw);
    }

    public function testAnUnparseableDateIsNullRatherThanAnException(): void
    {
        $token = LicenseToken::parse(self::tokenFor([
            'licensed' => true,
            'version' => '1.0.0',
            'valid_until' => 'whenever',
        ]));

        self::assertNull($token->validUntil());
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function tokenFor(array $body): string
    {
        return self::encode(json_encode($body, JSON_THROW_ON_ERROR)) . '.' . self::encode('a signature');
    }

    private static function encode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --testsuite unit --filter LicenseTokenTest`
Expected: FAIL, class `LicenseToken` not found.

- [ ] **Step 3: Write minimal implementation**

```php
<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Service\License;

final class MalformedLicenseToken extends \RuntimeException
{
}
```

```php
<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Service\License;

final class LicenseToken
{
    /**
     * @param array<string, mixed> $body
     */
    private function __construct(
        private readonly string $raw,
        public readonly string $signedContent,
        public readonly string $signature,
        private readonly array $body,
    ) {
    }

    public static function parse(string $raw): self
    {
        $parts = explode('.', $raw);
        if (\count($parts) !== 2) {
            throw new MalformedLicenseToken('A license token consists of exactly two parts separated by a dot.');
        }

        $decodedBody = self::decode($parts[0]);
        $signature = self::decode($parts[1]);

        $body = json_decode($decodedBody, true);
        if (!\is_array($body) || array_is_list($body)) {
            throw new MalformedLicenseToken('The body of a license token must be a JSON object.');
        }

        $fields = [];
        foreach ($body as $name => $value) {
            $fields[(string) $name] = $value;
        }

        return new self($raw, $parts[0], $signature, $fields);
    }

    public function raw(): string
    {
        return $this->raw;
    }

    public function isLicensed(): bool
    {
        return ($this->body['licensed'] ?? false) === true;
    }

    public function customer(): string
    {
        $value = $this->body['customer'] ?? null;

        return \is_string($value) ? $value : '';
    }

    public function version(): string
    {
        $value = $this->body['version'] ?? null;

        return \is_string($value) ? $value : '';
    }

    public function issuedAt(): ?\DateTimeImmutable
    {
        return $this->dateTime('issued_at');
    }

    public function recheckAfter(): ?\DateTimeImmutable
    {
        return $this->dateTime('recheck_after');
    }

    public function validUntil(): ?\DateTimeImmutable
    {
        return $this->dateTime('valid_until');
    }

    public function reason(): ?string
    {
        $value = $this->body['reason'] ?? null;

        return \is_string($value) && $value !== '' ? $value : null;
    }

    private function dateTime(string $field): ?\DateTimeImmutable
    {
        $value = $this->body[$field] ?? null;
        if (!\is_string($value) || $value === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }

    private static function decode(string $value): string
    {
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);
        if ($decoded === false) {
            throw new MalformedLicenseToken('A part of the license token is not valid base64url.');
        }

        return $decoded;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit --testsuite unit --filter LicenseTokenTest`
Expected: PASS, 10 tests.

- [ ] **Step 5: Run the full verification loop**

Run: `just check`
Expected: code style clean, PHPStan reports no errors, all suites green.

- [ ] **Step 6: Commit**

```bash
git add Service/License Tests/Service/License
git commit -m "Parse the signed license artefact"
```

---

### Task 2: Verify the signature

**Files:**
- Create: `Service/License/LicenseSignatureVerifier.php`
- Test: `Tests/Service/License/LicenseSignatureVerifierTest.php`

**Interfaces:**
- Consumes: `LicenseToken` from Task 1.
- Produces: `LicenseSignatureVerifier::__construct(array $base64PublicKeys)` taking `list<string>`, and `isSignatureValid(LicenseToken $token): bool`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Tests\Service\License;

use KimaiPlugin\KimaiLexwareSyncBundle\Service\License\LicenseSignatureVerifier;
use KimaiPlugin\KimaiLexwareSyncBundle\Service\License\LicenseToken;
use PHPUnit\Framework\TestCase;

final class LicenseSignatureVerifierTest extends TestCase
{
    private string $secretKey;
    private string $publicKey;

    protected function setUp(): void
    {
        $pair = sodium_crypto_sign_keypair();
        $this->secretKey = sodium_crypto_sign_secretkey($pair);
        $this->publicKey = sodium_crypto_sign_publickey($pair);
    }

    public function testAGenuineSignaturePasses(): void
    {
        $verifier = new LicenseSignatureVerifier([base64_encode($this->publicKey)]);

        self::assertTrue($verifier->isSignatureValid($this->sign(['licensed' => true], $this->secretKey)));
    }

    public function testAModifiedBodyFails(): void
    {
        $verifier = new LicenseSignatureVerifier([base64_encode($this->publicKey)]);
        $token = $this->sign(['licensed' => false], $this->secretKey);

        $tampered = LicenseToken::parse(self::encode('{"licensed":true}') . '.' . explode('.', $token->raw())[1]);

        self::assertFalse($verifier->isSignatureValid($tampered));
    }

    public function testASignatureFromAnotherKeyFails(): void
    {
        $otherPair = sodium_crypto_sign_keypair();
        $verifier = new LicenseSignatureVerifier([base64_encode($this->publicKey)]);

        self::assertFalse($verifier->isSignatureValid(
            $this->sign(['licensed' => true], sodium_crypto_sign_secretkey($otherPair))
        ));
    }

    public function testAnyKeyOnTheAcceptedListIsEnoughSoThatARotationIsPossible(): void
    {
        $retiringPair = sodium_crypto_sign_keypair();
        $verifier = new LicenseSignatureVerifier([
            base64_encode(sodium_crypto_sign_publickey($retiringPair)),
            base64_encode($this->publicKey),
        ]);

        self::assertTrue($verifier->isSignatureValid($this->sign(['licensed' => true], $this->secretKey)));
    }

    public function testAnUnusableEntryOnTheListIsIgnoredRatherThanFatal(): void
    {
        $verifier = new LicenseSignatureVerifier(['not base64 at all', base64_encode('too short'), base64_encode($this->publicKey)]);

        self::assertTrue($verifier->isSignatureValid($this->sign(['licensed' => true], $this->secretKey)));
    }

    public function testWithoutAnyUsableKeyNothingIsValid(): void
    {
        $verifier = new LicenseSignatureVerifier([]);

        self::assertFalse($verifier->isSignatureValid($this->sign(['licensed' => true], $this->secretKey)));
    }

    /**
     * @param array<string, mixed> $body
     */
    private function sign(array $body, string $secretKey): LicenseToken
    {
        $encodedBody = self::encode(json_encode($body, JSON_THROW_ON_ERROR));
        $signature = sodium_crypto_sign_detached($encodedBody, $secretKey);

        return LicenseToken::parse($encodedBody . '.' . self::encode($signature));
    }

    private static function encode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --testsuite unit --filter LicenseSignatureVerifierTest`
Expected: FAIL, class `LicenseSignatureVerifier` not found.

- [ ] **Step 3: Write minimal implementation**

```php
<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Service\License;

final class LicenseSignatureVerifier
{
    /**
     * @var list<string>
     */
    private readonly array $publicKeys;

    /**
     * @param list<string> $base64PublicKeys
     */
    public function __construct(array $base64PublicKeys)
    {
        $keys = [];
        foreach ($base64PublicKeys as $encoded) {
            $key = base64_decode($encoded, true);
            if ($key !== false && \strlen($key) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
                $keys[] = $key;
            }
        }

        $this->publicKeys = $keys;
    }

    public function isSignatureValid(LicenseToken $token): bool
    {
        if (\strlen($token->signature) !== SODIUM_CRYPTO_SIGN_BYTES) {
            return false;
        }

        foreach ($this->publicKeys as $publicKey) {
            if (sodium_crypto_sign_verify_detached($token->signature, $token->signedContent, $publicKey)) {
                return true;
            }
        }

        return false;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit --testsuite unit --filter LicenseSignatureVerifierTest`
Expected: PASS, 6 tests.

- [ ] **Step 5: Run the full verification loop**

Run: `just check`

- [ ] **Step 6: Commit**

```bash
git add Service/License Tests/Service/License
git commit -m "Verify a license artefact against a list of accepted keys"
```

---

### Task 3: Turn a token into a verdict

**Files:**
- Create: `Service/License/LicenseState.php`
- Create: `Service/License/LicenseVerdict.php`
- Create: `Service/License/LicenseEvaluator.php`
- Test: `Tests/Service/License/LicenseEvaluatorTest.php`

**Interfaces:**
- Consumes: `LicenseToken`, `LicenseSignatureVerifier`.
- Produces: enum `LicenseState` with cases `Licensed`, `NoKeyConfigured`, `Rejected`, `Unreachable`, `VersionNotCovered`; `LicenseVerdict::licensed(string $customer, ?\DateTimeImmutable $confirmedAt): self`, `LicenseVerdict::refused(LicenseState $state, ?string $reason = null, string $customer = '', ?\DateTimeImmutable $confirmedAt = null): self`, readonly properties `state`, `customer`, `reason`, `confirmedAt`, and `allowsConversion(): bool`; `LicenseEvaluator::usableToken(?string $rawToken, string $installedVersion, \DateTimeImmutable $now): ?LicenseToken` and `LicenseEvaluator::verdictFor(LicenseToken $token): LicenseVerdict`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Tests\Service\License;

use KimaiPlugin\KimaiLexwareSyncBundle\Service\License\LicenseEvaluator;
use KimaiPlugin\KimaiLexwareSyncBundle\Service\License\LicenseSignatureVerifier;
use KimaiPlugin\KimaiLexwareSyncBundle\Service\License\LicenseState;
use PHPUnit\Framework\TestCase;

final class LicenseEvaluatorTest extends TestCase
{
    private string $secretKey;
    private LicenseEvaluator $evaluator;

    protected function setUp(): void
    {
        $pair = sodium_crypto_sign_keypair();
        $this->secretKey = sodium_crypto_sign_secretkey($pair);
        $this->evaluator = new LicenseEvaluator(
            new LicenseSignatureVerifier([base64_encode(sodium_crypto_sign_publickey($pair))])
        );
    }

    public function testAGenuineCurrentTokenIsUsable(): void
    {
        $raw = $this->sign(['licensed' => true, 'version' => '1.0.0', 'valid_until' => '2026-10-06T00:00:00+02:00']);

        self::assertNotNull($this->evaluator->usableToken($raw, '1.0.0', $this->now()));
    }

    public function testNothingStoredIsNotUsable(): void
    {
        self::assertNull($this->evaluator->usableToken(null, '1.0.0', $this->now()));
        self::assertNull($this->evaluator->usableToken('', '1.0.0', $this->now()));
    }

    public function testAMalformedTokenIsNotUsable(): void
    {
        self::assertNull($this->evaluator->usableToken('nonsense', '1.0.0', $this->now()));
    }

    public function testATokenWithABrokenSignatureIsNotUsable(): void
    {
        $raw = $this->sign(['licensed' => true, 'version' => '1.0.0']);
        $tampered = self::encode('{"licensed":true,"version":"1.0.0"}') . '.' . explode('.', $raw)[1];

        self::assertNull($this->evaluator->usableToken($tampered, '1.0.0', $this->now()));
    }

    public function testATokenIssuedForAnotherVersionIsNotUsable(): void
    {
        $raw = $this->sign(['licensed' => true, 'version' => '1.0.0']);

        self::assertNull($this->evaluator->usableToken($raw, '1.1.0', $this->now()));
    }

    public function testATokenPastItsValidityIsNotUsable(): void
    {
        $raw = $this->sign(['licensed' => true, 'version' => '1.0.0', 'valid_until' => '2026-09-01T00:00:00+02:00']);

        self::assertNull($this->evaluator->usableToken($raw, '1.0.0', $this->now()));
    }

    public function testATokenWithoutAValidityDateNeverGoesStale(): void
    {
        $raw = $this->sign(['licensed' => true, 'version' => '1.0.0']);

        self::assertNotNull($this->evaluator->usableToken($raw, '1.0.0', $this->now()));
    }

    public function testAnApprovalBecomesALicensedVerdict(): void
    {
        $raw = $this->sign([
            'licensed' => true,
            'version' => '1.0.0',
            'customer' => 'Example GmbH',
            'issued_at' => '2026-09-06T10:00:00+02:00',
        ]);
        $token = $this->evaluator->usableToken($raw, '1.0.0', $this->now());
        self::assertNotNull($token);

        $verdict = $this->evaluator->verdictFor($token);

        self::assertSame(LicenseState::Licensed, $verdict->state);
        self::assertTrue($verdict->allowsConversion());
        self::assertSame('Example GmbH', $verdict->customer);
        self::assertEquals(new \DateTimeImmutable('2026-09-06T10:00:00+02:00'), $verdict->confirmedAt);
    }

    /**
     * @return array<string, array{0: string, 1: LicenseState}>
     */
    public static function refusals(): array
    {
        return [
            'expired' => ['expired', LicenseState::Rejected],
            'revoked' => ['revoked', LicenseState::Rejected],
            'unknown key' => ['unknown_key', LicenseState::Rejected],
            'version not covered' => ['version_not_covered', LicenseState::VersionNotCovered],
        ];
    }

    /**
     * @dataProvider refusals
     */
    public function testARefusalKeepsItsReasonAndMapsToAState(string $reason, LicenseState $expected): void
    {
        $raw = $this->sign(['licensed' => false, 'version' => '1.0.0', 'reason' => $reason]);
        $token = $this->evaluator->usableToken($raw, '1.0.0', $this->now());
        self::assertNotNull($token);

        $verdict = $this->evaluator->verdictFor($token);

        self::assertSame($expected, $verdict->state);
        self::assertSame($reason, $verdict->reason);
        self::assertFalse($verdict->allowsConversion());
    }

    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-09-06T12:00:00+02:00');
    }

    /**
     * @param array<string, mixed> $body
     */
    private function sign(array $body): string
    {
        $encodedBody = self::encode(json_encode($body, JSON_THROW_ON_ERROR));

        return $encodedBody . '.' . self::encode(sodium_crypto_sign_detached($encodedBody, $this->secretKey));
    }

    private static function encode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --testsuite unit --filter LicenseEvaluatorTest`
Expected: FAIL, class `LicenseEvaluator` not found.

- [ ] **Step 3: Write minimal implementation**

```php
<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Service\License;

enum LicenseState
{
    case Licensed;
    case NoKeyConfigured;
    case Rejected;
    case Unreachable;
    case VersionNotCovered;
}
```

```php
<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Service\License;

final class LicenseVerdict
{
    private function __construct(
        public readonly LicenseState $state,
        public readonly string $customer,
        public readonly ?string $reason,
        public readonly ?\DateTimeImmutable $confirmedAt,
    ) {
    }

    public static function licensed(string $customer, ?\DateTimeImmutable $confirmedAt): self
    {
        return new self(LicenseState::Licensed, $customer, null, $confirmedAt);
    }

    public static function refused(
        LicenseState $state,
        ?string $reason = null,
        string $customer = '',
        ?\DateTimeImmutable $confirmedAt = null,
    ): self {
        return new self($state, $customer, $reason, $confirmedAt);
    }

    public function allowsConversion(): bool
    {
        return $this->state === LicenseState::Licensed;
    }
}
```

```php
<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Service\License;

final class LicenseEvaluator
{
    public function __construct(private readonly LicenseSignatureVerifier $verifier)
    {
    }

    public function usableToken(?string $rawToken, string $installedVersion, \DateTimeImmutable $now): ?LicenseToken
    {
        if ($rawToken === null || $rawToken === '') {
            return null;
        }

        try {
            $token = LicenseToken::parse($rawToken);
        } catch (MalformedLicenseToken) {
            return null;
        }

        if (!$this->verifier->isSignatureValid($token)) {
            return null;
        }

        if ($token->version() !== $installedVersion) {
            return null;
        }

        $validUntil = $token->validUntil();

        return $validUntil !== null && $validUntil < $now ? null : $token;
    }

    public function verdictFor(LicenseToken $token): LicenseVerdict
    {
        if ($token->isLicensed()) {
            return LicenseVerdict::licensed($token->customer(), $token->issuedAt());
        }

        $reason = $token->reason();
        $state = $reason === 'version_not_covered' ? LicenseState::VersionNotCovered : LicenseState::Rejected;

        return LicenseVerdict::refused($state, $reason, $token->customer(), $token->issuedAt());
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit --testsuite unit --filter LicenseEvaluatorTest`
Expected: PASS, 12 tests.

- [ ] **Step 5: Run the full verification loop**

Run: `just check`

- [ ] **Step 6: Commit**

```bash
git add Service/License Tests/Service/License
git commit -m "Derive a license verdict from a verified artefact"
```

---

### Task 4: Store the artefact against the key it was fetched for

**Files:**
- Create: `Service/License/LicenseStore.php`
- Test: `Tests/Functional/License/LicenseStoreTest.php`

**Interfaces:**
- Consumes: `App\Repository\ConfigurationRepository`, `App\Configuration\SystemConfiguration`.
- Produces: `LicenseStore::storedToken(string $licenseKey): ?string`, `LicenseStore::store(string $licenseKey, string $rawToken): void`, `LicenseStore::forget(): void`. The configuration key written is `lexware_sync.license_token`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Tests\Functional\License;

use KimaiPlugin\KimaiLexwareSyncBundle\Service\License\LicenseStore;
use KimaiPlugin\KimaiLexwareSyncBundle\Tests\Support\FunctionalTestCase;

final class LicenseStoreTest extends FunctionalTestCase
{
    public function testAStoredArtefactComesBackForTheSameKey(): void
    {
        $store = $this->service(LicenseStore::class);
        $store->store('key-one', 'body.signature');

        self::assertSame('body.signature', $store->storedToken('key-one'));
    }

    public function testAStoredArtefactIsIgnoredAfterTheKeyChanged(): void
    {
        $store = $this->service(LicenseStore::class);
        $store->store('key-one', 'body.signature');

        self::assertNull($store->storedToken('key-two'));
    }

    public function testNothingStoredYieldsNull(): void
    {
        self::assertNull($this->service(LicenseStore::class)->storedToken('key-one'));
    }

    public function testStoringTwiceReplacesRatherThanAccumulates(): void
    {
        $store = $this->service(LicenseStore::class);
        $store->store('key-one', 'first.signature');
        $store->store('key-one', 'second.signature');

        self::assertSame('second.signature', $store->storedToken('key-one'));
    }

    public function testForgettingRemovesTheArtefact(): void
    {
        $store = $this->service(LicenseStore::class);
        $store->store('key-one', 'body.signature');
        $store->forget();

        self::assertNull($store->storedToken('key-one'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --testsuite functional --filter LicenseStoreTest`
Expected: FAIL, class `LicenseStore` not found.

- [ ] **Step 3: Write minimal implementation**

```php
<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Service\License;

use App\Configuration\SystemConfiguration;
use App\Entity\Configuration;
use App\Repository\ConfigurationRepository;

final class LicenseStore
{
    private const CONFIGURATION_KEY = 'lexware_sync.license_token';

    public function __construct(
        private readonly ConfigurationRepository $repository,
        private readonly SystemConfiguration $configuration,
    ) {
    }

    public function storedToken(string $licenseKey): ?string
    {
        $stored = $this->repository->findOneBy(['name' => self::CONFIGURATION_KEY]);
        $value = $stored?->getValue();
        if (!\is_string($value) || $value === '') {
            return null;
        }

        $separator = strpos($value, ':');
        if ($separator === false) {
            return null;
        }

        $fingerprint = substr($value, 0, $separator);

        return hash_equals($fingerprint, $this->fingerprint($licenseKey)) ? substr($value, $separator + 1) : null;
    }

    public function store(string $licenseKey, string $rawToken): void
    {
        $stored = $this->repository->findOneBy(['name' => self::CONFIGURATION_KEY]);
        if ($stored === null) {
            $stored = new Configuration();
            $stored->setName(self::CONFIGURATION_KEY);
        }

        $stored->setValue($this->fingerprint($licenseKey) . ':' . $rawToken);
        $this->repository->saveConfiguration($stored);
        $this->configuration->set(self::CONFIGURATION_KEY, $stored->getValue());
    }

    public function forget(): void
    {
        $stored = $this->repository->findOneBy(['name' => self::CONFIGURATION_KEY]);
        if ($stored === null) {
            return;
        }

        $stored->setValue('');
        $this->repository->saveConfiguration($stored);
        $this->configuration->set(self::CONFIGURATION_KEY, '');
    }

    private function fingerprint(string $licenseKey): string
    {
        return hash('sha256', $licenseKey);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit --testsuite functional --filter LicenseStoreTest`
Expected: PASS, 5 tests.

- [ ] **Step 5: Run the full verification loop**

Run: `just check`

- [ ] **Step 6: Commit**

```bash
git add Service/License Tests/Functional/License
git commit -m "Store the license artefact against the key it was fetched for"
```

---

### Task 5: Ask the licensing service

**Files:**
- Create: `Service/License/LicenseClient.php`
- Create: `Service/License/LicenseServiceUnavailable.php`
- Create: `Tests/Support/RecordingHttpClient.php`
- Modify: `Tests/Support/FakeLexwareHttpClient.php`
- Create: `Tests/Support/FakeLicenseHttpClient.php`
- Modify: `Tests/config/test_environment.yaml`
- Test: `Tests/Service/License/LicenseClientTest.php`

**Interfaces:**
- Consumes: `Symfony\Contracts\HttpClient\HttpClientInterface`.
- Produces: `LicenseClient::__construct(HttpClientInterface $httpClient, string $serviceUrl)` and `LicenseClient::fetch(string $licenseKey, string $version): string` returning the raw artefact and throwing `LicenseServiceUnavailable`. `RecordingHttpClient` holds the stubbing and recording behaviour that `FakeLexwareHttpClient` had, with `willRespondWith()`, `willRespondWithBody()`, `recordedRequests()`, `requestCount()` and `hasUnusedStubs()` unchanged in signature.

- [ ] **Step 1: Move the fake's behaviour into a reusable class**

`Tests/Support/RecordingHttpClient.php` receives the entire body of the current `FakeLexwareHttpClient`, with the class declared `abstract` and the class name changed. `FakeLexwareHttpClient` becomes:

```php
<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Tests\Support;

final class FakeLexwareHttpClient extends RecordingHttpClient
{
}
```

and `FakeLicenseHttpClient` is the same:

```php
<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Tests\Support;

final class FakeLicenseHttpClient extends RecordingHttpClient
{
}
```

Rename `UnexpectedLexwareRequest` to `UnexpectedHttpRequest` in the same move, since it is no longer specific to Lexware.

Run: `vendor/bin/phpunit --testsuite unit,functional`
Expected: PASS, unchanged count. This step must not change behaviour.

- [ ] **Step 2: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Tests\Service\License;

use KimaiPlugin\KimaiLexwareSyncBundle\Service\License\LicenseClient;
use KimaiPlugin\KimaiLexwareSyncBundle\Service\License\LicenseServiceUnavailable;
use KimaiPlugin\KimaiLexwareSyncBundle\Tests\Support\FakeLicenseHttpClient;
use PHPUnit\Framework\TestCase;

final class LicenseClientTest extends TestCase
{
    private const SERVICE_URL = 'https://licensing.example.com/v1/check';

    public function testTheArtefactIsReturnedAndOnlyTwoFieldsAreSent(): void
    {
        $httpClient = new FakeLicenseHttpClient();
        $httpClient->willRespondWith('POST', '/v1/check', ['license' => 'body.signature']);

        $client = new LicenseClient($httpClient, self::SERVICE_URL);

        self::assertSame('body.signature', $client->fetch('key-one', '1.0.0'));

        $request = $httpClient->recordedRequests()[0];
        self::assertSame(['key' => 'key-one', 'version' => '1.0.0'], $request->decodedBody());
    }

    public function testAResponseWithoutAnArtefactCountsAsUnavailable(): void
    {
        $httpClient = new FakeLicenseHttpClient();
        $httpClient->willRespondWith('POST', '/v1/check', ['unexpected' => true]);

        $this->expectException(LicenseServiceUnavailable::class);

        (new LicenseClient($httpClient, self::SERVICE_URL))->fetch('key-one', '1.0.0');
    }

    public function testAnErrorStatusCountsAsUnavailable(): void
    {
        $httpClient = new FakeLicenseHttpClient();
        $httpClient->willRespondWith('POST', '/v1/check', ['message' => 'boom'], 500);

        $this->expectException(LicenseServiceUnavailable::class);

        (new LicenseClient($httpClient, self::SERVICE_URL))->fetch('key-one', '1.0.0');
    }

    public function testAResponseThatIsNotJsonCountsAsUnavailable(): void
    {
        $httpClient = new FakeLicenseHttpClient();
        $httpClient->willRespondWithBody('POST', '/v1/check', 'not json at all');

        $this->expectException(LicenseServiceUnavailable::class);

        (new LicenseClient($httpClient, self::SERVICE_URL))->fetch('key-one', '1.0.0');
    }
}
```

- [ ] **Step 3: Run test to verify it fails**

Run: `vendor/bin/phpunit --testsuite unit --filter LicenseClientTest`
Expected: FAIL, class `LicenseClient` not found.

- [ ] **Step 4: Write minimal implementation**

```php
<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Service\License;

final class LicenseServiceUnavailable extends \RuntimeException
{
}
```

```php
<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Service\License;

use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class LicenseClient
{
    private const TIMEOUT_SECONDS = 5.0;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $serviceUrl,
    ) {
    }

    public function fetch(string $licenseKey, string $version): string
    {
        try {
            $response = $this->httpClient->request('POST', $this->serviceUrl, [
                'json' => ['key' => $licenseKey, 'version' => $version],
                'timeout' => self::TIMEOUT_SECONDS,
            ]);

            if ($response->getStatusCode() !== 200) {
                throw new LicenseServiceUnavailable('The licensing service answered with status ' . $response->getStatusCode() . '.');
            }

            $decoded = json_decode($response->getContent(false), true);
        } catch (ExceptionInterface $exception) {
            throw new LicenseServiceUnavailable('The licensing service could not be reached: ' . $exception->getMessage(), 0, $exception);
        }

        $artefact = \is_array($decoded) ? ($decoded['license'] ?? null) : null;
        if (!\is_string($artefact) || $artefact === '') {
            throw new LicenseServiceUnavailable('The licensing service answered without a license artefact.');
        }

        return $artefact;
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `vendor/bin/phpunit --testsuite unit --filter LicenseClientTest`
Expected: PASS, 4 tests.

- [ ] **Step 6: Run the full verification loop**

Run: `just check`

- [ ] **Step 7: Commit**

```bash
git add Service/License Tests/Support Tests/Service/License Tests/config
git commit -m "Ask the licensing service for an artefact"
```

---

### Task 6: The gate, its two implementations and the switch

**Files:**
- Create: `Service/License/LicenseGate.php`
- Create: `Service/License/AlwaysLicensedGate.php`
- Create: `Service/License/ServiceBackedLicenseGate.php`
- Create: `Service/License/PluginVersion.php`
- Modify: `Resources/config/services.yaml`
- Modify: `Tests/TestKernel.php`
- Create: `Tests/config/license_enforcement.yaml`
- Create: `Tests/ShippedWiringKernel.php`
- Test: `Tests/Functional/License/ServiceBackedLicenseGateTest.php`
- Test: `Tests/Functional/License/ShippedLicenseWiringTest.php`

**Interfaces:**
- Consumes: everything from Tasks 3, 4 and 5.
- Produces: `interface LicenseGate { public function verdict(): LicenseVerdict; }`; `AlwaysLicensedGate` and `ServiceBackedLicenseGate` implementing it; `PluginVersion::current(): string`. `TestKernel::testConfigurationFiles(): list<string>` becomes a protected method so a subclass can leave a file out.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Tests\Functional\License;

use KimaiPlugin\KimaiLexwareSyncBundle\Service\License\LicenseState;
use KimaiPlugin\KimaiLexwareSyncBundle\Service\License\LicenseStore;
use KimaiPlugin\KimaiLexwareSyncBundle\Service\License\ServiceBackedLicenseGate;
use KimaiPlugin\KimaiLexwareSyncBundle\Tests\Support\FunctionalTestCase;

final class ServiceBackedLicenseGateTest extends FunctionalTestCase
{
    public function testWithoutAKeyNothingIsAskedAndNothingIsAllowed(): void
    {
        $this->configure('lexware_sync.license_key', '');

        $verdict = $this->service(ServiceBackedLicenseGate::class)->verdict();

        self::assertSame(LicenseState::NoKeyConfigured, $verdict->state);
        self::assertSame(0, $this->licenseService()->requestCount());
    }

    public function testAStoredApprovalIsUsedWithoutAskingAgain(): void
    {
        $this->configure('lexware_sync.license_key', 'key-one');
        $this->service(LicenseStore::class)->store('key-one', $this->approval());

        $verdict = $this->service(ServiceBackedLicenseGate::class)->verdict();

        self::assertTrue($verdict->allowsConversion());
        self::assertSame('Example GmbH', $verdict->customer);
        self::assertSame(0, $this->licenseService()->requestCount());
    }

    public function testWithNothingStoredTheServiceIsAskedOnceAndTheAnswerIsKept(): void
    {
        $this->configure('lexware_sync.license_key', 'key-one');
        $this->licenseService()->willRespondWith('POST', '/v1/check', ['license' => $this->approval()]);

        self::assertTrue($this->service(ServiceBackedLicenseGate::class)->verdict()->allowsConversion());
        self::assertSame(1, $this->licenseService()->requestCount());
        self::assertNotNull($this->service(LicenseStore::class)->storedToken('key-one'));
    }

    public function testAnUnreachableServiceIsReportedAndNotAskedAgainImmediately(): void
    {
        $this->configure('lexware_sync.license_key', 'key-one');
        $this->licenseService()->willRespondWith('POST', '/v1/check', ['message' => 'down'], 500);

        $gate = $this->service(ServiceBackedLicenseGate::class);

        self::assertSame(LicenseState::Unreachable, $gate->verdict()->state);
        self::assertSame(LicenseState::Unreachable, $gate->verdict()->state);
        self::assertSame(1, $this->licenseService()->requestCount());
    }

    public function testARefusalIsKeptSoThatClickingAgainDoesNotAskAgain(): void
    {
        $this->configure('lexware_sync.license_key', 'key-one');
        $this->licenseService()->willRespondWith('POST', '/v1/check', ['license' => $this->refusal('expired')]);

        $gate = $this->service(ServiceBackedLicenseGate::class);

        self::assertSame(LicenseState::Rejected, $gate->verdict()->state);
        self::assertSame('expired', $gate->verdict()->reason);
        self::assertSame(1, $this->licenseService()->requestCount());
    }

    use SignsLicenseArtefacts;
}
```

The signing helpers live in a trait, because Task 8 needs exactly the same ones. Create `Tests/Support/SignsLicenseArtefacts.php`:

```php
<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Tests\Support;

use KimaiPlugin\KimaiLexwareSyncBundle\Service\License\PluginVersion;

/**
 * The key pair below exists only for tests and is committed on purpose. Its public half is
 * repeated in Tests/config/license_enforcement.yaml, because a YAML file cannot read a PHP
 * constant, and the two must match.
 */
trait SignsLicenseArtefacts
{
    private const TEST_SECRET_KEY = 'REPLACE WITH THE SECRET KEY PRINTED IN STEP 4';
    private const TEST_PUBLIC_KEY = 'REPLACE WITH THE PUBLIC KEY PRINTED IN STEP 4';

    protected function licenseService(): FakeLicenseHttpClient
    {
        return $this->service(FakeLicenseHttpClient::class);
    }

    protected function approval(): string
    {
        return $this->signArtefact(['licensed' => true, 'customer' => 'Example GmbH', 'version' => $this->installedVersion()]);
    }

    protected function refusal(string $reason): string
    {
        return $this->signArtefact([
            'licensed' => false,
            'customer' => 'Example GmbH',
            'version' => $this->installedVersion(),
            'reason' => $reason,
        ]);
    }

    protected function approvalRecheckedAfter(string $recheckAfter): string
    {
        return $this->signArtefact([
            'licensed' => true,
            'customer' => 'Example GmbH',
            'version' => $this->installedVersion(),
            'recheck_after' => $recheckAfter,
        ]);
    }

    protected function installedVersion(): string
    {
        return $this->service(PluginVersion::class)->current();
    }

    /**
     * @param array<string, mixed> $body
     */
    protected function signArtefact(array $body): string
    {
        $secretKey = base64_decode(self::TEST_SECRET_KEY, true);
        if ($secretKey === false) {
            throw new \RuntimeException('The test secret key is not valid base64.');
        }

        $encoded = self::encodePart(json_encode($body, JSON_THROW_ON_ERROR));

        return $encoded . '.' . self::encodePart(sodium_crypto_sign_detached($encoded, $secretKey));
    }

    private static function encodePart(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
```

```php
<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Tests\Functional\License;

use KimaiPlugin\KimaiLexwareSyncBundle\Service\License\AlwaysLicensedGate;
use KimaiPlugin\KimaiLexwareSyncBundle\Service\License\LicenseGate;
use KimaiPlugin\KimaiLexwareSyncBundle\Tests\ShippedWiringKernel;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ShippedLicenseWiringTest extends KernelTestCase
{
    protected static function getKernelClass(): string
    {
        return ShippedWiringKernel::class;
    }

    public function testThePluginShipsWithTheCheckSwitchedOff(): void
    {
        self::bootKernel();

        self::assertInstanceOf(AlwaysLicensedGate::class, self::getContainer()->get(LicenseGate::class));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --testsuite functional --filter License`
Expected: FAIL, `LicenseGate` not found.

- [ ] **Step 3: Write the implementation**

```php
<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Service\License;

interface LicenseGate
{
    public function verdict(): LicenseVerdict;
}
```

```php
<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Service\License;

final class AlwaysLicensedGate implements LicenseGate
{
    public function verdict(): LicenseVerdict
    {
        return LicenseVerdict::licensed('', null);
    }
}
```

```php
<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Service\License;

use App\Plugin\PluginMetadata;

final class PluginVersion
{
    public function current(): string
    {
        return PluginMetadata::createFromPath(\dirname(__DIR__, 2))->getVersion();
    }
}
```

```php
<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Service\License;

use KimaiPlugin\KimaiLexwareSyncBundle\Configuration\LexwareSyncConfiguration;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

final class ServiceBackedLicenseGate implements LicenseGate
{
    private const FAILURE_MEMORY_KEY = 'lexware_sync.license_fetch_failed';
    private const FAILURE_MEMORY_SECONDS = 900;

    public function __construct(
        private readonly LexwareSyncConfiguration $configuration,
        private readonly LicenseStore $store,
        private readonly LicenseEvaluator $evaluator,
        private readonly LicenseClient $client,
        private readonly PluginVersion $pluginVersion,
        private readonly CacheInterface $cache,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function verdict(): LicenseVerdict
    {
        $licenseKey = $this->configuration->getLicenseKey();
        if ($licenseKey === '') {
            return LicenseVerdict::refused(LicenseState::NoKeyConfigured);
        }

        $version = $this->pluginVersion->current();
        $now = new \DateTimeImmutable();

        $token = $this->evaluator->usableToken($this->store->storedToken($licenseKey), $version, $now);
        if ($token !== null) {
            return $this->evaluator->verdictFor($token);
        }

        if ($this->fetchFailedRecently()) {
            return LicenseVerdict::refused(LicenseState::Unreachable);
        }

        try {
            $raw = $this->client->fetch($licenseKey, $version);
        } catch (LicenseServiceUnavailable $exception) {
            $this->logger->warning('The licensing service could not be reached: ' . $exception->getMessage());
            $this->rememberFailure();

            return LicenseVerdict::refused(LicenseState::Unreachable);
        }

        $fresh = $this->evaluator->usableToken($raw, $version, $now);
        if ($fresh === null) {
            $this->logger->error('The licensing service answered with an artefact this installation cannot use.');
            $this->rememberFailure();

            return LicenseVerdict::refused(LicenseState::Unreachable);
        }

        $this->store->store($licenseKey, $raw);

        return $this->evaluator->verdictFor($fresh);
    }

    private function fetchFailedRecently(): bool
    {
        return $this->cache->get(self::FAILURE_MEMORY_KEY, static function (ItemInterface $item): bool {
            // Nothing is remembered, so nothing failed. The entry expires straight away rather
            // than leaving a permanent negative answer behind.
            $item->expiresAfter(1);

            return false;
        }) === true;
    }

    private function rememberFailure(): void
    {
        $this->cache->delete(self::FAILURE_MEMORY_KEY);
        $this->cache->get(self::FAILURE_MEMORY_KEY, static function (ItemInterface $item): bool {
            $item->expiresAfter(self::FAILURE_MEMORY_SECONDS);

            return true;
        });
    }
}
```

- [ ] **Step 4: Wire it**

Append to `Resources/config/services.yaml`:

```yaml
    # This alias is the single place where the license check is switched on and off. It ships
    # pointing at AlwaysLicensedGate because the licensing service does not exist yet. Point it
    # at ServiceBackedLicenseGate to switch the check on.
    KimaiPlugin\KimaiLexwareSyncBundle\Service\License\LicenseGate:
        alias: KimaiPlugin\KimaiLexwareSyncBundle\Service\License\AlwaysLicensedGate
        public: true

    KimaiPlugin\KimaiLexwareSyncBundle\Service\License\LicenseClient:
        arguments:
            $serviceUrl: '%lexware_sync.license_service_url%'

    KimaiPlugin\KimaiLexwareSyncBundle\Service\License\LicenseSignatureVerifier:
        arguments:
            $base64PublicKeys: '%lexware_sync.license_public_keys%'
```

and at the top of the same file:

```yaml
parameters:
    lexware_sync.license_service_url: 'https://licensing.navilalabs.com/v1/check'
    lexware_sync.license_public_keys: []
```

Create `Tests/config/license_enforcement.yaml`:

```yaml
services:
    _defaults:
        autowire: true
        autoconfigure: true
        public: false

    KimaiPlugin\KimaiLexwareSyncBundle\Tests\Support\FakeLicenseHttpClient: ~

    KimaiPlugin\KimaiLexwareSyncBundle\Service\License\LicenseClient:
        arguments:
            $httpClient: '@KimaiPlugin\KimaiLexwareSyncBundle\Tests\Support\FakeLicenseHttpClient'
            $serviceUrl: 'https://licensing.example.com/v1/check'

    KimaiPlugin\KimaiLexwareSyncBundle\Service\License\LicenseGate:
        alias: KimaiPlugin\KimaiLexwareSyncBundle\Service\License\ServiceBackedLicenseGate
        public: true
```

Generate the test key pair once, now, and print both halves:

```bash
php -r '$p = sodium_crypto_sign_keypair(); echo "public: ", base64_encode(sodium_crypto_sign_publickey($p)), PHP_EOL, "secret: ", base64_encode(sodium_crypto_sign_secretkey($p)), PHP_EOL;'
```

Paste the printed public key into the same `Tests/config/license_enforcement.yaml`, and both halves into the trait created in Step 1:

```yaml
parameters:
    lexware_sync.license_public_keys: ['the public key printed above']
```

This pair exists only for tests, is committed on purpose, and has nothing to do with the production key from Task 11. Printing it is harmless: it signs nothing anybody pays for.

In `Tests/TestKernel.php`, replace the single hard coded file with:

```php
    protected function configureContainer(ContainerBuilder $container, LoaderInterface $loader): void
    {
        parent::configureContainer($container, $loader);

        foreach ($this->testConfigurationFiles() as $file) {
            $loader->load($file);
        }
    }

    /**
     * @return list<string>
     */
    protected function testConfigurationFiles(): array
    {
        return [
            __DIR__ . '/config/test_environment.yaml',
            __DIR__ . '/config/license_enforcement.yaml',
        ];
    }
```

Create `Tests/ShippedWiringKernel.php`:

```php
<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Tests;

/**
 * Boots without the license enforcement override, so that a test can assert what a customer
 * installation is actually wired to.
 */
final class ShippedWiringKernel extends TestKernel
{
    public function getCacheDir(): string
    {
        return $this->getProjectDir() . '/var/cache/plugin-test-shipped';
    }

    protected function testConfigurationFiles(): array
    {
        return [__DIR__ . '/config/test_environment.yaml'];
    }
}
```

`TestKernel` must lose its `final` marker for this, which is the concrete reason the rule in CLAUDE.md allows.

- [ ] **Step 5: Run tests to verify they pass**

Run: `vendor/bin/phpunit --testsuite functional --filter License`
Expected: PASS, 6 tests.

- [ ] **Step 6: Run the full verification loop**

Run: `just check`

- [ ] **Step 7: Commit**

```bash
git add Service/License Resources/config/services.yaml Tests
git commit -m "Add the license gate and the single place that switches it on"
```

---

### Task 7: Refuse both conversions without a license

**Files:**
- Create: `Service/License/LicenseRequiredException.php`
- Modify: `Service/OrderConfirmationProcessor.php`
- Modify: `Service/InvoiceProcessor.php`
- Modify: `Controller/TriageController.php`
- Modify: `Controller/InvoiceAssignmentController.php`
- Modify: `Service/OrderConfirmationSynchronizer.php`
- Test: `Tests/Functional/License/ConversionRequiresALicenseTest.php`

**Interfaces:**
- Consumes: `LicenseGate`, `LicenseVerdict`.
- Produces: `LicenseRequiredException::__construct(LicenseVerdict $verdict)` with `verdict(): LicenseVerdict`, thrown by `OrderConfirmationProcessor::convert()` and `InvoiceProcessor::convert()`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Tests\Functional\License;

use KimaiPlugin\KimaiLexwareSyncBundle\Entity\TrackedOrderConfirmation;
use KimaiPlugin\KimaiLexwareSyncBundle\Service\LexwarePayload;
use KimaiPlugin\KimaiLexwareSyncBundle\Service\License\LicenseRequiredException;
use KimaiPlugin\KimaiLexwareSyncBundle\Service\License\LicenseState;
use KimaiPlugin\KimaiLexwareSyncBundle\Service\OrderConfirmationProcessor;
use KimaiPlugin\KimaiLexwareSyncBundle\Tests\Support\FunctionalTestCase;

final class ConversionRequiresALicenseTest extends FunctionalTestCase
{
    public function testAnOrderConfirmationIsNotConvertedWithoutALicense(): void
    {
        $this->configure('lexware_sync.license_key', '');
        $orderConfirmation = $this->trackedOrderConfirmation();

        try {
            $this->service(OrderConfirmationProcessor::class)->convert(
                $orderConfirmation,
                new LexwarePayload($this->payload()),
                null,
                '',
                false
            );
            self::fail('The conversion should have been refused.');
        } catch (LicenseRequiredException $exception) {
            self::assertSame(LicenseState::NoKeyConfigured, $exception->verdict()->state);
        }

        self::assertNull($orderConfirmation->getProject());
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        return [
            'address' => ['contactId' => 'contact-1', 'name' => 'Contact GmbH'],
            'lineItems' => [],
        ];
    }

    private function trackedOrderConfirmation(): TrackedOrderConfirmation
    {
        $orderConfirmation = new TrackedOrderConfirmation('lexware-license-1');
        $orderConfirmation->updateFromLexwarePayload(
            'AB-2026-500',
            'Order confirmation for a license test',
            new \DateTimeImmutable('2026-09-01'),
            'contact-1',
            'Contact GmbH',
            '{}',
            null
        );

        $this->entityManager()->persist($orderConfirmation);
        $this->entityManager()->flush();

        return $orderConfirmation;
    }
}
```

In the same file, the second test:

```php
    public function testAnInvoiceIsNotWrittenBackWithoutALicense(): void
    {
        $this->configure('lexware_sync.license_key', '');

        $orderConfirmation = $this->trackedOrderConfirmation();
        $customer = $this->factory()->createCustomer('Contact GmbH');
        $project = $this->factory()->createProject($customer);
        $orderConfirmation->setCustomer($customer);
        $orderConfirmation->setProject($project);

        $trackedInvoice = new \KimaiPlugin\KimaiLexwareSyncBundle\Entity\TrackedInvoice('lexware-invoice-1', $orderConfirmation);
        $trackedInvoice->updateFromLexwarePayload(
            'RE-2026-500',
            new \DateTimeImmutable('2026-09-01'),
            'Contact GmbH',
            '{"address":{"contactId":"contact-1"},"lineItems":[]}',
            null
        );
        $this->entityManager()->persist($trackedInvoice);
        $this->entityManager()->flush();

        $activity = $this->factory()->createActivity($project);
        $user = $this->factory()->createUser('converter');
        $timesheet = $this->factory()->createTimesheet($project, $activity, $user);

        try {
            $this->service(\KimaiPlugin\KimaiLexwareSyncBundle\Service\InvoiceProcessor::class)->convert(
                $trackedInvoice,
                [$timesheet],
                \KimaiPlugin\KimaiLexwareSyncBundle\Enum\InvoiceLineShape::PerTimesheet,
                false,
                false,
                $user
            );
            self::fail('The invoice should not have been written back.');
        } catch (LicenseRequiredException $exception) {
            self::assertSame(LicenseState::NoKeyConfigured, $exception->verdict()->state);
        }

        self::assertSame(0, $this->lexware()->requestCount(), 'Nothing may reach Lexware while unlicensed.');
    }
```

The license check must therefore be the very first statement of `InvoiceProcessor::convert()`, before `assertCurrencyMatches()`, so that an unlicensed installation is refused for the license rather than for whatever else happens to be wrong.

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --testsuite functional --filter ConversionRequiresALicenseTest`
Expected: FAIL, the conversion succeeds and `self::fail()` is reached.

- [ ] **Step 3: Write minimal implementation**

```php
<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Service\License;

final class LicenseRequiredException extends \RuntimeException
{
    public function __construct(private readonly LicenseVerdict $verdict)
    {
        parent::__construct('This installation has no confirmed license, so nothing is converted.');
    }

    public function verdict(): LicenseVerdict
    {
        return $this->verdict;
    }
}
```

In both processors, add `private readonly LicenseGate $licenseGate` to the constructor and this as the first statement of `convert()`:

```php
        $verdict = $this->licenseGate->verdict();
        if (!$verdict->allowsConversion()) {
            throw new LicenseRequiredException($verdict);
        }
```

In `Controller/TriageController.php` and `Controller/InvoiceAssignmentController.php`, add `LicenseRequiredException` to the catch clause that already handles `CustomerCurrencyMismatchException` and `UnprocessableOrderConfirmationException`, so the transaction is rolled back and a message is shown. In `Service/OrderConfirmationSynchronizer.php`, add it to the catch clause around the automatic conversion, so an unlicensed installation logs the reason once per document instead of failing the whole synchronization run.

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit --testsuite functional --filter ConversionRequiresALicenseTest`
Expected: PASS, 2 tests.

- [ ] **Step 5: Run the full verification loop**

Run: `just check`
Expected: every existing functional test still passes, because the shipped wiring allows everything.

- [ ] **Step 6: Commit**

```bash
git add Service Controller Tests/Functional/License
git commit -m "Refuse both conversions when no license is confirmed"
```

---

### Task 8: The scheduled refresh

**Files:**
- Create: `Command/CheckLicenseCommand.php`
- Test: `Tests/Functional/License/CheckLicenseCommandTest.php`

**Interfaces:**
- Consumes: `LicenseGate` is not used here. The command uses `LexwareSyncConfiguration`, `LicenseStore`, `LicenseEvaluator`, `LicenseClient` and `PluginVersion` directly, because it must refresh even when a usable artefact exists but `recheck_after` has passed, which the gate deliberately does not do.
- Produces: the console command `kimai:lexware-sync:check-license`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Tests\Functional\License;

use KimaiPlugin\KimaiLexwareSyncBundle\Service\License\LicenseStore;
use KimaiPlugin\KimaiLexwareSyncBundle\Tests\Support\FunctionalTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class CheckLicenseCommandTest extends FunctionalTestCase
{
    public function testWithoutAKeyTheCommandSaysSoAndFails(): void
    {
        $this->configure('lexware_sync.license_key', '');

        $tester = $this->run();

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('No license key', $tester->getDisplay());
    }

    public function testAnApprovalIsFetchedAndStored(): void
    {
        $this->configure('lexware_sync.license_key', 'key-one');
        $this->licenseService()->willRespondWith('POST', '/v1/check', ['license' => $this->approval()]);

        $tester = $this->run();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertNotNull($this->service(LicenseStore::class)->storedToken('key-one'));
    }

    public function testARefusalIsStoredAndReportedAsAFailure(): void
    {
        $this->configure('lexware_sync.license_key', 'key-one');
        $this->licenseService()->willRespondWith('POST', '/v1/check', ['license' => $this->refusal('expired')]);

        $tester = $this->run();

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('expired', $tester->getDisplay());
        self::assertNotNull($this->service(LicenseStore::class)->storedToken('key-one'));
    }

    public function testAFreshArtefactWhoseRecheckDateHasNotPassedIsLeftAlone(): void
    {
        $this->configure('lexware_sync.license_key', 'key-one');
        $this->service(LicenseStore::class)->store('key-one', $this->approvalRecheckedAfter('2099-01-01T00:00:00+01:00'));

        $tester = $this->run();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertSame(0, $this->licenseService()->requestCount());
    }

    public function testAnUnreachableServiceFailsWithoutDiscardingWhatIsStored(): void
    {
        $this->configure('lexware_sync.license_key', 'key-one');
        $this->service(LicenseStore::class)->store('key-one', $this->approvalRecheckedAfter('2020-01-01T00:00:00+01:00'));
        $this->licenseService()->willRespondWith('POST', '/v1/check', ['message' => 'down'], 500);

        $tester = $this->run();

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertNotNull($this->service(LicenseStore::class)->storedToken('key-one'));
    }

    private function run(): CommandTester
    {
        $application = new Application(self::$kernel);
        $tester = new CommandTester($application->find('kimai:lexware-sync:check-license'));
        $tester->execute([]);

        return $tester;
    }
}
```

The helpers `licenseService()`, `approval()`, `refusal()` and `approvalRecheckedAfter()` are the ones from Task 6, moved into a shared trait `Tests/Support/SignsLicenseArtefacts.php` when this test is written, so that both test classes use the same signing code.

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --testsuite functional --filter CheckLicenseCommandTest`
Expected: FAIL, the command is not defined.

- [ ] **Step 3: Write minimal implementation**

```php
<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Command;

use KimaiPlugin\KimaiLexwareSyncBundle\Configuration\LexwareSyncConfiguration;
use KimaiPlugin\KimaiLexwareSyncBundle\Service\License\LicenseClient;
use KimaiPlugin\KimaiLexwareSyncBundle\Service\License\LicenseEvaluator;
use KimaiPlugin\KimaiLexwareSyncBundle\Service\License\LicenseServiceUnavailable;
use KimaiPlugin\KimaiLexwareSyncBundle\Service\License\LicenseStore;
use KimaiPlugin\KimaiLexwareSyncBundle\Service\License\PluginVersion;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'kimai:lexware-sync:check-license', description: 'Confirm the configured license with the licensing service')]
final class CheckLicenseCommand extends Command
{
    public function __construct(
        private readonly LexwareSyncConfiguration $configuration,
        private readonly LicenseStore $store,
        private readonly LicenseEvaluator $evaluator,
        private readonly LicenseClient $client,
        private readonly PluginVersion $pluginVersion,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $licenseKey = $this->configuration->getLicenseKey();
        if ($licenseKey === '') {
            $io->error('No license key is configured, so nothing can be converted.');

            return Command::FAILURE;
        }

        $version = $this->pluginVersion->current();
        $now = new \DateTimeImmutable();
        $stored = $this->evaluator->usableToken($this->store->storedToken($licenseKey), $version, $now);

        if ($stored !== null) {
            $recheckAfter = $stored->recheckAfter();
            if ($recheckAfter !== null && $recheckAfter > $now) {
                $io->success('The stored license is still current, nothing to do.');

                return $stored->isLicensed() ? Command::SUCCESS : Command::FAILURE;
            }
        }

        try {
            $raw = $this->client->fetch($licenseKey, $version);
        } catch (LicenseServiceUnavailable $exception) {
            $message = 'The licensing service could not be reached: ' . $exception->getMessage();
            $this->logger->error($message);
            $io->error($message);

            return Command::FAILURE;
        }

        $fresh = $this->evaluator->usableToken($raw, $version, $now);
        if ($fresh === null) {
            $message = 'The licensing service answered with an artefact this installation cannot use.';
            $this->logger->error($message);
            $io->error($message);

            return Command::FAILURE;
        }

        $this->store->store($licenseKey, $raw);

        if (!$fresh->isLicensed()) {
            $io->error('The license was refused: ' . ($fresh->reason() ?? 'no reason given'));

            return Command::FAILURE;
        }

        $io->success('The license is confirmed for ' . $fresh->customer() . '.');

        return Command::SUCCESS;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit --testsuite functional --filter CheckLicenseCommandTest`
Expected: PASS, 5 tests.

- [ ] **Step 5: Run the full verification loop**

Run: `just check`

- [ ] **Step 6: Commit**

```bash
git add Command Tests
git commit -m "Refresh the license from a scheduled command"
```

---

### Task 9: The license key as a configuration field

**Files:**
- Modify: `Configuration/LexwareSyncConfiguration.php`
- Modify: `EventSubscriber/SystemConfigurationSubscriber.php`
- Modify: `Resources/translations/system-configuration.en.xlf`
- Modify: `Resources/translations/system-configuration.de.xlf`
- Test: `Tests/Functional/License/LicenseConfigurationTest.php`

**Interfaces:**
- Consumes: `LexwareApiKeyType` as the field type, because the license key deserves the same treatment as the API key: shown blank, kept when submitted empty.
- Produces: `LexwareSyncConfiguration::getLicenseKey(): string` reading `lexware_sync.license_key`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Tests\Functional\License;

use KimaiPlugin\KimaiLexwareSyncBundle\Configuration\LexwareSyncConfiguration;
use KimaiPlugin\KimaiLexwareSyncBundle\Tests\Support\FunctionalTestCase;

final class LicenseConfigurationTest extends FunctionalTestCase
{
    public function testTheLicenseKeyIsReadFromTheSystemConfiguration(): void
    {
        $this->configure('lexware_sync.license_key', 'key-one');

        self::assertSame('key-one', $this->service(LexwareSyncConfiguration::class)->getLicenseKey());
    }

    public function testAMissingLicenseKeyIsAnEmptyStringRatherThanNull(): void
    {
        self::assertSame('', $this->service(LexwareSyncConfiguration::class)->getLicenseKey());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --testsuite functional --filter LicenseConfigurationTest`
Expected: FAIL, method `getLicenseKey` not defined.

- [ ] **Step 3: Write minimal implementation**

Add to `Configuration/LexwareSyncConfiguration.php`:

```php
    public function getLicenseKey(): string
    {
        $value = $this->configuration->find('lexware_sync.license_key');

        return \is_string($value) ? $value : '';
    }
```

Add as the first entry of the configuration list in `EventSubscriber/SystemConfigurationSubscriber.php`:

```php
                    (new Configuration('lexware_sync.license_key'))
                        ->setTranslationDomain('system-configuration')
                        ->setType(LexwareApiKeyType::class)
                        ->setRequired(false),
```

Add the label and help text to both translation files, following the pattern of the existing `lexware_sync.api_key` entries. English: "License key" and "The license key from your purchase confirmation. Without it no order confirmation is converted into a project and no invoice is written back to Lexware." German: "Lizenzschlüssel" and the same sentence in German.

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit --testsuite functional --filter LicenseConfigurationTest`
Expected: PASS, 2 tests.

- [ ] **Step 5: Verify the screen renders**

Run: `/opt/kimai/bin/console kimai:reload -n` then open the system configuration screen in the browser and confirm the new field appears above the Lexware API key with its label in both languages.

- [ ] **Step 6: Run the full verification loop**

Run: `just check`

- [ ] **Step 7: Commit**

```bash
git add Configuration EventSubscriber Resources/translations Tests
git commit -m "Let an administrator enter the license key"
```

---

### Task 10: Say what is wrong on both screens

**Files:**
- Modify: `Controller/TriageController.php`
- Modify: `Controller/InvoiceAssignmentController.php`
- Modify: `Resources/views/triage/index.html.twig`
- Modify: `Resources/views/invoice/index.html.twig`
- Modify: `Resources/views/invoice/assign.html.twig`
- Modify: `Resources/translations/messages.en.xlf`
- Modify: `Resources/translations/messages.de.xlf`
- Test: `Tests/Functional/License/LicenseBannerTest.php`

**Interfaces:**
- Consumes: `LicenseGate`, `LicenseState`.
- Produces: the template variable `licenseVerdict`, an array with the keys `state` (the enum case name in lower snake case), `customer` and `reason`, so that Twig does not have to know the enum.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Tests\Functional\License;

use App\Entity\User;
use KimaiPlugin\KimaiLexwareSyncBundle\Tests\Support\WebTestCase;

final class LicenseBannerTest extends WebTestCase
{
    public function testTheTriageScreenExplainsAMissingLicense(): void
    {
        $this->configure('lexware_sync.license_key', '');
        $browser = $this->browserLoggedInAs('administrator', [User::ROLE_SUPER_ADMIN]);

        $crawler = $browser->request('GET', $this->url('lexware_sync_triage'));

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-license-state="no_key_configured"]'));
    }

    public function testTheInvoiceScreenExplainsAMissingLicense(): void
    {
        $this->configure('lexware_sync.license_key', '');
        $browser = $this->browserLoggedInAs('administrator', [User::ROLE_SUPER_ADMIN]);

        $crawler = $browser->request('GET', $this->url('lexware_sync_invoices'));

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-license-state="no_key_configured"]'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --testsuite functional --filter LicenseBannerTest`
Expected: FAIL, no element with that attribute.

- [ ] **Step 3: Write minimal implementation**

Both controllers take `LicenseGate` in the constructor and pass this into every `render()` call of the two index actions and the assign action:

```php
            'licenseVerdict' => [
                'state' => strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $verdict->state->name) ?? $verdict->state->name),
                'customer' => $verdict->customer,
                'reason' => $verdict->reason,
            ],
```

Each of the three templates gains this immediately inside `{% block main %}`:

```twig
    {% if licenseVerdict.state != 'licensed' %}
        <div class="alert alert-warning" data-license-state="{{ licenseVerdict.state }}">
            {{ ('lexware_sync.license.' ~ licenseVerdict.state)|trans }}
            {% if licenseVerdict.reason %}
                {{ ('lexware_sync.license.reason.' ~ licenseVerdict.reason)|trans }}
            {% endif %}
        </div>
    {% endif %}
```

Four buttons gain `{% if licenseVerdict.state != 'licensed' %}disabled{% endif %}`: the convert button of each row in `triage/index.html.twig`, the convert button in `invoice/assign.html.twig`, and the two buttons in `invoice/index.html.twig` that lead into a conversion. Locate them by the routes their forms post to: `lexware_sync_triage_convert`, `lexware_sync_invoices_convert` and `lexware_sync_invoices_confirm_existing`. The reject and reopen buttons stay enabled, because deciding against a document is not a conversion and must keep working without a license.

Translation keys to add to both message files: `lexware_sync.license.no_key_configured`, `lexware_sync.license.rejected`, `lexware_sync.license.unreachable`, `lexware_sync.license.version_not_covered`, and one per reason: `lexware_sync.license.reason.expired`, `.revoked`, `.unknown_key`, `.version_not_covered`. Each says plainly what is wrong and what to do, for example for `unreachable`: "The licensing service could not be reached, so the license cannot be confirmed. Check whether this server is allowed to reach the internet."

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit --testsuite functional --filter LicenseBannerTest`
Expected: PASS, 2 tests.

- [ ] **Step 5: Run the full verification loop**

Run: `just check`

- [ ] **Step 6: Commit**

```bash
git add Controller Resources Tests
git commit -m "Say on both screens why nothing can be converted"
```

---

### Task 11: The production key pair and the documentation

**Files:**
- Modify: `Resources/config/services.yaml`
- Modify: `README.md`
- Create: outside the repository, a file holding the private key

**Interfaces:**
- Consumes: nothing.
- Produces: the value of the parameter `lexware_sync.license_public_keys`.

- [ ] **Step 1: Generate the pair**

```bash
php -r '$p = sodium_crypto_sign_keypair(); file_put_contents(getenv("HOME") . "/kimai-lexware-sync-license-signing-key.txt", "secret: " . base64_encode(sodium_crypto_sign_secretkey($p)) . PHP_EOL . "public: " . base64_encode(sodium_crypto_sign_publickey($p)) . PHP_EOL); echo "public: ", base64_encode(sodium_crypto_sign_publickey($p)), PHP_EOL;'
```

The private key is written to a file in the home directory and is never printed to a terminal transcript, never committed, and never placed inside this repository. It belongs to the licensing service project. Move it there and delete the file afterwards.

- [ ] **Step 2: Put the public key into the parameter**

Replace the empty list in `Resources/config/services.yaml`:

```yaml
parameters:
    lexware_sync.license_public_keys: ['THE BASE64 PUBLIC KEY FROM STEP 1']
```

- [ ] **Step 3: Document the cron entry**

In `README.md`, in the list of console commands that need a cron entry, add:

```markdown
- `bin/console kimai:lexware-sync:check-license` confirms the configured license with the
  licensing service. Schedule it once a day. It usually does nothing, because it only asks again
  when the stored confirmation says it is time.
```

Also add `lexware_sync.license_key` to the configuration table, and a sentence to the Status section saying that the license check is present but switched off until the licensing service exists.

- [ ] **Step 4: Verify nothing leaked**

```bash
git -C /opt/kimai/var/plugins/KimaiLexwareSyncBundle grep -i "secret:" -- . && echo "STOP: a secret is in the working tree"
```

Expected: no match.

- [ ] **Step 5: Run the full verification loop**

Run: `just check`

- [ ] **Step 6: Commit**

```bash
git add Resources/config/services.yaml README.md
git commit -m "Ship the license signing public key and document the schedule"
```

---

## What this plan does not do

The alias in `Resources/config/services.yaml` still points at `AlwaysLicensedGate` when this plan is finished. Switching it is a separate decision that belongs with the launch of the licensing service, and the test written in Task 6 asserts the shipped state, so flipping the alias without also changing that test fails the build. That is on purpose: the reminder arrives exactly when it is useful.
