<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Fiscal\Application\Preset;

/**
 * What a country's law adds to an invoice beyond what every invoice says, as its preset declares it: whether an invoice
 * states the category of its operations (goods, services or both), and the mention it prints when the company opted to
 * pay VAT on the débits, whose presence is also what offers a company that option. A preset that declares neither
 * leaves both out, so issuing there stores and prints neither.
 */
final readonly class PresetInvoiceFields
{
    public function __construct(
        public bool $operationCategory = false,
        public ?string $vatOnDebitsMentionKey = null,
    ) {
    }

    public function offersVatOnDebits(): bool
    {
        return null !== $this->vatOnDebitsMentionKey;
    }
}
