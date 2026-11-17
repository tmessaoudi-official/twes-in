<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Products\Infrastructure\Import;

use App\ImportExport\Application\Undo\PutsBackImportRemovals;
use App\Module\Products\Application\ManageProducts;
use App\Tenancy\Domain\Model\Company;
use Symfony\Component\Uid\Uuid;

/**
 * « Annuler cet import » for a file that replaced who its products are bought from: each vendor it dropped is linked
 * again with the codes it held, through the product's own rules (ManageProducts::putBackVendor), which say what stays.
 */
final readonly class PutBackDroppedVendors implements PutsBackImportRemovals
{
    public function __construct(private ManageProducts $manage)
    {
    }

    public function tables(): array
    {
        return [ProductVendorImport::DROPPED];
    }

    public function putBack(Company $company, array $removed, ?Uuid $actorUserId): array
    {
        $kept = [];
        foreach ($removed[ProductVendorImport::DROPPED] ?? [] as $row) {
            $what = $row['what'];
            if (!\is_string($what['product'] ?? null) || !\is_string($what['vendor'] ?? null) || !\is_string($what['number'] ?? null) || !\is_array($what['codes'] ?? null)) {
                throw new \LogicException('A vendor dropped by an import names its product, its vendor, its number and its codes.');
            }
            $codes = [];
            foreach ($what['codes'] as $code) {
                if (!\is_array($code) || !\is_string($code['role'] ?? null) || !\is_string($code['code'] ?? null) || !\is_int($code['quantity'] ?? null)) {
                    throw new \LogicException('A dropped vendor\'s code names its role, its code and its quantity.');
                }
                $codes[] = ['role' => $code['role'], 'code' => $code['code'], 'quantity' => $code['quantity']];
            }
            array_push($kept, ...$this->manage->putBackVendor($company, Uuid::fromString($what['product']), Uuid::fromString($what['vendor']), $what['number'], $codes, $actorUserId));
        }

        return $kept;
    }
}
