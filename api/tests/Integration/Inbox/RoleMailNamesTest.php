<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Integration\Inbox;

use App\Tenancy\Application\Seed\SeedPlatform;
use App\Tests\Support\ShippedLanguages;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Translation\TranslatorBagInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * A mail names a built-in role as the screens do, in each language the interface speaks: a role added without its mail
 * name would reach the person whose role changed as its translation key.
 */
final class RoleMailNamesTest extends KernelTestCase
{
    private const string TEXTS = __DIR__.'/../../../../web/public/i18n';

    public function testEveryBuiltInRoleIsNamedInTheMailsAsTheScreensNameIt(): void
    {
        $translator = static::getContainer()->get(TranslatorInterface::class);
        self::assertInstanceOf(TranslatorBagInterface::class, $translator);
        $roles = array_keys(SeedPlatform::BUILT_IN_ROLES);
        self::assertCount(5, $roles, 'the built-in roles were read');

        foreach (ShippedLanguages::catalogue()->interface() as $language) {
            $screens = json_decode((string) file_get_contents(self::TEXTS.'/'.$language.'.json'), true);
            self::assertIsArray($screens);
            self::assertIsArray($screens['roles'] ?? null);
            $catalogue = $translator->getCatalogue($language);
            foreach ($roles as $role) {
                self::assertTrue($catalogue->has("roles.$role", 'emails'), "roles.$role ($language) has no name in the mails");
                self::assertSame($screens['roles'][$role] ?? null, $catalogue->get("roles.$role", 'emails'), "roles.$role ($language): the mail and the screens name it alike");
            }
        }
    }
}
