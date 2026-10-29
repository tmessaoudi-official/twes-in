<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Application\Closing;

use App\Audit\Application\AuditEntry;
use App\Audit\Application\AuditTrail;
use App\Audit\Application\DeclaresAuditActions;
use App\Audit\Domain\ValueObject\AuditScope;
use App\Audit\Domain\ValueObject\Recorded;
use App\Fiscal\Application\Preset\FiscalPresets;
use App\Identity\Application\StepUp\ConfirmStepUp;
use App\Identity\Application\StepUp\StepUpRequired;
use App\Shared\Application\Notification;
use App\Shared\Application\Notifications;
use App\Shared\Application\Transactions;
use App\Tenancy\Application\Company\CompanyNotFound;
use App\Tenancy\Application\Company\CreateCompany;
use App\Tenancy\Application\Member\OwnerOnly;
use App\Tenancy\Domain\Model\Company;
use App\Tenancy\Domain\Model\Membership;
use App\Tenancy\Domain\Model\Role;
use App\Tenancy\Domain\Repository\CompanyRepository;
use App\Tenancy\Domain\Repository\InvitationRepository;
use App\Tenancy\Domain\Repository\MembershipRepository;
use App\Tenancy\Domain\Repository\SupportGrantRepository;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * « Fermer l'entreprise »: its owner, within minutes of proving again who is at the screen, closes the company down. It
 * stops working at once and its members keep reading it until the last day its country's records must be kept: the end
 * of the year it closed plus the preset's years, fixed now, so a later change of the law's reading shortens nothing
 * already promised. No one new comes in: its open invitations go and support's access ends. Within the days the platform
 * sets, its owner reopens it; after them the closing is final, and the nightly run removes it once its retention ends.
 */
final readonly class CloseDownCompany implements DeclaresAuditActions
{
    public const string CLOSED_DOWN = 'company.closed_down';
    public const string REOPENED = 'company.reopened';

    public function __construct(
        private CompanyRepository $companies,
        private MembershipRepository $memberships,
        private InvitationRepository $invitations,
        private SupportGrantRepository $supportGrants,
        private FiscalPresets $presets,
        private ConfirmStepUp $stepUp,
        private ClosingTerms $terms,
        private AuditTrail $audit,
        private Transactions $transactions,
        private ClockInterface $clock,
        private Notifications $notifications,
    ) {
    }

    /** @throws OwnerOnly|StepUpRequired */
    public function closeDown(Company $company, Uuid $userId): ClosedDown
    {
        $owner = $this->provedOwner($company, $userId);
        $now = $this->clock->now();
        $year = (int) $now->setTimezone(new \DateTimeZone($company->getTimezone()))->format('Y');
        $retainUntil = new \DateTimeImmutable(\sprintf('%04d-12-31', $year + $this->presets->get($company->getFiscalPreset())->retentionYears));

        $this->transactions->run(function () use ($company, $owner, $now, $retainUntil): void {
            $company = $this->held($company);
            $company->closeDown($now, $retainUntil);
            $this->companies->save($company);
            foreach ($this->invitations->openOfCompany($company->getId(), $now) as $invitation) {
                $this->invitations->remove($invitation);
            }
            $grant = $this->supportGrants->liveIn($company->getId(), $now);
            if (null !== $grant) {
                $grant->end($owner->getUser(), $now);
                $this->supportGrants->save($grant);
            }
            $this->audit->record(new AuditEntry(CreateCompany::ENTITY_TYPE, $company->getId(), self::CLOSED_DOWN, $owner->getUser()->getId(), ['retainUntil' => $retainUntil->format('Y-m-d')], $company->getId()));
            // Everyone else working there would otherwise find out from a refused save.
            $this->tellTheOthers($company, self::CLOSED_DOWN, $owner, [
                'reopen_until' => $this->terms->reopenUntil($now)->setTimezone(new \DateTimeZone($company->getTimezone()))->format('Y-m-d H:i'),
                'retain_until' => $retainUntil->format('Y-m-d'),
            ]);
        });

        return new ClosedDown($retainUntil, $this->terms->reopenUntil($now));
    }

    /** @throws OwnerOnly|StepUpRequired|ReopenPeriodOver|NotClosedDown */
    public function reopen(Company $company, Uuid $userId): void
    {
        $owner = $this->provedOwner($company, $userId);
        $now = $this->clock->now();

        $this->transactions->run(function () use ($company, $owner, $now): void {
            // Whether it is closed, and since when, as the held row says: never as a read from before another save.
            $company = $this->held($company);
            $closedDownAt = $company->getClosedDownAt() ?? throw new NotClosedDown('Only a closed company is reopened.');
            if ($now > $this->terms->reopenUntil($closedDownAt)) {
                throw new ReopenPeriodOver('The days to take the closing back are over.');
            }
            $company->reopen($now);
            $this->companies->save($company);
            $this->audit->record(new AuditEntry(CreateCompany::ENTITY_TYPE, $company->getId(), self::REOPENED, $owner->getUser()->getId(), [], $company->getId()));
            $this->tellTheOthers($company, self::REOPENED, $owner, []);
        });
    }

    /** @param array<string, string> $details */
    private function tellTheOthers(Company $company, string $type, Membership $owner, array $details): void
    {
        $this->notifications->publish(new Notification(
            'company:'.$company->getId()->toRfc4122(),
            $type,
            ['company' => $company->getName(), 'by' => $owner->getUser()->getDisplayName(), ...$details],
            actorId: $owner->getUser()->getId()->toRfc4122(),
        ));
    }

    /** @throws OwnerOnly|StepUpRequired */
    private function provedOwner(Company $company, Uuid $userId): Membership
    {
        $membership = $this->memberships->ofUserInCompany($userId, $company->getId());
        if (null === $membership || Role::OWNER !== $membership->getRole()->getName()) {
            throw new OwnerOnly('Only the owner closes the company down or reopens it.');
        }
        if (!$this->stepUp->isRecent($userId)) {
            throw new StepUpRequired();
        }

        return $membership;
    }

    private function held(Company $company): Company
    {
        return $this->companies->lockedOfId($company->getId()) ?? throw new CompanyNotFound('No such company.');
    }

    public function auditActions(): array
    {
        return [
            self::CLOSED_DOWN => new Recorded(CreateCompany::ENTITY_TYPE, AuditScope::Company),
            self::REOPENED => new Recorded(CreateCompany::ENTITY_TYPE, AuditScope::Company),
        ];
    }
}
