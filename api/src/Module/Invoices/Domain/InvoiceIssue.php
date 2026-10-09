<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Domain;

use App\Shared\Domain\PrintSettings;
use Symfony\Component\Uid\Uuid;

/**
 * What issuing gives a draft besides its figures (docs/SPEC.md § 7, 2026-09-14): the number and day its series gave,
 * the terms and language its customer's settings say, the mentions and footer the company prints, the notes and
 * formats it prints with, who issued it, and where its country's law asks, the category of its operations and whether the
 * company had opted to pay VAT on the débits.
 */
final readonly class InvoiceIssue
{
    /**
     * @param list<string>                         $mentionKeys       translation keys, printed in the document's language
     * @param array<string, array<string, string>> $mentionParameters what fills each mention's placeholders, by key
     */
    public function __construct(
        public string $number,
        public \DateTimeImmutable $issueDate,
        public int $paymentTermsDays,
        public string $language,
        public array $mentionKeys,
        public ?string $latePenaltyText,
        public ?string $footer,
        public ?Uuid $issuedBy,
        public PrintSettings $print,
        public array $mentionParameters = [],
        public ?OperationCategory $operationCategory = null,
        public ?bool $vatOnDebits = null,
    ) {
    }
}
