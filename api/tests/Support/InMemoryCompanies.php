<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Support;

use App\Shared\Domain\ValueObject\Page;
use App\Shared\Domain\ValueObject\PageRequest;
use App\Tenancy\Domain\Model\Company;
use App\Tenancy\Domain\Repository\CompanyRepository;
use App\Tenancy\Domain\ValueObject\CompanySearch;
use Symfony\Component\Uid\Uuid;

final class InMemoryCompanies implements CompanyRepository
{
    /** @var list<Company> */
    public array $companies = [];

    public function ofId(Uuid $id): ?Company
    {
        foreach ($this->companies as $company) {
            if ($company->getId()->equals($id)) {
                return $company;
            }
        }

        return null;
    }

    public function lockedOfId(Uuid $id): ?Company
    {
        return $this->ofId($id);
    }

    public function ofName(string $name): ?Company
    {
        foreach ($this->companies as $company) {
            if ($company->getName() === $name) {
                return $company;
            }
        }

        return null;
    }

    public function all(): array
    {
        return $this->companies;
    }

    /** Narrows on the name only: the owners' addresses live in memberships, which this fake does not hold. */
    public function search(CompanySearch $search, PageRequest $page): Page
    {
        // As the repository asks it: a suspended company never opened is « refused », and « suspended » leaves it out.
        $statusOf = static fn (Company $company): string => Company::STATUS_SUSPENDED === $company->getStatus() && !$company->wasOpened() ? CompanySearch::REFUSED : $company->getStatus();
        $found = array_values(array_filter($this->companies, static fn (Company $company): bool => ([] === $search->statuses || \in_array($statusOf($company), $search->statuses, true))
            && ([] === $search->countryCodes || \in_array($company->getCountryCode(), $search->countryCodes, true))
            && (null === $search->text || '' === trim($search->text) || str_contains(mb_strtolower($company->getName()), mb_strtolower(trim($search->text))))
            && !\in_array($company->getId()->toRfc4122(), array_map(static fn (Uuid $id): string => $id->toRfc4122(), $search->leftOut), true)));
        usort($found, static fn (Company $a, Company $b): int => $a->getName() <=> $b->getName());

        return new Page(\array_slice($found, $page->offset(), $page->size), \count($found), $page);
    }

    public function save(Company $company): void
    {
        if (!\in_array($company, $this->companies, true)) {
            $this->companies[] = $company;
        }
    }

    public function closedDownKeptUntilBefore(\DateTimeImmutable $day): array
    {
        $due = array_values(array_filter($this->companies, static fn (Company $company): bool => $company->isClosedDown() && $company->getRetainUntil() < $day));
        usort($due, static fn (Company $a, Company $b): int => $a->getRetainUntil() <=> $b->getRetainUntil());

        return $due;
    }

    public function removeWhole(Company $company): void
    {
        $this->companies = array_values(array_filter($this->companies, static fn (Company $kept): bool => $kept !== $company));
    }
}
