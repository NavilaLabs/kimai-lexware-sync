<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Tests\EventSubscriber;

use App\Event\SystemConfigurationEvent;
use App\Form\Model\Configuration;
use KimaiPlugin\KimaiLexwareSyncBundle\Configuration\LexwareSyncConfiguration;
use KimaiPlugin\KimaiLexwareSyncBundle\EventSubscriber\SystemConfigurationSubscriber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final class SystemConfigurationSubscriberTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string|int|null|bool|float}>
     */
    public static function settingsThatCarryADefault(): array
    {
        return [
            'reconcile interval' => ['lexware_sync.reconcile_interval_minutes', LexwareSyncConfiguration::DEFAULT_RECONCILE_INTERVAL_MINUTES],
            'api key check interval' => ['lexware_sync.check_api_key_interval_days', LexwareSyncConfiguration::DEFAULT_CHECK_API_KEY_INTERVAL_DAYS],
            'license check interval' => ['lexware_sync.check_license_interval_days', LexwareSyncConfiguration::DEFAULT_CHECK_LICENSE_INTERVAL_DAYS],
            'budget unit regex' => ['lexware_sync.budget_unit_regex', LexwareSyncConfiguration::DEFAULT_BUDGET_UNIT_REGEX],
            'project title source' => ['lexware_sync.project_title_source', LexwareSyncConfiguration::DEFAULT_PROJECT_TITLE_SOURCE],
            'project completion mode' => ['lexware_sync.project_completion_mode', LexwareSyncConfiguration::DEFAULT_PROJECT_COMPLETION_MODE],
        ];
    }

    /**
     * @dataProvider settingsThatCarryADefault
     */
    public function testASettingWithADefaultOffersThatDefaultAsItsFormValue(string $name, string|int|null|bool|float $expected): void
    {
        self::assertSame($expected, $this->configurationNamed($name)->getValue());
    }

    public function testASettingWithoutADefaultStaysEmpty(): void
    {
        self::assertNull($this->configurationNamed('lexware_sync.line_regex')->getValue());
    }

    private function configurationNamed(string $name): Configuration
    {
        $event = new SystemConfigurationEvent([]);
        $this->subscriber()->onSystemConfiguration($event);

        foreach ($event->getConfigurations() as $model) {
            foreach ($model->getConfiguration() as $configuration) {
                if ($configuration->getName() === $name) {
                    return $configuration;
                }
            }
        }

        self::fail(sprintf('The system configuration screen does not offer "%s".', $name));
    }

    private function subscriber(): SystemConfigurationSubscriber
    {
        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturn('/connect-webhooks');

        $csrfTokenManager = $this->createMock(CsrfTokenManagerInterface::class);
        $csrfTokenManager->method('getToken')->willReturn(new CsrfToken('lexware_sync_connect_webhooks', 'token'));

        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        return new SystemConfigurationSubscriber($urlGenerator, $csrfTokenManager, $translator);
    }
}
