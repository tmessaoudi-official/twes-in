<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Purchases\Application\Receivers;

use App\Tenancy\Domain\Model\Company;
use Symfony\Component\Uid\Uuid;

/**
 * The company's members, as a receipt naming who received the goods reads them: a port this module owns, answered by
 * the tenancy, which keeps who belongs to a company. Names only: never an address, which a receipt has no use for.
 */
interface ReceivingMembers
{
    /**
     * Every member of the company, `$first` before the others when they are one, the rest oldest membership first.
     *
     * @return list<ReceivingMember>
     */
    public function membersOf(Company $company, ?Uuid $first): array;

    /** The member's name as the company knows it now, or null when this account belongs to no membership of it. */
    public function nameOf(Company $company, Uuid $userId): ?string;
}
