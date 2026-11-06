<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Purchases\Domain\ValueObject;

use App\Shared\Domain\ValueObject\PrintSettings;

/**
 * How an order prints as it was sent: its language and the notes, formats and design every document keeps. Kept with
 * the order, so the vendor's copy prints the same whatever the company's settings become, as a sent quote does.
 */
final readonly class PurchaseOrderPrint
{
    public function __construct(
        public string $language,
        public PrintSettings $print,
    ) {
    }

    /** @return array<string, string|bool|null> */
    public function toArray(): array
    {
        return ['language' => $this->language] + $this->print->toArray();
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $language = $data['language'] ?? null;
        if (!\is_string($language)) {
            throw new \UnexpectedValueException('An order\'s print settings hold no language.');
        }

        return new self($language, PrintSettings::fromArray($data));
    }
}
