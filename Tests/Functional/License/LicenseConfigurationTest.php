<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Tests\Functional\License;

use App\Event\SystemConfigurationEvent;
use App\Form\Model\Configuration;
use App\Form\Model\SystemConfiguration;
use KimaiPlugin\KimaiLexwareSyncBundle\Form\LexwareApiKeyType;
use KimaiPlugin\KimaiLexwareSyncBundle\Tests\Support\FunctionalTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

final class LicenseConfigurationTest extends FunctionalTestCase
{
    public function testTheLicenseKeyIsRegisteredAsASystemConfigurationField(): void
    {
        $licenseKey = $this->lexwareSyncSection()->getConfigurationByName('lexware_sync.license_key');

        self::assertNotNull($licenseKey);
        self::assertSame(LexwareApiKeyType::class, $licenseKey->getType());
    }

    public function testTheLicenseKeyIsListedBeforeTheApiKey(): void
    {
        $names = array_map(
            static fn (Configuration $configuration): string => $configuration->getName(),
            $this->lexwareSyncSection()->getConfiguration()
        );

        $licenseKeyPosition = array_search('lexware_sync.license_key', $names, true);
        $apiKeyPosition = array_search('lexware_sync.api_key', $names, true);

        self::assertSame(0, $licenseKeyPosition);
        self::assertLessThan($apiKeyPosition, $licenseKeyPosition);
    }

    private function lexwareSyncSection(): SystemConfiguration
    {
        $requestStack = $this->service(RequestStack::class);
        $request = new Request();
        $request->setSession(new Session(new MockArraySessionStorage()));
        $requestStack->push($request);

        try {
            $event = new SystemConfigurationEvent([]);
            $this->service(EventDispatcherInterface::class)->dispatch($event);
        } finally {
            $requestStack->pop();
        }

        foreach ($event->getConfigurations() as $section) {
            if ($section->getConfigurationByName('lexware_sync.api_key') !== null) {
                return $section;
            }
        }

        self::fail('The section this bundle registers for the Lexware API key was not found.');
    }
}
