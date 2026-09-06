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

}
