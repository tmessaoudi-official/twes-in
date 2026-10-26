<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure;

use App\Module\DeliveryNotes\Domain\Exception\InvalidDeliveryNote;
use App\Module\Invoices\Domain\Exception\InvalidInvoice;
use App\Module\Purchases\Domain\Exception\InvalidPurchaseOrder;
use App\Module\Quotes\Domain\Exception\InvalidQuote;
use App\Shared\Infrastructure\ApiPlatform\Output\DomainRule;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A line's refusal is named again under the line it came from (`quantity` within `lines[2]`); the reason a screen
 * translates and its parameters go with it, or the screen falls back to « Valeur refusée. » for that line alone.
 */
final class DomainRefusalWithinTest extends TestCase
{
    /** @return iterable<string, array{class-string<InvalidInvoice|InvalidDeliveryNote|InvalidQuote|InvalidPurchaseOrder>}> */
    public static function refusals(): iterable
    {
        yield 'invoice' => [InvalidInvoice::class];
        yield 'delivery note' => [InvalidDeliveryNote::class];
        yield 'quote' => [InvalidQuote::class];
        yield 'purchase order' => [InvalidPurchaseOrder::class];
    }

    /** @param class-string<InvalidInvoice|InvalidDeliveryNote|InvalidQuote|InvalidPurchaseOrder> $refusal */
    #[DataProvider('refusals')]
    public function testARefusalNamedUnderItsLineKeepsItsReasonAndParameters(string $refusal): void
    {
        $within = new $refusal('quantity', 'At most 3 decimals.', 'too_many_decimals', ['max' => 3])->within('lines[2]');

        self::assertSame(['lines[2].quantity', 'At most 3 decimals.', 'too_many_decimals', ['max' => 3]], [$within->field, $within->getMessage(), $within->reason, $within->params]);

        $violation = DomainRule::refused($within->field, $within->getMessage(), $within->reason ?? 'invalid_value', $within->params)->getConstraintViolationList()->get(0);
        self::assertSame(['lines[2].quantity', 'too_many_decimals'], [$violation->getPropertyPath(), $violation->getCode()]);
    }
}
