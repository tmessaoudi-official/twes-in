<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Erasure\Application;

use App\Audit\Application\AuditEntry;
use App\Audit\Application\AuditTrail;
use App\Erasure\Domain\DataErasure;
use App\Erasure\Domain\DataErasureRepository;
use App\Erasure\Domain\ErasureNoLongerPending;
use App\Shared\Application\LiveChange;
use App\Shared\Application\LiveChanges;
use App\Shared\Application\Transactions;
use App\Tenancy\Domain\Company;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * « Annuler l'effacement »: everything the erasure took comes back in one go, as it was, until the erasure's end, and
 * not one second after, whether or not the worker has ended it yet. When something made since stands in the way, nothing
 * comes back and the erasure still waits, so the owner may clear the way and try again.
 */
final readonly class UndoErasure
{
    public const string UNDONE = 'data.erasure_undone';

    public function __construct(
        private ErasureGate $gate,
        private ErasureCatalogue $catalogue,
        private ErasureStore $store,
        private DataErasureRepository $erasures,
        private AuditTrail $audit,
        private LiveChanges $liveChanges,
        private Transactions $transactions,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws OwnerOnly
     * @throws ErasureNotFound
     * @throws ErasureNoLongerPending
     * @throws ErasureConflict
     */
    public function handle(Company $company, Uuid $actorUserId, Uuid $erasureId): DataErasure
    {
        $this->gate->owner($company, $actorUserId);

        return $this->transactions->run(function () use ($company, $actorUserId, $erasureId): DataErasure {
            $erasure = $this->erasures->lockedOfIdInCompany($erasureId, $company->getId()) ?? throw new ErasureNotFound('No erasure of this company has this id.');
            $now = $this->clock->now();
            if (!$erasure->isUndoableAt($now)) {
                throw new ErasureNoLongerPending('This erasure can no longer be undone.');
            }
            $this->store->restore($company->getId(), $erasure->getId(), $this->catalogue->references());
            $erasure->undo($now);
            $this->erasures->save($erasure);

            $this->audit->record(new AuditEntry(EraseData::ENTITY_TYPE, $erasure->getId(), self::UNDONE, $actorUserId, ['parts' => $erasure->getParts(), 'counts' => $erasure->getCounts()], $company->getId()));
            foreach ($this->catalogue->liveKindsOf($erasure->getParts()) as $kind) {
                $this->liveChanges->stage(new LiveChange($kind, null, self::UNDONE, $actorUserId, $company->getId()));
            }

            return $erasure;
        });
    }
}
