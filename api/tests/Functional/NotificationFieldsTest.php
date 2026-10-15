<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Inbox\Application\NotificationKinds;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Translation\TranslatorBagInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Every value a notification's sentence writes is either plain words (a name, a number of things) or declared on its kind
 * as an amount, a day, a moment or a quantity, which the mail writes as its reader writes them: a new kind whose sentence
 * names a figure it did not declare is caught here, not in somebody's inbox.
 */
final class NotificationFieldsTest extends KernelTestCase
{
    /** What a sentence may write as it is sent: names, document numbers, counts of things, the stage and days late. */
    private const array PLAIN = ['product', 'reference', 'establishment', 'location', 'company', 'by', 'customer', 'number', 'description', 'display_name', 'label', 'stage', 'days_late', 'count', 'counted', 'differences', 'currency'];

    public function testEverySentencesFigureIsDeclaredOnItsKindOrIsPlainWords(): void
    {
        $kinds = static::getContainer()->get(NotificationKinds::class);
        $translator = static::getContainer()->get(TranslatorInterface::class);
        self::assertInstanceOf(TranslatorBagInterface::class, $translator);
        $checked = 0;
        foreach ($kinds->all() as $kind) {
            $declared = [...$kind->amounts, ...$kind->days, ...$kind->moments, ...$kind->quantities];
            self::assertSame([], array_values(array_intersect($declared, self::PLAIN)), "$kind->type declares as a figure what is plain words");
            foreach (['fr', 'en'] as $language) {
                $sentence = $translator->getCatalogue($language)->get('notification.types.'.str_replace('.', '_', $kind->type), 'emails');
                preg_match_all('/%(\w+)%/', $sentence, $names);
                foreach ($names[1] as $name) {
                    self::assertContains($name, [...$declared, ...self::PLAIN], "$kind->type ($language) writes %$name%, neither declared on its kind nor plain words");
                    ++$checked;
                }
            }
        }
        self::assertGreaterThan(60, $checked, 'the sentences were read');
    }
}
