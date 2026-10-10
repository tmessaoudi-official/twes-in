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
use App\Identity\Application\StepUp\StepUpRequired;
use App\Shared\Application\LiveChange;
use App\Shared\Application\LiveChanges;
use App\Shared\Application\Transactions;
use App\Tenancy\Domain\Company;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;

/**
 * The owner takes parts of the company's data away for every member at once. What goes is copied as it goes, so that
 * it may all be put back until the erasure's end, and the activity journal says what went, counted, never the rows.
 */
final readonly class EraseData
{
    public const string ERASED = 'data.erased';
    public const string ENTITY_TYPE = 'data_erasure';

    public function __construct(
        private ErasureGate $gate,
        private ErasureCatalogue $catalogue,
        private ErasureStore $store,
        private DataErasureRepository $erasures,
        private EndDueErasures $ends,
        private AuditTrail $audit,
        private LiveChanges $liveChanges,
        private Transactions $transactions,
        private ClockInterface $clock,
        #[Autowire(param: 'app.erasure.undoable_for')]
        private string $undoableFor,
    ) {
    }

    /**
     * @throws OwnerOnly
     * @throws StepUpRequired
     * @throws NoPartChosen
     * @throws UnknownErasurePart
     * @throws ErasurePending
     */
    public function handle(Company $company, Uuid $actorUserId, mixed $parts): DataErasure
    {
        $this->gate->provedOwner($company, $actorUserId);
        $chosen = $this->catalogue->chosen($parts);
        // An erasure whose end came while the worker had not yet passed is ended first, so it no longer stands in the way.
        $this->ends->handle($company->getId());

        return $this->transactions->run(function () use ($company, $actorUserId, $chosen): DataErasure {
            $pending = $this->erasures->lockedPendingOf($company->getId());
            if (null !== $pending) {
                throw new ErasurePending($pending->getId());
            }
            // The record first: every row copied names it.
            $erasure = new DataErasure(Uuid::v7(), $company, $chosen, [], $actorUserId, $this->clock->now(), new \DateInterval($this->undoableFor));
            $this->erasures->save($erasure);
            $counts = $this->catalogue->shaped($this->store->erase($company->getId(), $erasure->getId(), $this->catalogue->stepsOf($chosen), $this->catalogue->references()), $chosen);
            $erasure->recordCounts($counts);
            $this->erasures->save($erasure);

            $this->audit->record(new AuditEntry(self::ENTITY_TYPE, $erasure->getId(), self::ERASED, $actorUserId, ['parts' => $chosen, 'counts' => $counts], $company->getId()));
            foreach ($this->catalogue->liveKindsOf($chosen) as $kind) {
                $this->liveChanges->stage(new LiveChange($kind, null, self::ERASED, $actorUserId, $company->getId()));
            }

            return $erasure;
        });
    }
}
