<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\DeliveryNotes\Domain;

use Symfony\Component\Uid\Uuid;

interface DeliveryNoteRepository
{
    /** @return list<DeliveryNote> one company's delivery notes, the newest first */
    public function ofCompany(Uuid $companyId): array;

    /** Null for a delivery note that does not exist or belongs to another company. */
    public function ofIdInCompany(Uuid $id, Uuid $companyId): ?DeliveryNote;

    /** Whether a delivery note of the company already carries this number. */
    public function numberTaken(Uuid $companyId, string $number): bool;

    public function save(DeliveryNote $note): void;
}
