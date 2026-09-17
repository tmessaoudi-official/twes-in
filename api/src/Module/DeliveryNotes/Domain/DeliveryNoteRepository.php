<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\DeliveryNotes\Domain;

use App\Shared\Domain\Page;
use App\Shared\Domain\PageRequest;
use Symfony\Component\Uid\Uuid;

interface DeliveryNoteRepository
{
    /** @return list<DeliveryNote> one company's delivery notes, the newest first */
    public function ofCompany(Uuid $companyId): array;

    /**
     * One page of a company's delivery notes, searched, narrowed and sorted by the database.
     *
     * @return Page<DeliveryNote>
     */
    public function search(Uuid $companyId, DeliveryNoteSearch $search, PageRequest $page): Page;

    /** Null for a delivery note that does not exist or belongs to another company. */
    public function ofIdInCompany(Uuid $id, Uuid $companyId): ?DeliveryNote;

    /**
     * The company's delivery notes among these ids, their rows held until the transaction this runs in ends; outside a
     * transaction it refuses.
     *
     * @param list<Uuid> $ids
     *
     * @return list<DeliveryNote>
     */
    public function lockedOfIdsInCompany(array $ids, Uuid $companyId): array;

    /**
     * The same, for the company's delivery notes carrying any of these lines.
     *
     * @param list<Uuid> $lineIds
     *
     * @return list<DeliveryNote>
     */
    public function lockedOfLineIdsInCompany(array $lineIds, Uuid $companyId): array;

    /** Whether a delivery note of the company already carries this number. */
    public function numberTaken(Uuid $companyId, string $number): bool;

    public function save(DeliveryNote $note): void;
}
