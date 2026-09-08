<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Service\License;

final class LicenseEvaluator
{
    public function __construct(private readonly LicenseSignatureVerifier $verifier)
    {
    }

    public function canVerifySignatures(): bool
    {
        return $this->verifier->acceptsAnySigningKey();
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

    public function hasBrokenSignature(string $rawToken): bool
    {
        try {
            $token = LicenseToken::parse($rawToken);
        } catch (MalformedLicenseToken) {
            return false;
        }

        return !$this->verifier->isSignatureValid($token);
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
