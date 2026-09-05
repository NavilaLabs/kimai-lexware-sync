<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Tests\Support;

use KimaiPlugin\KimaiLexwareSyncBundle\Tests\TestKernel;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

abstract class FunctionalTestCase extends KernelTestCase
{
    use InteractsWithKimai;

    protected static function getKernelClass(): string
    {
        return TestKernel::class;
    }

    protected function setUp(): void
    {
        parent::setUp();
        self::bootKernel();
    }

    protected function tearDown(): void
    {
        $this->forgetEntityFactory();
        parent::tearDown();
    }
}
