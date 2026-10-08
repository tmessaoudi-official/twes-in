<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * A printed amount carries its sign once. A template that writes a minus sign before a value prints two on a credit
 * note, whose amounts are already negative ("−-15,126", row 29): a subtracted amount goes through the `deduction`
 * filter, which writes the sign from the value itself. This reads the templates; `DecimalExtensionTest` checks what the
 * filter writes.
 */
final class PdfTemplateSignsTest extends TestCase
{
    public function testNoTemplateWritesAMinusSignBeforeAPrintedValue(): void
    {
        $templates = glob(\dirname(__DIR__, 4).'/templates/pdf/*.html.twig') ?: [];
        self::assertGreaterThanOrEqual(2, \count($templates), 'the delivery note and invoice layouts are found, so an empty set cannot pass');

        foreach ($templates as $template) {
            $source = (string) file_get_contents($template);
            self::assertDoesNotMatchRegularExpression('/[−-]\s*\{\{/u', $source, basename($template).' writes a sign before a value');
        }
    }

    /**
     * Every figure and every day a printed document writes follows the company's number and date format (docs/SPEC.md
     * § 7, 2026-09-25 12:45, row 130): a filter call left on the language alone would print one amount another way.
     */
    public function testEveryPrintedFigureAndDayFollowsTheCompanysFormats(): void
    {
        $templates = glob(\dirname(__DIR__, 4).'/templates/pdf/*.html.twig') ?: [];
        $figures = 0;
        foreach ($templates as $template) {
            $source = (string) file_get_contents($template);
            $figures += preg_match_all('/\|(?:decimal|deduction)\(/', $source);
            self::assertSame(0, preg_match_all('/\|(?:decimal|deduction)\([^)]*\blocale\)/', $source), basename($template).' formats a figure by the language alone');
            self::assertSame(0, preg_match_all('/\|date\((?!dateFormat\b)/', $source), basename($template).' writes a day in a format of its own');
            // A partial (`_name`) writes no day of its own: the document it is drawn into sets the pattern.
            if (!str_starts_with(basename($template), '_')) {
                self::assertStringContainsString("date_pattern(page.dateFormat, 'format.date'|trans({}, t, locale))", $source, basename($template));
            }
        }
        self::assertGreaterThanOrEqual(20, $figures, 'both layouts (25 figures on 2026-09-26)\' figures are found, so an empty set cannot pass');
    }

    /**
     * Written out, the amount is the document's total with its taxes, while the bold last row is what remains to pay after
     * a withholding: the closing sentence (« Arrêtée la présente facture à la somme de … », as ruled) names that it spells
     * the total with tax, or a reader takes the two for a disagreement. A credit note closes with a sentence of its own.
     */
    public function testTheAmountInWordsNamesTheTotalItSpellsOnAnInvoiceAndACreditNote(): void
    {
        $dir = \dirname(__DIR__, 4).'/translations';
        foreach ([
            'fr' => ['TTC', 'Arrêtée la présente facture à la somme', 'Arrêté le présent avoir à la somme'],
            'en' => ['tax included', 'This invoice is closed at the sum', 'This credit note is closed at the sum'],
        ] as $language => [$withTax, $invoice, $creditNote]) {
            $messages = Yaml::parseFile($dir.'/pdf.'.$language.'.yaml');
            self::assertIsArray($messages);
            self::assertIsArray($messages['totals'] ?? null);
            foreach (['in_words' => $invoice, 'in_words_credit_note' => $creditNote] as $key => $opening) {
                $label = $messages['totals'][$key] ?? null;
                self::assertIsString($label);
                self::assertStringStartsWith($opening, $label, $language.' '.$key);
                self::assertStringContainsString($withTax, $label, 'the '.$language.' sentence says it spells the total with tax');
            }
        }
    }

    public function testTheInvoiceDeductsItsDiscountAndItsWithholdingsThroughTheFilter(): void
    {
        $source = (string) file_get_contents(\dirname(__DIR__, 4).'/templates/pdf/invoice.html.twig');

        self::assertStringContainsString('figures.documentDiscount|deduction(', $source);
        self::assertStringContainsString('withholding.amount|deduction(', $source);
    }
}
