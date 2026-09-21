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
 * Where a product normally lives, as this module needs it (docs/SPEC.md row 101): a product file names a home, and
 * a product file is read here.
 *
 * A port rather than a call into the inventory, for the same reason `ProductStockHistory` is one: stock depends on
 * the catalogue and not the other way round, so what this module needs OF the inventory is declared here and
 * answered there. What is returned is deliberately unopinionated — identifiers and counts, no refusals — because
 * what a bad cell says to the person who wrote it belongs with the file's other refusals, not in another module.
 */
interface ProductHomes
{
    /**
     * Whether this company keeps homes at all — it does once it holds stock. A company that does not is never asked
     * for a shelf in its product file, and a file that names one anyway is refused for the column rather than having
     * the cell quietly dropped.
     */
    public function offered(Company $company): bool;

    /**
     * The company's stock locations under that code. A code is unique per ESTABLISHMENT, never per company, so this
     * answers none, one, or several — and several means the file cannot say which building was meant.
     *
     * @return list<Uuid>
     */
    public function locationsCoded(Company $company, string $code): array;

    /** Gives the product its home there, moving the one that establishment already had. */
    public function setHome(Company $company, Uuid $productId, Uuid $locationId, ?Uuid $actorUserId): void;
}
