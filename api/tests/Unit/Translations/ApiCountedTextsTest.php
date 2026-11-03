<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Translations;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\Loader\YamlFileLoader;
use Symfony\Component\Translation\Translator;
use Symfony\Component\Yaml\Yaml;

/**
 * A count a mail or a printed document states takes its noun's form from the count: « 1 heure », never « 1 heures ».
 * Each such text gives its forms in Symfony's pipe form (« …%hours% heure…|…%hours% heures… »), kept in its domain's
 * file so « Textes » still finds it, and the code hands the number as `%count%`, which alone chooses the form. Arabic
 * names the count after its label (« عدد الأيام: %days% »), so no noun has to agree with it, and gives one form.
 */
final class ApiCountedTextsTest extends TestCase
{
    private const string TRANSLATIONS = __DIR__.'/../../../translations';

    /** A word that counts time after a number, in the languages whose nouns agree with it. */
    private const array COUNTED = [
        'fr' => 'secondes?|minutes?|heures?|jours?|semaines?',
        'en' => 'seconds?|minutes?|hours?|days?|weeks?',
    ];

    public function testEveryTextCountingTimeGivesItsSingularAndItsPlural(): void
    {
        $bare = [];
        $found = 0;
        foreach (self::COUNTED as $language => $words) {
            foreach (glob(self::TRANSLATIONS.'/*.'.$language.'.yaml') ?: [] as $file) {
                foreach (self::texts($file) as $key => $text) {
                    if (1 !== preg_match('/%\w+%[\s\x{a0}\x{202f}]+(?:'.$words.')\b/u', $text)) {
                        continue;
                    }
                    ++$found;
                    if (!str_contains($text, '|')) {
                        $bare[] = basename($file).": $key";
                    }
                }
            }
        }
        // Signup, reset, deletion, the two reminders, the late fee's line and the payment terms, in French and English.
        self::assertGreaterThanOrEqual(14, $found, 'the texts counting time are no longer found: the sweep reads nothing');
        self::assertSame([], $bare, 'A count of time states its noun in the form the count takes: give « singular|plural ».');
    }

    /**
     * French and English have two forms, and Symfony throws when the count's form is missing from a text giving several, so
     * a mail would fail to be written: a text with forms gives exactly two. Arabic names the count after its label and
     * gives one, since its six forms would each have to be written.
     */
    public function testATextWithFormsGivesExactlyItsLanguagesForms(): void
    {
        $wrong = [];
        foreach (['fr' => 2, 'en' => 2, 'ar' => 1] as $language => $forms) {
            foreach (glob(self::TRANSLATIONS.'/*.'.$language.'.yaml') ?: [] as $file) {
                foreach (self::texts($file) as $key => $text) {
                    $given = \count(preg_split('/(?<!\|)\|(?!\|)/', $text) ?: []);
                    if ($given > 1 && $given !== $forms) {
                        $wrong[] = basename($file).": $key gives $given forms";
                    }
                }
            }
        }
        self::assertSame([], $wrong, 'French and English give « singular|plural »; Arabic gives one form.');
    }

