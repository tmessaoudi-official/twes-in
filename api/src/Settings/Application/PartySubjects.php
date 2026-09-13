<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Settings\Application;

use App\Tenancy\Domain\Company;
use Symfony\Component\Uid\Uuid;

/**
 * The parties chain's subjects, as the module that owns them knows them. The settings engine asks whether a group
 * or a customer is the company's, and which group a customer is in; the customers module answers, so the engine
 * never depends on it.
 */
interface PartySubjects
{
    public function hasCustomerGroup(Company $company, Uuid $groupId): bool;

    /** @return PartySubject|null null when the company has no such customer */
    public function customer(Company $company, Uuid $customerId): ?PartySubject;
}
