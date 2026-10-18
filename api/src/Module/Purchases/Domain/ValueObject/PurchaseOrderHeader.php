<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Purchases\Domain\ValueObject;

use App\Module\Purchases\Domain\Exception\InvalidPurchaseOrder;

/**
 * What an order says besides its vendor and lines: the day the goods are expected, a remark printed for the vendor and
 * notes kept inside the company. Blank text is none.
 */
final readonly class PurchaseOrderHeader
{
    public const int TEXT_MAX = 5000;

    public ?string $remarksPrinted;
    public ?string $notesInternal;

    /** @throws InvalidPurchaseOrder */
    public function __construct(public ?\DateTimeImmutable $expectedOn = null, ?string $remarksPrinted = null, ?string $notesInternal = null)
    {
        $this->remarksPrinted = self::text('remarksPrinted', $remarksPrinted);
        $this->notesInternal = self::text('notesInternal', $notesInternal);
    }

    /** @return list<string> the fields this header says otherwise than `$other` */
    public function differencesFrom(self $other): array
    {
        $changed = [];
        if ($this->expectedOn?->format('Y-m-d') !== $other->expectedOn?->format('Y-m-d')) {
            $changed[] = 'expectedOn';
        }
        if ($this->remarksPrinted !== $other->remarksPrinted) {
            $changed[] = 'remarksPrinted';
        }
        if ($this->notesInternal !== $other->notesInternal) {
            $changed[] = 'notesInternal';
        }

        return $changed;
    }

    /** @throws InvalidPurchaseOrder */
    private static function text(string $field, ?string $value): ?string
    {
        $value = trim($value ?? '');
        if (mb_strlen($value) > self::TEXT_MAX) {
            throw new InvalidPurchaseOrder($field, \sprintf('At most %d characters.', self::TEXT_MAX));
        }

        return '' === $value ? null : $value;
    }
}
