<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\ImportExport\Application\Undo;

use App\Tenancy\Domain\Model\Company;
use Symfony\Component\Uid\Uuid;

/**
 * The goods an import brought, which its undo takes back: never by erasing a movement, which stands once written, but
 * by one of its own the other way, at what the goods came in at. Answered by the stock's own module.
 */
interface ImportedStock
{
    /**
     * Takes back every movement the import wrote that still stands, where nothing has come to stand on it since: a count
     * taken there since, or goods gone out so that taking it back would leave less than nothing, keep it.
     *
     * @return array{takenBack: list<array{reference: string, location: string, quantity: string}>, kept: list<array{reference: string, location: string, reason: string}>}
     */
    public function takeBack(Company $company, Uuid $runId, Uuid $undoId, ?Uuid $actorUserId): array;

    /**
     * Puts back what the undo named by `$undoId` took back of the import's stock, once the bin restores it.
     *
     * @throws ImportNotRestored when what came since stands in its way
     */
    public function putBack(Company $company, Uuid $runId, Uuid $undoId, ?Uuid $actorUserId): void;
}
