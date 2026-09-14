<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Support;

use App\Module\DeliveryNotes\Domain\DeliveryNote;
use App\Module\DeliveryNotes\Domain\DeliveryNoteRepository;
use Symfony\Component\Uid\Uuid;

final class InMemoryDeliveryNotes implements DeliveryNoteRepository
{
    /** @var list<DeliveryNote> */
    public array $notes = [];

    public function ofCompany(Uuid $companyId): array
    {
        $mine = array_values(array_filter($this->notes, static fn (DeliveryNote $n) => $n->getCompany()->getId()->equals($companyId)));
        usort($mine, static fn (DeliveryNote $a, DeliveryNote $b) => [$b->getCreatedAt(), $b->getId()->toRfc4122()] <=> [$a->getCreatedAt(), $a->getId()->toRfc4122()]);

        return $mine;
    }

    public function ofIdInCompany(Uuid $id, Uuid $companyId): ?DeliveryNote
    {
        foreach ($this->ofCompany($companyId) as $note) {
            if ($note->getId()->equals($id)) {
                return $note;
            }
        }

        return null;
    }

    public function numberTaken(Uuid $companyId, string $number): bool
    {
        return [] !== array_filter($this->ofCompany($companyId), static fn (DeliveryNote $note): bool => $note->getNumber() === $number);
    }

    public function save(DeliveryNote $note): void
    {
        if (!\in_array($note, $this->notes, true)) {
            $this->notes[] = $note;
        }
    }
}
