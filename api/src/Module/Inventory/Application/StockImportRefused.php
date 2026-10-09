<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Application;

/**
 * A quantity a file asks for that the stock refuses, with what it is about — `product`, `place` or `quantity` — so each
 * file names the column it wrote it in, and a stable reason with its parameters for the screen to translate.
 */
final class StockImportRefused extends \DomainException
{
    public const string PRODUCT = 'product';
    public const string PLACE = 'place';
    public const string QUANTITY = 'quantity';
    public const string COST = 'cost';

    /** @param array<string, string|int> $params */
    public function __construct(
        public readonly string $about,
        public readonly string $reason,
        string $message,
        public readonly array $params = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
