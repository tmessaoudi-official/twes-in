<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Integration\Records;

use App\Records\Application\RecordCatalogue;
use App\Records\Application\RecordUse;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * What a module declares deletable, the screens say in words: « Récemment supprimés » lists it, the journal says its
 * delete and its restore rather than the code the API recorded, and a refusal names each table that still uses it.
 * The declarations live in the API and the words in the web, so nothing on either side alone sees a pair left half
 * done; a subject added without its words reds here.
 */
final class RecordWordsTest extends KernelTestCase
{
    private const array LANGUAGES = ['fr', 'en', 'ar'];

    public function testEverySubjectIsListedAndSaidAndEveryUseIsNamed(): void
    {
        $catalogue = static::getContainer()->get(RecordCatalogue::class);
        $web = \dirname(__DIR__, 4).'/web';
        $deletes = self::read($web.'/src/app/shared/records/record-deletes.ts');
        $journal = self::read($web.'/src/app/activity/activity-list.ts');
        $deletable = self::listed($deletes, 'export const DELETABLE_SUBJECTS = [');
        $named = self::listed($deletes, 'const NAMED_TABLES = [');
        $actions = self::listed($journal, 'export const ACTIVITY_ACTIONS: readonly string[] = [');
        $kinds = self::listed($journal, 'export const ACTIVITY_KINDS = [');
        $words = [];
        foreach (self::LANGUAGES as $language) {
            $texts = json_decode(self::read($web.'/public/i18n/records/'.$language.'.json'), true, flags: \JSON_THROW_ON_ERROR);
            self::assertIsArray($texts, $language);
            $words[$language] = $texts;
        }

        self::assertNotEmpty($catalogue->subjects());
        self::assertContains('record_removal.ended', $actions);
        self::assertContains('record_removal', $kinds);
        foreach (array_keys($catalogue->subjects()) as $table) {
            self::assertContains($table, $deletable, "$table is missing from DELETABLE_SUBJECTS");
            self::assertContains($table, $kinds, "$table is missing from ACTIVITY_KINDS");
            foreach (['deleted', 'restored', 'merged', 'merge_undone'] as $verb) {
                self::assertContains("$table.$verb", $actions, "$table.$verb is missing from ACTIVITY_ACTIONS");
            }
            foreach (self::LANGUAGES as $language) {
                self::assertIsString(self::at($words[$language], "records.recent.subjects.$table"), "records.recent.subjects.$table in $language");
            }
        }
        foreach ($catalogue->references() as $reference) {
            if (RecordUse::Uses !== $reference->onDelete) {
                continue;
            }
            self::assertContains($reference->table, $named, "{$reference->table} is missing from NAMED_TABLES");
            foreach (self::LANGUAGES as $language) {
                self::assertIsString(self::at($words[$language], "records.tables.{$reference->table}"), "records.tables.{$reference->table} in $language");
            }
        }
    }

    private static function read(string $path): string
    {
        $content = file_get_contents($path);
        self::assertIsString($content, $path);

        return $content;
    }

    /** @return list<string> the quoted strings of the array literal opened by $opening */
    private static function listed(string $source, string $opening): array
    {
        $start = strpos($source, $opening);
        self::assertIsInt($start, $opening);
        $end = strpos($source, ']', $start + \strlen($opening));
        self::assertIsInt($end, $opening);
        preg_match_all("/'([a-z_.]+)'/", substr($source, $start, $end - $start), $matches);
        self::assertNotEmpty($matches[1], $opening);

        return $matches[1];
    }

    /** @param array<mixed> $tree */
    private static function at(array $tree, string $key): mixed
    {
        $node = $tree;
        foreach (explode('.', $key) as $part) {
            if (!\is_array($node) || !\array_key_exists($part, $node)) {
                return null;
            }
            $node = $node[$part];
        }

        return $node;
    }
}
