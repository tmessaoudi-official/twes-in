<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Domain;

use Symfony\Component\Uid\Uuid;

/** One chain of issued documents: a company's establishment and a document type, as one numbering series numbers them. */
final readonly class ChainSeries
{
    public function __construct(
        public Uuid $companyId,
        public Uuid $establishmentId,
        public InvoiceType $type,
    ) {
    }
}
