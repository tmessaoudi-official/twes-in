<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Integration\Shared;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * What the API says on the platform's channel (`app.realtime.platform_kinds`) is what /platform reloads on
 * (`PLATFORM_KINDS`, web/src/app/platform/platform-kinds.ts), and each is a kind some use case records or says (an
 * account's lock, said without an audit entry of that kind, as `AccountChanges::KIND`): a kind said
 * and never heard, or heard and never said, leaves the operator's page stale with no error anywhere.
 */
final class PlatformKindsTest extends KernelTestCase
{
    public function testTheKindsSaidToOperatorsAreTheKindsTheirPageHearsAndEachIsRecorded(): void
    {
        $said = static::getContainer()->getParameter('app.realtime.platform_kinds');
        $root = \dirname(__DIR__, 4);
        $web = file_get_contents($root.'/web/src/app/platform/platform-kinds.ts');
        self::assertIsString($web);
        if (1 !== preg_match('/PLATFORM_KINDS: readonly string\[\] = \[(.*?)\];/s', $web, $list)) {
            self::fail('PLATFORM_KINDS is not found in platform-kinds.ts');
        }
        preg_match_all("/'([a-z_]+)'/", $list[1], $heard);

        self::assertGreaterThanOrEqual(6, \count($said), 'the list is read');
        self::assertSame($said, $heard[1]);
        $recorded = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root.'/api/src', \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file instanceof \SplFileInfo && 'php' === $file->getExtension()) {
                preg_match_all("/(?:ENTITY_TYPE|KIND) = '([a-z_]+)'/", (string) file_get_contents($file->getPathname()), $types);
                $recorded = [...$recorded, ...$types[1]];
            }
        }
        foreach ($said as $kind) {
            self::assertContains($kind, $recorded, "no use case records the kind $kind");
        }
    }
}
