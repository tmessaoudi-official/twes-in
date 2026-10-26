<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Application\Support;

use App\Audit\Application\AuditEntry;
use App\Audit\Application\AuditTrail;
use App\Identity\Application\StepUp\ConfirmStepUp;
use App\Identity\Application\StepUp\StepUpRequired;
use App\Identity\Domain\Model\User;
use App\Shared\Application\Notification;
use App\Shared\Application\Notifications;
use App\Shared\Application\Transactions;
use App\Tenancy\Domain\Exception\InvalidSupportReason;
use App\Tenancy\Domain\Exception\InvalidSupportWindow;
use App\Tenancy\Domain\Model\Company;
use App\Tenancy\Domain\Model\Role;
use App\Tenancy\Domain\Model\SupportGrant;
use App\Tenancy\Domain\Repository\MembershipRepository;
use App\Tenancy\Domain\Repository\SupportGrantRepository;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * The owner's side of « Accès support »: open the company to the platform's operators for an hour, a day or three, and
 * close it early. Only the owner, whatever a custom role grants: letting someone outside the company read all of it is
 * the owner's to decide, and the owner proves who is at the screen again to open it (docs/SPEC.md § 7, the step-up
 * list); closing it asks nothing. Every grant and every end is in the activity log, and the other owners are told of it.
 */
final readonly class ManageSupportAccess
{
    public const string ENTITY_TYPE = 'support_grant';
    public const string GRANTED = 'support_access.granted';
    public const string ENDED = 'support_access.ended';

    public function __construct(
        private MembershipRepository $memberships,
        private SupportGrantRepository $grants,
        private AuditTrail $audit,
        private Notifications $notifications,
        private Transactions $transactions,
        private ClockInterface $clock,
        private ConfirmStepUp $stepUp,
    ) {
    }

    /** @throws OwnerOnly */
    public function current(Company $company, Uuid $userId): ?SupportGrant
    {
        $this->owner($company, $userId);

        return $this->grants->liveIn($company->getId(), $this->clock->now());
    }

    /**
     * Opens a new window from now; one still live is ended first, so there is only ever one. What the person can fix,
     * the owner and the window, is said before the proof is asked.
     *
     * @throws OwnerOnly
     * @throws InvalidSupportWindow
     * @throws InvalidSupportReason
     * @throws StepUpRequired
     */
    public function grant(Company $company, Uuid $userId, mixed $hours, mixed $reason = null): SupportGrant
    {
        $owner = $this->owner($company, $userId);
        if (!\is_int($hours)) {
            throw new InvalidSupportWindow('Support access lasts a whole number of hours.');
        }
        if (null !== $reason && !\is_string($reason)) {
            throw new InvalidSupportReason('What is wrong is said in words.');
        }
        $now = $this->clock->now();
        $grant = new SupportGrant($company, $owner, $hours, $now, $reason);
        if (!$this->stepUp->isRecent($userId)) {
            throw new StepUpRequired();
        }

        return $this->transactions->run(function () use ($company, $owner, $grant, $now): SupportGrant {
            $this->endLive($company, $owner, $now);
            $this->grants->save($grant);
            $this->record($grant, self::GRANTED, $owner);
            $this->tellOtherOwners($grant, self::GRANTED, $owner);

            return $grant;
        });
    }

    /** @throws OwnerOnly */
    public function end(Company $company, Uuid $userId): void
    {
        $owner = $this->owner($company, $userId);
        $this->transactions->run(function () use ($company, $owner): void {
            $ended = $this->endLive($company, $owner, $this->clock->now());
            if (null !== $ended) {
                $this->tellOtherOwners($ended, self::ENDED, $owner);
            }
        });
    }

    private function endLive(Company $company, User $owner, \DateTimeImmutable $now): ?SupportGrant
    {
        $live = $this->grants->liveIn($company->getId(), $now);
        if (null === $live) {
            return null;
        }
        $live->end($owner, $now);
        $this->grants->save($live);
        $this->record($live, self::ENDED, $owner);

        return $live;
    }

    /** @throws OwnerOnly */
    private function owner(Company $company, Uuid $userId): User
    {
        $membership = $this->memberships->ofUserInCompany($userId, $company->getId());
        if (null === $membership || Role::OWNER !== $membership->getRole()->getName()) {
            throw new OwnerOnly('Only the owner opens the company to support.');
        }

        return $membership->getUser();
    }

    private function record(SupportGrant $grant, string $action, User $owner): void
    {
        $fields = self::GRANTED === $action && null !== $grant->getReason() ? ['endsAt', 'reason'] : ['endsAt'];
        $this->audit->record(new AuditEntry(self::ENTITY_TYPE, $grant->getId(), $action, $owner->getId(), ['fields' => $fields], $grant->getCompany()->getId()));
    }

    private function tellOtherOwners(SupportGrant $grant, string $type, User $actor): void
    {
        $company = $grant->getCompany();
        foreach ($this->memberships->ofCompany($company->getId()) as $membership) {
            $owner = $membership->getUser();
            if (Role::OWNER !== $membership->getRole()->getName() || $owner->getId()->equals($actor->getId())) {
                continue;
            }
            $this->notifications->publish(new Notification(
                'user:'.$owner->getId()->toRfc4122(),
                $type,
                ['company' => $company->getName(), 'by' => $actor->getDisplayName(), 'until' => $grant->getEndsAt()->setTimezone(new \DateTimeZone($company->getTimezone()))->format('Y-m-d H:i')],
                $company->getId()->toRfc4122(),
            ));
        }
    }
}
