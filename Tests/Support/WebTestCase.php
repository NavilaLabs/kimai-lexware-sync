<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Tests\Support;

use App\Entity\User;
use KimaiPlugin\KimaiLexwareSyncBundle\Tests\TestKernel;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase as SymfonyWebTestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

abstract class WebTestCase extends SymfonyWebTestCase
{
    use InteractsWithKimai;

    private const FIREWALL = 'secured_area';

    private ?KernelBrowser $kernelBrowser = null;

    protected static function getKernelClass(): string
    {
        return TestKernel::class;
    }

    protected function tearDown(): void
    {
        $this->forgetEntityFactory();
        $this->kernelBrowser = null;
        parent::tearDown();
    }

    protected function browser(): KernelBrowser
    {
        return $this->kernelBrowser ??= self::createClient();
    }

    /**
     * @param list<string> $roles
     */
    protected function browserLoggedInAs(string $username = 'tester', array $roles = [User::ROLE_USER]): KernelBrowser
    {
        $browser = $this->browser();
        $browser->loginUser($this->factory()->createUser($username, $roles), self::FIREWALL);

        return $browser;
    }

    /**
     * Routes are generated rather than written out, because several of them carry a locale
     * prefix that a test has no business knowing about.
     *
     * @param array<string, mixed> $parameters
     */
    protected function url(string $route, array $parameters = []): string
    {
        return $this->service(UrlGeneratorInterface::class)->generate($route, $parameters);
    }
}
