<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Customers\Application;

use App\Fiscal\Application\Regime\ExcludedTaxFamilies;
use App\Fiscal\Domain\TaxFamily;
use App\Module\Customers\Domain\Customer;
use App\Module\Customers\Domain\CustomerRepository;
use App\Tenancy\Domain\Company;
use Symfony\Component\Uid\Uuid;

/**
 * The few customers a person means while typing in a form (docs/SPEC.md § 7, 2026-09-17, ruling 3), in the shape a
 * document header starts from: the tax families a document for it leaves out (its regime's and the company's own), its default discount and its
 * default taxes. A form used to be handed every active customer before it was filled in at all.
 */
final readonly class PickCustomers
{
    /** What a picker shows at once: enough to recognise the right one, few enough to read (§ 7, 2026-09-17). */
    public const int SHOWN = 20;

    public function __construct(private CustomerRepository $customers, private ExcludedTaxFamilies $excluded)
    {
    }

    /**
     * @return list<array{id: string, number: string, name: string, excludedFamilies: list<string>, defaultDiscountRate: string|null, defaultTaxComponentIds: list<string>}>
     */
    public function matching(Company $company, string $words, int $limit = self::SHOWN): array
    {
        return array_map(fn (Customer $customer): array => $this->row($company, $customer), $this->customers->pick($company->getId(), $words, max(1, min($limit, self::SHOWN))));
    }

    /**
     * The same rows, for customers already named — what a form opening a document needs to show who it is for and
     * which taxes that customer's regime allows. A DEACTIVATED customer is answered here and left out of `matching`:
     * a document written last year still names who it was for, but nobody starts a new one for them.
     *
     * @param list<Uuid> $ids
     *
     * @return list<array{id: string, number: string, name: string, excludedFamilies: list<string>, defaultDiscountRate: string|null, defaultTaxComponentIds: list<string>}>
     */
    public function byIds(Company $company, array $ids): array
    {
        return array_map(fn (Customer $customer): array => $this->row($company, $customer), $this->customers->ofIdsInCompany(\array_slice($ids, 0, self::SHOWN), $company->getId()));
    }

    /**
     * `excludedFamilies` is what a document for this customer leaves out: its regime's and the company's own (EXT-03).
     *
     * @return array{id: string, number: string, name: string, excludedFamilies: list<string>, defaultDiscountRate: string|null, defaultTaxComponentIds: list<string>}
     */
    private function row(Company $company, Customer $customer): array
    {
        return [
            'id' => $customer->getId()->toRfc4122(),
            'number' => $customer->getNumber(),
            'name' => $customer->getProfile()->name,
            'excludedFamilies' => array_map(static fn (TaxFamily $family): string => $family->value, $this->excluded->of($company, $customer->getTaxRegime())),
            'defaultDiscountRate' => $customer->getProfile()->defaultDiscountRate,
            'defaultTaxComponentIds' => $customer->getDefaultTaxComponentIds(),
        ];
    }
}
