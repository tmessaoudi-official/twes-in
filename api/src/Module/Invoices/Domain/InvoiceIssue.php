<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Domain;

use Symfony\Component\Uid\Uuid;

/**
 * What issuing gives a draft besides its figures (docs/SPEC.md § 7, 2026-09-14): the number and day its series gave,
 * the terms and language its customer's settings say, the mentions and footer the company prints, and who issued it.
 */
final readonly class InvoiceIssue
{
    /** @param list<string> $mentionKeys translation keys, printed in the document's language */
    public function __construct(
        public string $number,
        public \DateTimeImmutable $issueDate,
        public int $paymentTermsDays,
        public string $language,
        public array $mentionKeys,
        public ?string $latePenaltyText,
        public ?string $footer,
        public ?Uuid $issuedBy,
    ) {
    }
}
