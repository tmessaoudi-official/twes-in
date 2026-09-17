<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Licensing\Domain;

use Symfony\Component\Uid\Uuid;

interface SubscriptionRepository
{
    public function ofCompany(Uuid $companyId): ?Subscription;

    /**
     * The subscriptions of the companies named, by company id.
     *
     * @param list<Uuid> $companyIds
     *
     * @return array<string, Subscription>
     */
    public function ofCompanies(array $companyIds): array;

    public function save(Subscription $subscription): void;

    public function remove(Subscription $subscription): void;
}
