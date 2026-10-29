<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Licensing\Application;

use App\Audit\Application\AuditEntry;
use App\Audit\Application\AuditTrail;
use App\Audit\Application\DeclaresAuditActions;
use App\Audit\Domain\ValueObject\AuditScope;
use App\Audit\Domain\ValueObject\Recorded;
use App\Licensing\Domain\Model\Subscription;
use App\Licensing\Domain\Repository\SubscriptionRepository;
use App\Licensing\Domain\ValueObject\SubscriptionTerms;
use App\Shared\Application\Notification;
use App\Shared\Application\Notifications;
use App\Shared\Application\RecordChanged;
use App\Shared\Application\RecordVersions;
use App\Shared\Application\Transactions;
use App\Tenancy\Application\Company\CompanyNotFound;
use App\Tenancy\Domain\Model\Company;
use App\Tenancy\Domain\Model\Role;
use App\Tenancy\Domain\Repository\CompanyRepository;
use App\Tenancy\Domain\Repository\MembershipRepository;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * An operator's hand on a company's subscription (docs/SPEC.md § 7, 2026-09-17): setting its terms starts managing the
 * company, stopping forgets them and gives the company full access again. Every change is audited against the company,
 * naming the terms it changed, so the company's own log shows when what was decided about it changed.
 */
final readonly class ManageSubscriptions implements DeclaresAuditActions
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
        private RecordVersions $versions,
        private MembershipRepository $memberships,
        private Notifications $notifications,
    ) {
    }

    /** @throws CompanyNotFound */
    public function ofCompany(Uuid $companyId): ?Subscription
    {
        return $this->subscriptions->ofCompany($this->companyOf($companyId)->getId());
    }

    /**
     * @param int $version the version of the subscription the terms were read at, 0 when the company had none
     *
     * @throws CompanyNotFound
     * @throws RecordChanged   when the subscription was set, stopped or carried forward since
     */
    public function set(Uuid $companyId, SubscriptionTerms $terms, Uuid $operatorId, int $version): Subscription
    {
        $this->companyOf($companyId);

        return $this->transactions->run(function () use ($companyId, $terms, $operatorId, $version): Subscription {
            // The company's row is held first, so two operators starting to manage it never both find none.
            $company = $this->companies->lockedOfId($companyId) ?? throw new CompanyNotFound();
            $now = $this->clock->now();
            $subscription = $this->subscriptions->lockedOfCompany($company->getId());
            if (null !== $subscription) {
                $this->versions->expect($subscription, $version);
            } elseif (0 !== $version) {
                throw new RecordChanged();
            }
            $before = $subscription?->getTerms();
            if (null === $subscription) {
                $subscription = new Subscription($company, $terms, $now);
            } else {
                $subscription->revise($terms, $now);
            }
            $this->subscriptions->save($subscription);
            $changed = self::changed($before, $terms);
            $this->record($subscription, self::SET, $operatorId, ['fields' => $changed]);
            if (null === $before || [] !== $changed) {
                $this->tellOwners($company, self::SET);
            }

            return $subscription;
        });
    }

    /**
     * Stopping erases the terms, which nothing keeps afterwards, so it names the version it was read at: terms another
     * operator set or stopped since are refused rather than erased unseen.
     *
     * @param int $version the version of the subscription the operator read
     *
     * @throws CompanyNotFound
     * @throws RecordChanged   when the subscription was set, stopped or carried forward since
     */
    public function stop(Uuid $companyId, Uuid $operatorId, int $version): void
    {
        $company = $this->companyOf($companyId);
        $this->transactions->run(function () use ($company, $operatorId, $version): void {
            $subscription = $this->subscriptions->lockedOfCompany($company->getId()) ?? throw new RecordChanged();
            $this->versions->expect($subscription, $version);
            $this->record($subscription, self::STOPPED, $operatorId, ['fields' => self::changed($subscription->getTerms(), null)]);
            $this->subscriptions->remove($subscription);
            $this->tellOwners($company, self::STOPPED);
        });
    }

    /**
     * What the company pays, or until when, is its owners' business, and the platform changed it: each is told, and the
     * subscription page says what it now is. Terms set again unchanged tell nothing.
     */
    private function tellOwners(Company $company, string $type): void
    {
        foreach ($this->memberships->ofCompany($company->getId()) as $membership) {
            if (Role::OWNER !== $membership->getRole()->getName()) {
                continue;
            }
            $this->notifications->publish(new Notification(
                'user:'.$membership->getUser()->getId()->toRfc4122(),
                $type,
                ['company' => $company->getName()],
                $company->getId()->toRfc4122(),
            ));
        }
    }

    /** @param array<string, mixed> $changes */
    private function record(Subscription $subscription, string $action, Uuid $operatorId, array $changes): void
    {
        $companyId = $subscription->getCompany()->getId();
        $this->audit->record(new AuditEntry(self::ENTITY_TYPE, $subscription->getId(), $action, $operatorId, $changes, $companyId));
    }

    /**
     * The names of the terms that differ between two sets, either of which may be none: the journal names what changed
     * and never holds a price or a date (audit records field names, never values).
     *
     * @return list<string>
     */
    private static function changed(?SubscriptionTerms $before, ?SubscriptionTerms $after): array
    {
        $from = self::compared($before);
        $to = self::compared($after);

        return array_values(array_filter(array_keys($from), static fn (string $term): bool => $from[$term] !== $to[$term]));
    }

    /**
     * Each term as one comparable value, none when there are no terms; kept in memory to tell what changed.
     *
     * @return array<string, int|string|null>
     */
    private static function compared(?SubscriptionTerms $terms): array
    {
        return [
            'period' => null === $terms ? null : $terms->period->count.' '.$terms->period->unit->value,
            // The instant, not its writing: terms read back from the database come in another time zone than those typed.
            'trialEndsAt' => $terms?->trialEndsAt?->getTimestamp(),
            'paidUntil' => $terms?->paidUntil?->getTimestamp(),
            'price' => $terms?->price,
            'currency' => $terms?->currency,
            'graceDays' => $terms?->graceDays,
            'holdDays' => $terms?->holdDays,
            'unpaidMode' => $terms?->unpaidMode?->value,
        ];
    }

    private function companyOf(Uuid $companyId): Company
    {
        return $this->companies->ofId($companyId) ?? throw new CompanyNotFound();
    }

    public function auditActions(): array
    {
        return [
            self::SET => new Recorded(self::ENTITY_TYPE, AuditScope::Company),
            self::STOPPED => new Recorded(self::ENTITY_TYPE, AuditScope::Company),
        ];
    }
}
