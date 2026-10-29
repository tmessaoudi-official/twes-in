<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Licensing\Application;

use App\Licensing\Domain\Repository\PaymentDeclarationRepository;
use App\Licensing\Domain\Repository\SubscriptionRepository;
use App\Licensing\Domain\ValueObject\Stage;
use App\Shared\Application\Notification;
use App\Shared\Application\Notifications;
use App\Shared\Application\Transactions;
use App\Tenancy\Domain\Model\Role;
use App\Tenancy\Domain\Repository\MembershipRepository;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Tells a company's owners, some days before the time they paid for (or their trial) ends, until when access stays
 * whole and where to declare a payment: nobody watches that date, and the restriction would otherwise be the first they
 * hear of it. Each end is told once; paying moves the end, which is told again when it nears. A company whose payment
 * already waits for the operator, or that is past its lock, or that is not open, is not told.
 */
final readonly class TellLapsingSubscriptions
{
    public const string LAPSING = 'subscription.lapsing';

    public function __construct(
        private SubscriptionRepository $subscriptions,
        private PaymentDeclarationRepository $declarations,
        private CompanyStandings $standings,
        private MembershipRepository $memberships,
        private Notifications $notifications,
        private Transactions $transactions,
        private ClockInterface $clock,
        #[Autowire(param: 'app.subscription.lapse_notice_days')]
        private int $noticeDays,
    ) {
    }

    /** @return int how many companies' owners were told */
    public function handle(): int
    {
        return $this->transactions->run(function (): int {
            $now = $this->clock->now();
            $told = 0;
            foreach ($this->subscriptions->coveredBy($now->modify(\sprintf('+%d days', $this->noticeDays))) as $subscription) {
                $company = $subscription->getCompany();
                $coveredUntil = $subscription->getTerms()->coveredUntil();
                if (!$company->isActive() || $subscription->wasToldOfLapse($coveredUntil)) {
                    continue;
                }
                $standing = $this->standings->standingOf($subscription);
                if (\in_array($standing->stage, [Stage::Held, Stage::Unpaid], true) || null !== $this->declarations->openOfCompany($company->getId())) {
                    continue;
                }

                $this->subscriptions->lapseTold($subscription, $coveredUntil);
                $zone = new \DateTimeZone($company->getTimezone());
                $payload = [
                    'company' => $company->getName(),
                    'covered_until' => $coveredUntil->setTimezone($zone)->format('Y-m-d'),
                    'grace_until' => $standing->graceEndsAt->setTimezone($zone)->format('Y-m-d'),
                ];
                foreach ($this->memberships->ofCompany($company->getId()) as $membership) {
                    if (Role::OWNER === $membership->getRole()->getName()) {
                        $this->notifications->publish(new Notification('user:'.$membership->getUser()->getId()->toRfc4122(), self::LAPSING, $payload, $company->getId()->toRfc4122()));
                    }
                }
                ++$told;
            }

            return $told;
        });
    }
}
