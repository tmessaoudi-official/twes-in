<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Integration\Texts;

use App\Shared\Application\Languages;
use App\Texts\Application\ShippedTexts;
use App\Texts\Domain\Service\TextLocks;
use App\Texts\Domain\ValueObject\TextAddress;
use App\Texts\Infrastructure\Catalogue\ShippedTextFiles;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The texts a release ships, as an override finds them: a screen text by its file (the main one, or a page's part, whose
 * top-level names repeat the main file's), an API text by its domain, each by its dotted key; and every printed label
 * classed either locked (what a mandatory mention or a legal status prints through) or open, so a new one is classed
 * when it is added.
 */
final class ShippedTextsTest extends KernelTestCase
{
    public function testATextIsFoundByItsFileAndItsKey(): void
    {
        $texts = self::shipped();

        self::assertStringStartsWith('Votre clé d’accès fonctionne', (string) $texts->original(new TextAddress('fr', 'screen', 'auth.mfa.passkey_elsewhere')));
        self::assertSame('Vérification en deux étapes', $texts->original(new TextAddress('fr', 'screen:two-factor', 'auth.two_factor.title')));
        self::assertNull($texts->original(new TextAddress('fr', 'screen', 'auth.two_factor.title')), 'the part\'s text is not the main file\'s');
        self::assertSame('Facture', $texts->original(new TextAddress('fr', 'pdf', 'invoice.title')));
        self::assertSame('Invoice', $texts->original(new TextAddress('en', 'pdf', 'invoice.title')));
        self::assertNull($texts->original(new TextAddress('fr', 'screen', 'auth.mfa')), 'a group of texts is no text');
        self::assertNull($texts->original(new TextAddress('fr', 'screen:nowhere', 'auth.mfa.passkey')));
        self::assertNull($texts->original(new TextAddress('fr', 'messages', 'anything')), 'only the domains the API writes texts in');
        self::assertNull($texts->original(new TextAddress('xx', 'pdf', 'invoice.title')));

        $domains = $texts->domains();
        foreach (['screen', 'screen:two-factor', 'screen:records', 'pdf', 'emails', 'imports', 'roles', 'fiscal'] as $domain) {
            self::assertContains($domain, $domains);
        }
        self::assertGreaterThan(3000, \count($texts->texts('fr', 'screen')));
    }

    public function testEveryPrintedLabelIsClassedLockedOrOpenAndTheFiscalTextsAreLocked(): void
    {
        $locked = static::getContainer()->getParameter('app.texts.locked');
        $open = static::getContainer()->getParameter('app.texts.open');
        $locks = new TextLocks($locked);
        $texts = self::shipped();

        $unclassed = [];
        foreach (array_keys($texts->texts('fr', 'pdf')) as $key) {
            $address = new TextAddress('fr', 'pdf', $key);
            $isOpen = TextLocks::matches($open['pdf'], $key);
            if ($locks->locked($address) === $isOpen) {
                $unclassed[] = $key;
            }
        }
        self::assertSame([], $unclassed, 'each printed label is either locked or open, never both nor neither');

        foreach (['invoice.corrects', 'totals.total', 'totals.withheld', 'watermark.draft', 'demo.band', 'format.date'] as $key) {
            self::assertTrue($locks->locked(new TextAddress('fr', 'pdf', $key)), $key);
        }
        foreach (['invoice.title', 'quote.title', 'payment.how_to_pay'] as $key) {
            self::assertFalse($locks->locked(new TextAddress('fr', 'pdf', $key)), $key);
        }
        foreach (array_keys($texts->texts('fr', 'fiscal')) as $key) {
            self::assertTrue($locks->locked(new TextAddress('fr', 'fiscal', $key)), 'fiscal: '.$key);
        }
        self::assertNotEmpty($texts->texts('fr', 'fiscal'));
        self::assertFalse($locks->locked(new TextAddress('fr', 'screen', 'auth.mfa.passkey_elsewhere')));
    }

    /** The files as the container names them; the adapter itself, until a use case reads it and keeps it wired. */
    private static function shipped(): ShippedTexts
    {
        $container = static::getContainer();

        return new ShippedTextFiles(
            $container->get(Languages::class),
            $container->getParameter('app.texts.screen_directory'),
            $container->getParameter('kernel.project_dir').'/translations',
        );
    }
}
