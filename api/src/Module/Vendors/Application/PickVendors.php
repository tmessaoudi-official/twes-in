<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Vendors\Application;

use App\Module\Vendors\Domain\Vendor;
use App\Module\Vendors\Domain\VendorRepository;
use App\Tenancy\Domain\Company;
use Symfony\Component\Uid\Uuid;

/**
 * The few vendors a person means while typing in a form (docs/SPEC.md § 7, 2026-09-17, ruling 3), in the shape an
 * expense starts from: what the vendor is usually paid in days, and the category its expenses usually go to. The
 * expense form used to be handed every vendor of the company before it was filled in at all.
 */
final readonly class PickVendors
{
    /** What a picker shows at once: enough to recognise the right one, few enough to read (§ 7, 2026-09-17). */
    public const int SHOWN = 20;

    public function __construct(private VendorRepository $vendors)
    {
    }

    /**
     * @return list<array{id: string, number: string, name: string, paymentTermsDays: int|null, defaultExpenseCategoryId: string|null}>
     */
    public function matching(Company $company, string $words, int $limit = self::SHOWN): array
    {
        return array_map(self::row(...), $this->vendors->pick($company->getId(), $words, max(1, min($limit, self::SHOWN))));
    }

    /**
     * The same rows for vendors already named. A RETIRED vendor is answered here and left out of `matching`: an
     * expense recorded last year still names who it was paid to, but nobody records a new one against them.
     *
     * @param list<Uuid> $ids
     *
     * @return list<array{id: string, number: string, name: string, paymentTermsDays: int|null, defaultExpenseCategoryId: string|null}>
     */
    public function byIds(Company $company, array $ids): array
    {
        return array_map(self::row(...), $this->vendors->ofIdsInCompany(\array_slice($ids, 0, self::SHOWN), $company->getId()));
    }

    /** @return array{id: string, number: string, name: string, paymentTermsDays: int|null, defaultExpenseCategoryId: string|null} */
    private static function row(Vendor $vendor): array
    {
        $profile = $vendor->getProfile();

        return [
            'id' => $vendor->getId()->toRfc4122(),
            'number' => $vendor->getNumber(),
            'name' => $profile->name,
            'paymentTermsDays' => $profile->paymentTermsDays,
            'defaultExpenseCategoryId' => $profile->defaultExpenseCategoryId?->toRfc4122(),
        ];
    }
}
