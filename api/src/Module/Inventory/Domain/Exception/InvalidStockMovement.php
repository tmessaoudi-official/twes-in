<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Domain\Exception;

/**
 * A stock movement refused by its own rules; names the field, and when the refusal has one, the stable reason a screen
 * or an import report translates and that text's parameters, as a product's refusal does.
 */
final class InvalidStockMovement extends \DomainException
{
    /** @param array<string, string|int> $params */
    public function __construct(
        public readonly string $field,
        string $message,
        ?\Throwable $previous = null,
        public readonly ?string $reason = null,
        public readonly array $params = [],
    ) {
        parent::__construct($message, 0, $previous);
    }
}
