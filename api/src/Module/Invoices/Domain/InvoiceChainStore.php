<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Domain;

use Symfony\Component\Uid\Uuid;

/** Where the issued documents' chains are read (`InvoiceChain`), a slice at a time, since a series may hold many. */
interface InvoiceChainStore
{
    /**
     * Every series with an issued document, of one company or of all.
     *
     * @return list<ChainSeries>
     */
    public function series(?Uuid $companyId, bool $unsealedOnly = false): array;

    /** The last document of the series' chain; null while nothing in it is sealed. */
    public function head(ChainSeries $series): ?Invoice;

    /**
     * Issued documents not sealed yet, in the order they were issued.
     *
     * @return list<Invoice>
     */
    public function unsealed(ChainSeries $series, int $limit): array;

    /**
     * Sealed documents after a place of the chain, in its order.
     *
     * @return list<Invoice>
     */
    public function sealed(ChainSeries $series, int $afterPosition, int $limit): array;

    /** Writes what was sealed. */
    public function write(): void;

    /** Lets go of the documents read so far, between slices of a long walk. */
    public function forget(): void;
}
