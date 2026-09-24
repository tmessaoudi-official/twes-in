<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure;

use PHPUnit\Framework\TestCase;

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

    public function testTheInvoiceDeductsItsDiscountAndItsWithholdingsThroughTheFilter(): void
    {
        $source = (string) file_get_contents(\dirname(__DIR__, 4).'/templates/pdf/invoice.html.twig');

        self::assertStringContainsString('figures.documentDiscount|deduction(', $source);
        self::assertStringContainsString('withholding.amount|deduction(', $source);
    }
}