    /** @return iterable<string, array{string, string, string, array<string, int|string>, string}> */
    public static function counted(): iterable
    {
        foreach ([0 => 'heure', 1 => 'heure', 2 => 'heures', 24 => 'heures'] as $count => $noun) {
            yield "a signup link of $count hours in French" => ['emails', 'signup.expires', 'fr', ['%hours%' => $count, '%count%' => $count], "valable $count $noun et"];
        }
        foreach ([0 => 'hours', 1 => 'hour', 2 => 'hours', 24 => 'hours'] as $count => $noun) {
            yield "a signup link of $count hours in English" => ['emails', 'signup.expires', 'en', ['%hours%' => $count, '%count%' => $count], "valid for $count $noun and"];
        }
        yield 'a reset link of one minute in French' => ['emails', 'password_reset.expires', 'fr', ['%minutes%' => 1, '%count%' => 1], 'valable 1 minute et'];
        yield 'a reset link of 60 minutes in French' => ['emails', 'password_reset.expires', 'fr', ['%minutes%' => 60, '%count%' => 60], 'valable 60 minutes et'];
        yield 'a reset link of one minute in English' => ['emails', 'password_reset.expires', 'en', ['%minutes%' => 1, '%count%' => 1], 'valid for 1 minute and'];
        yield 'a deletion final in one day in French' => ['emails', 'account_deletion.final', 'fr', ['%days%' => 1, '%count%' => 1, '%date%' => 'x'], 'dans 1 jour, le x'];
        yield 'a deletion final in 30 days in French' => ['emails', 'account_deletion.final', 'fr', ['%days%' => 30, '%count%' => 30, '%date%' => 'x'], 'dans 30 jours, le x'];
        yield 'a deletion final in one day in English' => ['emails', 'account_deletion.final', 'en', ['%days%' => 1, '%count%' => 1, '%date%' => 'x'], 'in 1 day, on x'];
        $late = ['%stage%' => 1, '%number%' => 'F-7', '%customer%' => 'Atlas', '%amount_due%' => '1', '%currency%' => 'TND', '%late_fee%' => '1'];
        yield 'a reminder one day late in French' => ['emails', 'notification.types.invoice_reminder_due', 'fr', $late + ['%days_late%' => '1', '%count%' => 1], 'a 1 jour de retard.'];
        yield 'a reminder one day late in English' => ['emails', 'notification.types.invoice_reminder_due', 'en', $late + ['%days_late%' => '1', '%count%' => 1], 'is 1 day late.'];
        yield 'a late fee drafted one day late in French' => ['emails', 'notification.types.invoice_late_fee_drafted', 'fr', $late + ['%days_late%' => '1', '%count%' => 1], 'a 1 jour de retard. Frais'];
        yield 'a late fee drafted 12 days late in English' => ['emails', 'notification.types.invoice_late_fee_drafted', 'en', $late + ['%days_late%' => '12', '%count%' => 12], 'is 12 days late. A late'];
        yield 'a late fee line one day late in French' => ['pdf', 'invoice.late_fee_line', 'fr', ['%number%' => 'F-7', '%stage%' => 1, '%days%' => 1, '%count%' => 1], '(1 jour de retard)'];
        yield 'a late fee line one day late in English' => ['pdf', 'invoice.late_fee_line', 'en', ['%number%' => 'F-7', '%stage%' => 1, '%days%' => 1, '%count%' => 1], '(1 day late)'];
        yield 'payment terms of one day in French' => ['pdf', 'invoice.payment_terms', 'fr', ['%days%' => 1, '%count%' => 1], 'À 1 jour'];
        yield 'payment terms of 30 days in French' => ['pdf', 'invoice.payment_terms', 'fr', ['%days%' => 30, '%count%' => 30], 'À 30 jours'];
        yield 'payment terms of one day in English' => ['pdf', 'invoice.payment_terms', 'en', ['%days%' => 1, '%count%' => 1], 'Within 1 day'];
        yield 'payment terms of 30 days in Arabic' => ['pdf', 'invoice.payment_terms', 'ar', ['%days%' => 30, '%count%' => 30], 'الأجل بالأيام: 30'];
        yield 'payment terms of 3 days in Arabic' => ['pdf', 'invoice.payment_terms', 'ar', ['%days%' => 3, '%count%' => 3], 'الأجل بالأيام: 3'];
        yield 'a signup link of 2 hours in Arabic' => ['emails', 'signup.expires', 'ar', ['%hours%' => 2, '%count%' => 2], 'بالساعات: 2.'];
    }

    /** @param array<string, int|string> $parameters */
    #[DataProvider('counted')]
    public function testTheCountChoosesItsForm(string $domain, string $key, string $language, array $parameters, string $said): void
    {
        $translator = new Translator($language);
        $translator->addLoader('yaml', new YamlFileLoader());
        $translator->addResource('yaml', self::TRANSLATIONS."/$domain.$language.yaml", $language, $domain);

        $text = $translator->trans($key, $parameters, $domain, $language);

        self::assertStringContainsString($said, $text);
        self::assertStringNotContainsString('|', $text);
    }

    /**
     * A text with forms chooses one only when handed `%count%`; without it Symfony writes every form, pipes and all. Each
     * place a template or a class names such a text hands it, but the notifications, whose kind names its count
     * (`NotificationKind::$count`) and whose mailer hands it.
     */
    public function testEveryPlaceWritingATextWithFormsHandsItsCount(): void
    {
        $withForms = [];
        foreach (glob(self::TRANSLATIONS.'/*.fr.yaml') ?: [] as $file) {
            foreach (self::texts($file) as $key => $text) {
                if (str_contains($text, '|') && !str_starts_with($key, 'notification.types.')) {
                    $withForms[] = $key;
                }
            }
        }
        self::assertGreaterThanOrEqual(5, \count($withForms), 'the texts with forms are no longer found');

        $sources = [];
        foreach (['templates', 'src'] as $root) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::TRANSLATIONS.'/../'.$root, \FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                if ($file instanceof \SplFileInfo && \in_array($file->getExtension(), ['php', 'twig'], true)) {
                    $sources[$file->getPathname()] = (string) file_get_contents($file->getPathname());
                }
            }
        }
        $countless = [];
        foreach ($withForms as $key) {
            $named = 0;
            foreach ($sources as $path => $source) {
                foreach (explode("\n", $source) as $n => $line) {
                    if (!str_contains($line, "'$key'")) {
                        continue;
                    }
                    ++$named;
                    if (!str_contains($line, "'%count%'")) {
                        $countless[] = basename($path).':'.($n + 1).": $key";
                    }
                }
            }
            if (0 === $named) {
                $countless[] = "$key is named nowhere, so no count is handed to it";
            }
        }
        self::assertSame([], $countless, 'Hand the number as %count% where a text with forms is written.');
    }

    /** @return array<string, string> every text of the file, by its dotted key */
    private static function texts(string $file): array
    {
        $flat = [];
        $walk = static function (mixed $node, string $prefix) use (&$walk, &$flat): void {
            if (\is_string($node)) {
                $flat[$prefix] = $node;
            } elseif (\is_array($node)) {
                foreach ($node as $key => $child) {
                    $walk($child, '' === $prefix ? (string) $key : $prefix.'.'.$key);
                }
            }
        };
        $walk(Yaml::parseFile($file), '');

        return $flat;
    }
}
