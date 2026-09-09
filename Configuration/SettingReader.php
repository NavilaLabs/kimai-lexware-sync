<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Configuration;

/**
 * Reads one stored setting, and reads it fresh every time.
 *
 * Kimai's own SystemConfiguration answers from a snapshot of the whole configuration table that
 * is cached for a day, in a pool belonging to the environment that filled it. Saving on the
 * settings screen drops that pool's copy, but only that one, so a console run or a second web
 * node can keep acting on a value the administrator changed hours ago. Every setting this
 * plugin acts on is read through here instead, which makes the cache irrelevant for us.
 */
interface SettingReader
{
    public function read(string $name): ?string;
}
