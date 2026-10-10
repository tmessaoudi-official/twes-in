<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Erasure\Application;

use App\Erasure\Domain\DataErasure;
use App\Erasure\Domain\DataErasureRepository;
use App\Identity\Application\StepUp\StepUpRequired;
use App\Tenancy\Domain\Company;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/** What the page counts under each part before anything goes, and the erasure the banner offers to undo. */
final readonly class ReadErasures
{
    public function __construct(
        private ErasureGate $gate,
        private ErasureCatalogue $catalogue,
        private ErasureStore $store,
        private DataErasureRepository $erasures,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Counted now, without touching anything.
     *
     * @return list<array{part: string, counts: array<string, int>}>
     *
     * @throws OwnerOnly
     * @throws StepUpRequired
     */
    public function preview(Company $company, Uuid $actorUserId): array
    {
        $this->gate->provedOwner($company, $actorUserId);
        $counted = $this->catalogue->shaped($this->store->count($company->getId(), $this->catalogue->stepsOf(ErasureCatalogue::PARTS), $this->catalogue->references()), ErasureCatalogue::PARTS);

        return array_map(static fn (string $part, array $counts): array => ['part' => $part, 'counts' => $counts], array_keys($counted), array_values($counted));
    }

    /**
     * The company's erasure that may still be undone, the banner's.
     *
     * @throws OwnerOnly
     */
    public function pending(Company $company, Uuid $actorUserId): ?DataErasure
    {
        $this->gate->owner($company, $actorUserId);
        $erasure = $this->erasures->pendingOf($company->getId());

        return null !== $erasure && $erasure->isUndoableAt($this->clock->now()) ? $erasure : null;
    }
}
