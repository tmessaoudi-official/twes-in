<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Architecture;

use App\Tenancy\Application\Permission\KnownPermissions;
use App\Tenancy\Application\Seed\SeedPlatform;
use App\Tenancy\Domain\Permission;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The catalogue is what a roles screen shows, so a permission missing from it cannot be granted by anyone
 * (docs/SPEC.md § 7, 2026-09-20 11:30, and row 104).
 *
 * That makes its completeness a guarantee rather than a convenience, and nothing about *collecting* it makes it
 * complete: the module half is collected from the manifests, but the permissions belonging to no module are named
 * once by hand, and a tenth permission class added next year would be invisible on the screen and silently
 * ungrantable. This test is what makes the guarantee true — it discovers the constants from the source rather than
 * listing them, so a class it has never heard of still reds.
 */
final class PermissionCatalogueTest extends KernelTestCase
{
    /** Below this the discovery found nothing and every comparison below would pass on two empty sets. */
    private const int DECLARED_AT_LEAST = 15;

    public function testTheCatalogueHoldsEveryPermissionTheCodeDeclaresAndInventsNone(): void
    {
        $declared = self::declaredInSource();
        self::assertGreaterThanOrEqual(
            self::DECLARED_AT_LEAST,
            \count($declared),
            'the discovery below found the permission classes; a lower count means its glob stopped matching',
        );

        $catalogued = self::catalogued();

        self::assertSame([], array_values(array_diff($declared, $catalogued)), 'every declared permission is in the catalogue, or it cannot be granted');
        self::assertSame([], array_values(array_diff($catalogued, $declared)), 'the catalogue offers no permission the code never checks');
    }

    /**
     * A platform permission is held outside any membership (docs/SPEC.md § 3 Authorization), so a company's own role
     * may never grant one — offering it on the screen would be offering a company the operator's keys.
     */
    public function testNoPlatformPermissionIsOfferedToACompanyRole(): void
    {
        $platform = array_filter(self::catalogued(), static fn (string $p) => Permission::fromString($p)->isPlatformScoped());

        self::assertSame([], array_values($platform));
    }

    public function testEachPermissionSitsInExactlyOneNonEmptyGroup(): void
    {
        $groups = self::getContainer()->get(KnownPermissions::class)->groups();
        self::assertNotEmpty($groups);

        $seen = [];
        foreach ($groups as $group) {
            self::assertNotEmpty($group->permissions, "the {$group->key} group would be an empty heading on the screen");
            self::assertNotSame('', $group->labelKey, "the {$group->key} group has no label to show");
            foreach ($group->permissions as $permission) {
                self::assertArrayNotHasKey($permission, $seen, "$permission is in both the {$seen[$permission]} and {$group->key} groups, so a screen would show it twice");
                $seen[$permission] = $group->key;
            }
        }
    }

    public function testTheCatalogueAnswersWhetherItKnowsAPermission(): void
    {
        $known = self::getContainer()->get(KnownPermissions::class);

        self::assertTrue($known->knows(self::catalogued()[0]));
        self::assertFalse($known->knows('invoice.invented'));
        // The wildcard is what a built-in role holds, never a row on the screen: it is not a permission, it is all of them.
        self::assertFalse($known->knows(Permission::WILDCARD));
        self::assertFalse($known->knows('platform.company.create'));
    }

    /** @return list<string> */
    private static function catalogued(): array
    {
        $permissions = [];
        foreach (self::getContainer()->get(KnownPermissions::class)->groups() as $group) {
            foreach ($group->permissions as $permission) {
                $permissions[] = $permission;
            }
        }
        sort($permissions);

        return array_values(array_unique($permissions));
    }

    /**
     * Every permission string the source declares, discovered rather than listed, from BOTH places one can appear.
     *
     * The constants are the obvious half. The other half is what the built-in roles are seeded with, and it is the
     * half that caught something: `user.read` and `user.write` were bare literals in three providers, in no
     * permission class at all, and were granted to the built-in admin — so a catalogue built from the classes alone
     * offered every other permission and silently not those two, leaving a company unable to build a role that reads
     * its own members. Any permission a built-in role holds must be offerable to a custom one, or the built-in roles
     * cannot be reproduced.
     *
     * The wildcard and the platform prefix are not permissions — one is all of them and the other is half a string —
     * so they are dropped by shape, not by name: anything that is not two or more dotted lower-case segments.
     *
     * @return list<string>
     */
    private static function declaredInSource(): array
    {
        $permissions = [];
        foreach (SeedPlatform::BUILT_IN_ROLES as $granted) {
            foreach ($granted as $value) {
                if (self::isPermission($value)) {
                    $permissions[] = $value;
                }
            }
        }
        foreach (self::sourceFiles() as $file) {
            preg_match_all("/const string [A-Z_]+ = '([^']+)'/", (string) file_get_contents($file), $matches);
            foreach ($matches[1] as $value) {
                if (self::isPermission($value)) {
                    $permissions[] = $value;
                }
            }
        }
        sort($permissions);

        return array_values(array_unique($permissions));
    }

    /**
     * A permission string a company role may hold. The shape is asked of the domain rather than matched again here:
     * a second copy of that pattern would be free to drift from the one the voter actually uses.
     */
    private static function isPermission(string $value): bool
    {
        return Permission::isWellFormed($value) && !Permission::fromString($value)->isPlatformScoped();
    }

    /** @return list<string> */
    private static function sourceFiles(): array
    {
        $files = [];
        $directory = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(\dirname(__DIR__, 2).'/src'));
        foreach ($directory as $file) {
            if ($file instanceof \SplFileInfo && str_ends_with($file->getFilename(), 'Permission.php')) {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}
