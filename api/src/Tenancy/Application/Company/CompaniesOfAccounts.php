<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Application\Company;

use App\Tenancy\Domain\MembershipRepository;
use Symfony\Component\Uid\Uuid;

/** The companies of a page of accounts, by company name, read once for the whole page: only an operator's endpoint reaches this. */
final readonly class CompaniesOfAccounts
{
    public function __construct(private MembershipRepository $memberships)
    {
    }

    /**
     * @param list<Uuid> $userIds
     *
     * @return array<string, list<AccountCompany>> by the account's RFC 4122 identifier; an account of no company is absent
     */
    public function of(array $userIds): array
    {
        $byUser = [];
        foreach ($this->memberships->ofUsers($userIds) as $membership) {
            $company = $membership->getCompany();
            $byUser[$membership->getUser()->getId()->toRfc4122()][] = new AccountCompany($company->getId()->toRfc4122(), $company->getName(), $membership->getRole()->getName());
        }
        foreach ($byUser as &$companies) {
            usort($companies, static fn (AccountCompany $a, AccountCompany $b): int => $a->name <=> $b->name);
        }

        return $byUser;
    }
}
