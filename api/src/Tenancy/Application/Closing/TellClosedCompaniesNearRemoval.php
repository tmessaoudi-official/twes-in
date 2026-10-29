<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Application\Closing;

use App\Shared\Application\Notification;
use App\Shared\Application\Notifications;
use App\Shared\Application\Transactions;
use App\Tenancy\Domain\Model\Role;
use App\Tenancy\Domain\Repository\CompanyRepository;
use App\Tenancy\Domain\Repository\MembershipRepository;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Tells a closed company's owners, some days before the night it is removed with everything it holds, while its books
 * can still be taken: years pass between the closing and that night, and nothing else would remind them. Each company is
 * told once; one whose last day kept is already past goes that night untold, since nothing can be taken by then.
 */
final readonly class TellClosedCompaniesNearRemoval
{
    public const string REMOVAL_NEAR = 'company.removal_near';

    public function __construct(
        private CompanyRepository $companies,
        private MembershipRepository $memberships,
        private Notifications $notifications,
        private Transactions $transactions,
        private ClockInterface $clock,
        #[Autowire(param: 'app.company.removal_notice_days')]
        private int $noticeDays,
    ) {
    }

    /** @return int how many companies' owners were told */
    public function handle(): int
    {
        return $this->transactions->run(function (): int {
            $now = $this->clock->now();
            $today = new \DateTimeImmutable($now->format('Y-m-d'));
            $told = 0;
            foreach ($this->companies->closedDownNearingRemoval($today, $today->modify(\sprintf('+%d days', $this->noticeDays))) as $company) {
                $this->companies->removalTold($company, $now);
                $retainUntil = $company->getRetainUntil() ?? throw new \LogicException('A closed company keeps a last day.');
                foreach ($this->memberships->ofCompany($company->getId()) as $membership) {
                    if (Role::OWNER === $membership->getRole()->getName()) {
                        $this->notifications->publish(new Notification(
                            'user:'.$membership->getUser()->getId()->toRfc4122(),
                            self::REMOVAL_NEAR,
                            ['company' => $company->getName(), 'retain_until' => $retainUntil->format('Y-m-d')],
                            $company->getId()->toRfc4122(),
                        ));
                    }
                }
                ++$told;
            }

            return $told;
        });
    }
}
