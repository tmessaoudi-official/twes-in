<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Licensing\Application;

use App\Audit\Application\AuditEntry;
use App\Audit\Application\AuditTrail;
use App\Licensing\Domain\Subscription;
use App\Licensing\Domain\SubscriptionRepository;
use App\Licensing\Domain\SubscriptionTerms;
use App\Shared\Application\Transactions;
use App\Tenancy\Application\Company\CompanyNotFound;
use App\Tenancy\Domain\Company;
use App\Tenancy\Domain\CompanyRepository;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * An operator's hand on a company's subscription (docs/SPEC.md § 7, 2026-09-17): setting its terms starts managing the
 * company, stopping forgets them and gives the company full access again. Every change is audited against the company,
 * with the terms before and after, so the company's own log shows what was decided about it.
 */
final readonly class ManageSubscriptions
{
    public const string ENTITY_TYPE = 'subscription';
    public const string SET = 'subscription.set';
    public const string STOPPED = 'subscription.stopped';

    public function __construct(
        private SubscriptionRepository $subscriptions,
        private CompanyRepository $companies,
        private AuditTrail $audit,
        private ClockInterface $clock,
        private Transactions $transactions,
    ) {
    }

    /** @throws CompanyNotFound */
    public function ofCompany(Uuid $companyId): ?Subscription
    {
        return $this->subscriptions->ofCompany($this->companyOf($companyId)->getId());
    }

    /** @throws CompanyNotFound */
    public function set(Uuid $companyId, SubscriptionTerms $terms, Uuid $operatorId): Subscription
    {
        $company = $this->companyOf($companyId);

        return $this->transactions->run(function () use ($company, $terms, $operatorId): Subscription {
            $now = $this->clock->now();
            $subscription = $this->subscriptions->ofCompany($company->getId());
            $before = $subscription?->getTerms();
            if (null === $subscription) {
                $subscription = new Subscription($company, $terms, $now);
            } else {
                $subscription->revise($terms, $now);
            }
            $this->subscriptions->save($subscription);
            $this->record($subscription, self::SET, $operatorId, ['from' => null === $before ? null : self::describe($before), 'to' => self::describe($terms)]);

            return $subscription;
        });
    }

    /** Stopping a subscription licensing does not hold changes nothing. @throws CompanyNotFound */
    public function stop(Uuid $companyId, Uuid $operatorId): void
    {
        $company = $this->companyOf($companyId);
        $this->transactions->run(function () use ($company, $operatorId): void {
            $subscription = $this->subscriptions->ofCompany($company->getId());
            if (null === $subscription) {
                return;
            }
            $this->record($subscription, self::STOPPED, $operatorId, ['from' => self::describe($subscription->getTerms())]);
            $this->subscriptions->remove($subscription);
        });
    }

    /** @param array<string, mixed> $changes */
    private function record(Subscription $subscription, string $action, Uuid $operatorId, array $changes): void
    {
        $companyId = $subscription->getCompany()->getId();
        $this->audit->record(new AuditEntry(self::ENTITY_TYPE, $subscription->getId(), $action, $operatorId, $changes, $companyId));
    }

    /** @return array<string, int|string|null> */
    private static function describe(SubscriptionTerms $terms): array
    {
        return [
            'period' => $terms->period->count.' '.$terms->period->unit->value,
            'trialEndsAt' => $terms->trialEndsAt?->format(\DATE_ATOM),
            'paidUntil' => $terms->paidUntil?->format(\DATE_ATOM),
            'price' => $terms->price,
            'currency' => $terms->currency,
            'graceDays' => $terms->graceDays,
            'unpaidMode' => $terms->unpaidMode?->value,
        ];
    }

    private function companyOf(Uuid $companyId): Company
    {
        return $this->companies->ofId($companyId) ?? throw new CompanyNotFound();
    }
}
