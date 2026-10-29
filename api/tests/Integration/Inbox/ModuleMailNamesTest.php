<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Integration\Inbox;

use App\ModuleRegistry\Application\ModuleCatalog;
use App\Tests\Support\ShippedLanguages;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Translation\TranslatorBagInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * A mail names a module as the screens do: every module the catalogue holds, built or planned, has its name in the mails'
 * texts in each language the interface speaks, the same words as the screens' `modules` texts. A module added without
 * its mail name would arrive in a mail as its translation key.
 */
final class ModuleMailNamesTest extends KernelTestCase
{
    private const string TEXTS = __DIR__.'/../../../../web/public/i18n';

    public function testEveryModuleIsNamedInTheMailsAsTheScreensNameIt(): void
    {
        $translator = static::getContainer()->get(TranslatorInterface::class);
        self::assertInstanceOf(TranslatorBagInterface::class, $translator);
        // The test environment's own fixture module is no product's and has no name anywhere.
        $modules = array_filter(static::getContainer()->get(ModuleCatalog::class)->all(), static fn ($module): bool => 'fixture_ledger' !== $module->key);
        self::assertGreaterThan(25, \count($modules), 'the catalogue was read');

        foreach (ShippedLanguages::catalogue()->interface() as $language) {
            $screens = json_decode((string) file_get_contents(self::TEXTS.'/'.$language.'.json'), true);
            self::assertIsArray($screens);
            $catalogue = $translator->getCatalogue($language);
            foreach ($modules as $module) {
                self::assertTrue($catalogue->has($module->labelKey, 'emails'), "$module->labelKey ($language) has no name in the mails");
                $said = $screens;
                foreach (explode('.', $module->labelKey) as $part) {
                    $said = \is_array($said) ? $said[$part] ?? null : null;
                }
                self::assertSame($said, $catalogue->get($module->labelKey, 'emails'), "$module->labelKey ($language): the mail and the screens name it alike");
            }
        }
    }
}
