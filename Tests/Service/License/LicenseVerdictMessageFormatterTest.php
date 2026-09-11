<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Tests\Service\License;

use KimaiPlugin\KimaiLexwareSyncBundle\Dto\License\LicenseVerdict;
use KimaiPlugin\KimaiLexwareSyncBundle\Enum\License\LicenseState;
use KimaiPlugin\KimaiLexwareSyncBundle\Service\License\LicenseVerdictMessageFormatter;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

final class LicenseVerdictMessageFormatterTest extends TestCase
{
    public function testAVerdictWithoutAReasonUsesOnlyTheStateMessage(): void
    {
        $formatter = new LicenseVerdictMessageFormatter($this->recordingTranslator());

        $message = $formatter->format(LicenseVerdict::refused(LicenseState::NoKeyConfigured));

        self::assertSame('translated:lexware_sync.license.no_key_configured', $message);
    }

    public function testAVerdictWithAReasonAppendsTheReasonMessage(): void
    {
        $formatter = new LicenseVerdictMessageFormatter($this->recordingTranslator());

        $message = $formatter->format(LicenseVerdict::refused(LicenseState::Rejected, 'expired'));

        self::assertSame(
            'translated:lexware_sync.license.rejected translated:lexware_sync.license.reason.expired',
            $message
        );
    }

    public function testDifferentStatesProduceDifferentMessages(): void
    {
        $formatter = new LicenseVerdictMessageFormatter($this->recordingTranslator());

        $noKeyConfigured = $formatter->format(LicenseVerdict::refused(LicenseState::NoKeyConfigured));
        $unreachable = $formatter->format(LicenseVerdict::refused(LicenseState::Unreachable));

        self::assertNotSame($noKeyConfigured, $unreachable);
    }

    public function testDifferentReasonsForTheSameStateProduceDifferentMessages(): void
    {
        $formatter = new LicenseVerdictMessageFormatter($this->recordingTranslator());

        $expired = $formatter->format(LicenseVerdict::refused(LicenseState::Rejected, 'expired'));
        $revoked = $formatter->format(LicenseVerdict::refused(LicenseState::Rejected, 'revoked'));

        self::assertNotSame($expired, $revoked);
    }

    private function recordingTranslator(): TranslatorInterface
    {
        return new class () implements TranslatorInterface {
            /**
             * @param mixed[] $parameters
             */
            public function trans(string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string
            {
                Assert::assertSame('messages', $domain);

                return 'translated:' . $id;
            }

            public function getLocale(): string
            {
                return 'en';
            }
        };
    }
}
