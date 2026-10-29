<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Application\ShortSales;

/**
 * A sale refused for the stock it would take: each short line, and whether the seller may confirm it instead, in which
 * case the same request confirmed goes through. Each document's module answers it on its lines' quantities.
 */
final class ShortOfStock extends \DomainException
{
    public const string REFUSED = 'stock_short';
    public const string TO_CONFIRM = 'stock_short_to_confirm';

    /** @param non-empty-list<ShortLine> $lines */
    public function __construct(public readonly array $lines, public readonly bool $confirmable)
    {
        parent::__construct($confirmable
            ? 'This sale takes more than the stock recorded: confirm it to go on.'
            : 'This sale takes more than the stock recorded: count the stock or receive the goods first.');
    }

    public function reason(): string
    {
        return $this->confirmable ? self::TO_CONFIRM : self::REFUSED;
    }
}
