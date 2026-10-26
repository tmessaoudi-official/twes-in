<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Products\Application;

use App\Audit\Application\AuditEntry;
use App\Audit\Application\AuditTrail;
use App\Audit\Application\DeclaresAuditActions;
use App\Audit\Domain\ValueObject\AuditScope;
use App\Audit\Domain\ValueObject\Recorded;
use App\Module\Products\Domain\Model\Product;
use App\Module\Products\Domain\Repository\ProductRepository;
use App\Shared\Application\Transactions;
use App\Tenancy\Domain\Model\Company;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * A product whose stock was counted no longer awaits its count: « à compter » leaves it, audited by the field's name as
 * any revision is, so the screens that show it hear of it. Nothing is written for a product that awaited no count.
 */
final readonly class NoteProductCounted implements DeclaresAuditActions
{
    public function __construct(
        private ProductRepository $products,
        private AuditTrail $audit,
        private ClockInterface $clock,
        private Transactions $transactions,
    ) {
    }

    public function handle(Company $company, Product $product, ?Uuid $actorUserId): bool
    {
        if (!$product->awaitsCount()) {
            return false;
        }

        return $this->transactions->run(function () use ($company, $product, $actorUserId): bool {
            // Read again under the row lock: a person's save of this product since it was read is waited for and taken
            // in, where the unlocked object would be refused at the flush for a version saved in between.
            $product = $this->products->lockedOfIdInCompany($product->getId(), $company->getId()) ?? throw new ProductNotFound();
            if (!$product->counted($this->clock->now())) {
                return false;
            }
            $this->products->save($product);
            $this->audit->record(new AuditEntry(ManageProducts::ENTITY_TYPE, $product->getId(), ManageProducts::REVISED, $actorUserId, ['fields' => ['toCount']], $company->getId()));

            return true;
        });
    }

    public function auditActions(): array
    {
        return [
            ManageProducts::REVISED => new Recorded(ManageProducts::ENTITY_TYPE, AuditScope::Company),
        ];
    }
}
