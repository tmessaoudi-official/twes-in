<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Products\Application;

use App\Tenancy\Domain\Company;
use Symfony\Component\Uid\Uuid;

/**
 * A product's reorder point per establishment, as a product file writes it (docs/SPEC.md § 7, 2026-09-24 11:40). A port
 * answered by the inventory, as `ProductHomes` is, since stock depends on the catalogue and not the other way round.
 */
interface ProductReorderPoints
{
    /** The establishment a stock location is in; null for one the company does not have. */
    public function establishmentOfLocation(Company $company, Uuid $locationId): ?Uuid;

    /** @throws ReorderPointRefused when the quantity is not one the product's unit can count */
    public function setReorderPoint(Company $company, Uuid $productId, Uuid $establishmentId, string $quantity, ?Uuid $actorUserId): void;
}
