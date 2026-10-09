<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Integration\Inbox;

use App\Inbox\Application\NotificationKinds;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Translation\TranslatorBagInterface;

/**
 * Every kind a context declares as mailed has its mail's words in every language the API writes: a subject (its short
 * name) and the sentence that tells it. The parity test keeps the two files' keys equal; this one keeps the declarations
 * and the files together, so a new kind cannot reach a person's inbox as a raw key.
 */
final class NotificationMailTextsTest extends KernelTestCase
{
    /** Below this, the kinds were not found at all, which would make the check pass on nothing. */
    private const int MAILED_AT_LEAST = 15;

    public function testEveryMailedKindHasItsSubjectAndItsSentenceInFrenchAndEnglish(): void
    {
        self::bootKernel();
        $kinds = static::getContainer()->get(NotificationKinds::class);
        $translator = static::getContainer()->get('translator');
        self::assertInstanceOf(TranslatorBagInterface::class, $translator);

        $mailed = array_values(array_filter($kinds->all(), static fn ($kind): bool => $kind->mailed));
        self::assertGreaterThanOrEqual(self::MAILED_AT_LEAST, \count($mailed));

        $missing = [];
        foreach (['fr', 'en'] as $language) {
            $catalogue = $translator->getCatalogue($language);
            foreach ($mailed as $kind) {
                $key = str_replace('.', '_', $kind->type);
                foreach (["notification.kinds.$key", "notification.types.$key"] as $id) {
                    if (!$catalogue->defines($id, 'emails')) {
                        $missing[] = "$language $id";
                    }
                }
            }
        }
        self::assertSame([], $missing);
    }
}
