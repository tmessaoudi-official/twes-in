<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Support;

use App\Module\DeliveryNotes\Domain\DeliveryNote;
use App\Module\DeliveryNotes\Domain\DeliveryNoteLine;
use App\Module\DeliveryNotes\Domain\DeliveryNoteRepository;
use App\Shared\Application\Transactions;
use Symfony\Component\Uid\Uuid;

final class InMemoryDeliveryNotes implements DeliveryNoteRepository
{
    /** @var list<DeliveryNote> */
    public array $notes = [];

    /** When given, a lock is refused outside its transaction, as the database refuses one. */
    public ?Transactions $transactions = null;

    /**
     * What an unlocked read hands out instead of the stored note: a copy read before another request committed. A
     * locked read waits for that commit and reads the row as it stands, as `FOR UPDATE` with a refresh does.
     *
     * @var array<string, DeliveryNote>
     */
    public array $staleReads = [];

    public function ofCompany(Uuid $companyId): array
    {
        $mine = array_values(array_filter($this->notes, static fn (DeliveryNote $n) => $n->getCompany()->getId()->equals($companyId)));
        usort($mine, static fn (DeliveryNote $a, DeliveryNote $b) => [$b->getCreatedAt(), $b->getId()->toRfc4122()] <=> [$a->getCreatedAt(), $a->getId()->toRfc4122()]);

        return $mine;
    }

    public function ofIdInCompany(Uuid $id, Uuid $companyId): ?DeliveryNote
    {
        $stale = $this->staleReads[$id->toRfc4122()] ?? null;
        if (null !== $stale && $stale->getCompany()->getId()->equals($companyId)) {
            return $stale;
        }

        foreach ($this->ofCompany($companyId) as $note) {
            if ($note->getId()->equals($id)) {
                return $note;
            }
        }

        return null;
    }

    public function lockedOfIdsInCompany(array $ids, Uuid $companyId): array
    {
        $this->assertLockable();
        $wanted = array_map(static fn (Uuid $id): string => $id->toRfc4122(), $ids);

        return array_values(array_filter($this->ofCompany($companyId), static fn (DeliveryNote $note): bool => \in_array($note->getId()->toRfc4122(), $wanted, true)));
    }

    public function lockedOfLineIdsInCompany(array $lineIds, Uuid $companyId): array
    {
        $this->assertLockable();
        $wanted = array_map(static fn (Uuid $id): string => $id->toRfc4122(), $lineIds);

        return array_values(array_filter($this->ofCompany($companyId), static fn (DeliveryNote $note): bool => [] !== array_filter($note->getLines(), static fn (DeliveryNoteLine $line): bool => \in_array($line->getId()->toRfc4122(), $wanted, true))));
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

    private function assertLockable(): void
    {
        if (null !== $this->transactions && !$this->transactions->active()) {
            throw new \LogicException('A delivery note is locked inside a transaction.');
        }
    }
}
