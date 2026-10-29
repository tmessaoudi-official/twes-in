<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Licensing\Domain\Repository;

use App\Licensing\Domain\Model\Subscription;
use Symfony\Component\Uid\Uuid;

interface SubscriptionRepository
{
    public function ofCompany(Uuid $companyId): ?Subscription;

    /** The company's subscription, held until the transaction ends and read afresh: what a writer checks and changes. */
    public function lockedOfCompany(Uuid $companyId): ?Subscription;

    /**
     * The subscriptions of the companies named, by company id.
     *
     * @param list<Uuid> $companyIds
     *
     * @return array<string, Subscription>
     */
    public function ofCompanies(array $companyIds): array;

    /**
     * @return list<Subscription> every subscription whose covered time ends by $by, with its company, read afresh
     */
    public function coveredBy(\DateTimeImmutable $by): array;

    /** Keeps that its owners were told this end of covered time is near, without touching the terms or their version. */
    public function lapseTold(Subscription $subscription, \DateTimeImmutable $coveredUntil): void;

    public function save(Subscription $subscription): void;

    public function remove(Subscription $subscription): void;
}
