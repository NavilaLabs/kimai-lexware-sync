<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Tests\Service\License;

use KimaiPlugin\KimaiLexwareSyncBundle\Service\License\LicenseState;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\Loader\XliffFileLoader;

final class LicenseTranslationCatalogueTest extends TestCase
{
    /**
     * The licensing service decides which reason a refusal carries, and the plugin passes it
     * through as a plain string, so there is nothing in the code to enumerate. These four are
     * what the protocol in the design specification defines.
     */
    private const REFUSAL_REASONS = ['expired', 'revoked', 'unknown_key', 'version_not_covered'];

    private const KEY_PREFIX = 'lexware_sync.license.';

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function catalogues(): array
    {
        return [
            'English' => ['messages.en.xlf', 'en'],
            'German' => ['messages.de.xlf', 'de'],
        ];
    }

    /**
     * @dataProvider catalogues
     */
    public function testACatalogueHoldsExactlyTheLicenseMessagesTheCodeCanAskFor(string $file, string $locale): void
    {
        $present = array_keys($this->licenseMessagesIn($file, $locale));
        sort($present);

        self::assertSame($this->expectedKeys(), $present);
    }

    /**
     * @dataProvider catalogues
     */
    public function testEveryLicenseMessageIsTranslatedRatherThanEchoingItsKey(string $file, string $locale): void
    {
        foreach ($this->licenseMessagesIn($file, $locale) as $key => $message) {
            self::assertNotSame('', trim($message), $key . ' has no translation in ' . $file . '.');
            self::assertNotSame($key, $message, $key . ' would render as its own key in ' . $file . '.');
        }
    }

    /**
     * @return list<string>
     */
    private function expectedKeys(): array
    {
        $keys = [];

        foreach (LicenseState::cases() as $state) {
            $keys[] = self::KEY_PREFIX . $state->key();
        }

        foreach (self::REFUSAL_REASONS as $reason) {
            $keys[] = self::KEY_PREFIX . 'reason.' . $reason;
        }

        sort($keys);

        return $keys;
    }

    /**
     * @return array<string, string>
     */
    private function licenseMessagesIn(string $file, string $locale): array
    {
        $catalogue = (new XliffFileLoader())->load(
            \dirname(__DIR__, 3) . '/Resources/translations/' . $file,
            $locale,
            'messages'
        );

        $messages = [];
        foreach ($catalogue->all('messages') as $key => $message) {
            if (str_starts_with($key, self::KEY_PREFIX)) {
                $messages[$key] = $message;
            }
        }

        return $messages;
    }
}
