<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Integration\Texts;

use App\Inbox\Application\NotificationKinds;
use App\Texts\Domain\Service\CountedTexts;
use App\Texts\Domain\ValueObject\TextAddress;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * « Textes » checks the forms of a text its code hands a count to, in every language, whatever its original says: the
 * list of those texts holds every place handing `%count%`, a template's or a class's own call and every notification
 * whose kind names its count, and nothing else, so a new count is never written unchecked and a text no count reaches
 * keeps its « | » as written.
 */
final class CountedTextsTest extends KernelTestCase
{
    private const string API = __DIR__.'/../../..';

    public function testTheCountedTextsAreExactlyThoseWhoseCodeHandsACount(): void
    {
        self::bootKernel();
        $handed = [];
        foreach (['templates', 'src'] as $root) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::API.'/'.$root, \FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                if (!$file instanceof \SplFileInfo || !\in_array($file->getExtension(), ['php', 'twig'], true)) {
                    continue;
                }
                foreach (explode("\n", (string) file_get_contents($file->getPathname())) as $line) {
                    // A template's `'key'|trans({… '%count%': …})` and a class's `trans('key', [… '%count%' => …])`.
                    preg_match_all("/'([a-z_]+(?:\.[a-z_]+)+)'\s*\|\s*trans\(\{[^}]*'%count%'|trans\('([a-z_]+(?:\.[a-z_]+)+)',\s*\[[^\]]*'%count%'/", $line, $found);
                    foreach ([...$found[1], ...$found[2]] as $key) {
                        if ('' !== $key) {
                            $handed[] = $key;
                        }
                    }
                }
            }
        }
        foreach (static::getContainer()->get(NotificationKinds::class)->all() as $kind) {
            if ($kind->mailed && null !== $kind->count) {
                $handed[] = 'notification.types.'.str_replace('.', '_', $kind->type);
            }
        }
        $handed = array_values(array_unique($handed));
        sort($handed);
        self::assertGreaterThanOrEqual(8, \count($handed), 'the places handing a count are no longer found: the sweep reads nothing');

        $declared = array_merge(...array_values(CountedTexts::KEYS));
        sort($declared);
        self::assertSame($handed, $declared, 'CountedTexts::KEYS names every text whose code hands %count%, and only those.');
    }

    public function testATextIsCountedInEveryLanguageAndOnlyInTheApisFiles(): void
    {
        foreach (['fr', 'en', 'ar'] as $language) {
            self::assertTrue(CountedTexts::counted(new TextAddress($language, 'pdf', 'invoice.payment_terms')));
            self::assertTrue(CountedTexts::counted(new TextAddress($language, 'emails', 'notification.types.data_check_failed')));
        }
        self::assertFalse(CountedTexts::counted(new TextAddress('fr', 'pdf', 'invoice.title')));
        self::assertFalse(CountedTexts::counted(new TextAddress('fr', 'screen', 'invoice.payment_terms')));
    }
}
