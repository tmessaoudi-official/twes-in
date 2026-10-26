<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Integration\Audit;

use App\Audit\Application\AuditEntry;
use App\Audit\Application\AuditTrail;
use App\Audit\Application\AuditVocabulary;
use App\Audit\Infrastructure\UndeclaredAuditAction;
use App\Tests\Support\ShippedLanguages;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * A company's journal says every action it shows in words: an action recorded without them reached it as its raw name
 * (« subscription.payment_confirmed », « record_removal.final »), and a word kept for an action no longer recorded
 * hides that the action changed its name. What is recorded is declared in the API, its words live in the web, so
 * nothing on either side alone sees a pair left half done; both halves are read here, both ways.
 */
final class AuditActionWordsTest extends KernelTestCase
{
    public function testEveryActionAJournalShowsIsListedAndSaidAndNothingElseIs(): void
    {
        $vocabulary = static::getContainer()->get(AuditVocabulary::class);
        $web = \dirname(__DIR__, 4).'/web';
        $listed = self::listed(self::read($web.'/src/app/activity/activity-list.ts'), 'export const ACTIVITY_ACTIONS: readonly string[] = [');
        $shown = array_keys(array_filter($vocabulary->all(), static fn ($scope): bool => $scope->shownInAJournal()));

        // Above what any one context declares, so losing a context's declaration reds too.
        self::assertGreaterThanOrEqual(150, \count($shown));
        self::assertSame([], array_values(array_diff($shown, $listed)), 'recorded in a company, missing from ACTIVITY_ACTIONS');
        self::assertSame([], array_values(array_diff($listed, $shown)), 'in ACTIVITY_ACTIONS, recorded in no company');
        foreach (ShippedLanguages::catalogue()->interface() as $language) {
            $words = self::words($web, $language);
            $unsaid = array_values(array_filter($shown, static fn (string $action): bool => !\is_string(self::at($words, "activity.actions.$action"))));
            self::assertSame([], $unsaid, "activity.actions without words in $language");
        }
    }

    /** The journal names each row's kind of record and filters by it: every kind it can show, said, and no other. */
    public function testEveryKindAJournalShowsIsListedAndSaidAndNothingElseIs(): void
    {
        $vocabulary = static::getContainer()->get(AuditVocabulary::class);
        $web = \dirname(__DIR__, 4).'/web';
        $listed = self::listed(self::read($web.'/src/app/activity/activity-list.ts'), 'export const ACTIVITY_KINDS = [');
        $shown = $vocabulary->kindsShownInAJournal();

        self::assertGreaterThanOrEqual(45, \count($shown));
        self::assertSame([], array_values(array_diff($shown, $listed)), 'recorded on in a company, missing from ACTIVITY_KINDS');
        self::assertSame([], array_values(array_diff($listed, $shown)), 'in ACTIVITY_KINDS, recorded on in no company');
        foreach (ShippedLanguages::catalogue()->interface() as $language) {
            $words = self::words($web, $language);
            $unsaid = array_values(array_filter($shown, static fn (string $kind): bool => !\is_string(self::at($words, "activity.kinds.$kind"))));
            self::assertSame([], $unsaid, "activity.kinds without words in $language");
        }
    }

    public function testTheTrailRefusesAnActionNobodyDeclaresOrOneRecordedWhereOrOnWhatItIsNotDeclared(): void
    {
        $trail = static::getContainer()->get(AuditTrail::class);

        foreach ([
            new AuditEntry('nobody', null, 'nobody.declared', null),
            // Declared as an account's, never a company's.
            new AuditEntry('user', null, 'auth.login_failed', null, [], Uuid::v7()),
            // Declared as a company's, never outside one.
            new AuditEntry('invoice', null, 'invoice.issued', null),
            // Declared on an invoice, never on a customer.
            new AuditEntry('customer', null, 'invoice.issued', null, [], Uuid::v7()),
        ] as $entry) {
            try {
                $trail->record($entry);
                self::fail("{$entry->action} was recorded");
            } catch (UndeclaredAuditAction $refused) {
                self::assertStringContainsString("\"{$entry->action}\"", $refused->getMessage());
            }
        }
    }

    /** @return array<mixed> the language's screen texts, the journal's own part laid over its main file */
    private static function words(string $web, string $language): array
    {
        $words = json_decode(self::read("$web/public/i18n/$language.json"), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($words);
        $part = "$web/public/i18n/activity/$language.json";
        if (is_file($part)) {
            $own = json_decode(self::read($part), true, flags: \JSON_THROW_ON_ERROR);
            self::assertIsArray($own);
            $words = array_replace_recursive($words, $own);
        }

        return $words;
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
