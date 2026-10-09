<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Products\Application;

/**
 * A quantity of a product file the stock refuses, with what it is about — `product`, `place`, `quantity` or `cost` —
 * so the file names the column, and a stable reason with its parameters for the screen to translate.
 */
final class ProductStockRefused extends \DomainException
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
