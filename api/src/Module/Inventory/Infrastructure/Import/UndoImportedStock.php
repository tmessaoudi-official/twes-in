<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Infrastructure\Import;

use App\ImportExport\Application\Undo\ImportedStock;
use App\ImportExport\Application\Undo\ImportNotRestored;
use App\Module\Inventory\Application\RaiseStockAlerts;
use App\Module\Inventory\Domain\Model\StockMovement;
use App\Module\Inventory\Domain\Repository\StockMovementRepository;
use App\Shared\Application\LiveChange;
use App\Shared\Application\LiveChanges;
use App\Tenancy\Domain\Model\Company;
use BcMath\Number;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * The stock an import wrote, taken back by a movement of its own the other way, worth what it came in at, and given
 * again the same way when the bin restores the undo. A count taken there since, or goods gone out so that taking it
 * back would leave less than nothing, stand on it: it stays, and the answer says why. Runs inside the undo's or the
 * restore's transaction, holding each product's stock at each place, in one order, as every writer of stock does.
 */
final readonly class UndoImportedStock implements ImportedStock
{
    public function __construct(
        private StockMovementRepository $movements,
        private ClockInterface $clock,
        private LiveChanges $liveChanges,
        private RaiseStockAlerts $alerts,
    ) {
    }

    public function takeBack(Company $company, Uuid $runId, Uuid $undoId, ?Uuid $actorUserId): array
    {
        $takenBack = $kept = $reversals = [];
        foreach ($this->held($this->movements->ofImportRun($runId, $company->getId())) as $movement) {
            if (0 === new Number($movement->getQuantity())->compare(0)) {
                continue;
            }
            $reason = $this->standingOn($movement, $movement->getAt(), $runId, $movement->getQuantity());
            if (null !== $reason) {
                $kept[] = ['reference' => $movement->getProduct()->getReference(), 'location' => $movement->getLocation()->getCode(), 'reason' => $reason];
                continue;
            }
            $reversals[] = StockMovement::reversal($movement, StockMovement::SOURCE_IMPORT_UNDO, self::sourceOf($undoId, $movement), $actorUserId, $this->clock->now());
            $takenBack[] = ['reference' => $movement->getProduct()->getReference(), 'location' => $movement->getLocation()->getCode(), 'quantity' => new Number($movement->getQuantity())->round($movement->getProduct()->getUnit()->getDecimals())->value];
        }
        $this->saved($company, $reversals, $actorUserId, 'stock.import_undone');

        return ['takenBack' => $takenBack, 'kept' => $kept];
    }

    public function putBack(Company $company, Uuid $runId, Uuid $undoId, ?Uuid $actorUserId): void
    {
        $taken = [];
        foreach ($this->movements->ofImportRun($runId, $company->getId()) as $movement) {
            array_push($taken, ...$this->movements->ofSource(StockMovement::SOURCE_IMPORT_UNDO, self::sourceOf($undoId, $movement), $company->getId()));
        }
        $again = [];
        foreach ($this->held($taken) as $reversal) {
            $reason = $this->standingOn($reversal, $reversal->getAt(), $runId, $reversal->getQuantity());
            if (null !== $reason) {
                throw new ImportNotRestored($reason, \sprintf('The stock of %s at %s moved since the import was undone: it is not put back.', $reversal->getProduct()->getReference(), $reversal->getLocation()->getCode()), ['reference' => $reversal->getProduct()->getReference(), 'location' => $reversal->getLocation()->getCode()]);
            }
            $again[] = StockMovement::reversal($reversal, StockMovement::SOURCE_IMPORT_REDO, $reversal->getSourceId() ?? throw new \LogicException('An undo\'s movement names its source.'), $actorUserId, $this->clock->now());
        }
        $this->saved($company, $again, $actorUserId, 'stock.import_restored');
    }

    /**
     * What stands on a movement taken back: a count since, which replaced what was there, or goods gone out since, so
     * that taking it back leaves less than nothing.
     *
     * @param numeric-string $quantity what the movement moved, signed
     */
    private function standingOn(StockMovement $movement, \DateTimeImmutable $at, Uuid $runId, string $quantity): ?string
    {
        if ($this->movements->countedSince($movement->getProduct()->getId(), $movement->getLocation()->getId(), $at, $runId)) {
            return 'counted_since';
        }
        $left = new Number($this->movements->onHand($movement->getProduct()->getId(), $movement->getLocation()->getId(), $movement->getLot()?->getId()))->sub($quantity);

        return -1 === $left->compare(0) ? 'below_zero' : null;
    }

    /**
     * The movements, each product's stock at each place held until the transaction ends, taken in one order.
     *
     * @param list<StockMovement> $movements
     *
     * @return list<StockMovement>
     */
    private function held(array $movements): array
    {
        usort($movements, static fn (StockMovement $a, StockMovement $b): int => [$a->getProduct()->getId()->toRfc4122(), $a->getLocation()->getId()->toRfc4122(), $a->getId()->toRfc4122()] <=> [$b->getProduct()->getId()->toRfc4122(), $b->getLocation()->getId()->toRfc4122(), $b->getId()->toRfc4122()]);
        $locked = [];
        foreach ($movements as $movement) {
            $key = $movement->getProduct()->getId()->toRfc4122().' '.$movement->getLocation()->getId()->toRfc4122();
            if (!isset($locked[$key])) {
                $this->movements->lockStockOf($movement->getProduct()->getId(), $movement->getLocation()->getId());
                $locked[$key] = true;
            }
        }

        return $movements;
    }

    /** @param list<StockMovement> $movements */
    private function saved(Company $company, array $movements, ?Uuid $actorUserId, string $action): void
    {
        if ([] === $movements) {
            return;
        }
        $this->movements->save(...$movements);
        $products = [];
        foreach ($movements as $movement) {
            $products[$movement->getProduct()->getId()->toRfc4122()] = $movement->getProduct()->getId();
        }
        foreach ($products as $productId) {
            $this->liveChanges->stage(new LiveChange('stock', $productId, $action, $actorUserId, $company->getId()));
        }
        $this->alerts->raiseFalls($movements);
    }

    /** One movement of the undo per movement of the import, so two of one product at one place stay two. */
    private static function sourceOf(Uuid $undoId, StockMovement $movement): Uuid
    {
        return Uuid::v5($undoId, $movement->getId()->toRfc4122());
    }
}
