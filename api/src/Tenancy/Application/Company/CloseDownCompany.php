<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Application\Company;

use App\Audit\Application\AuditEntry;
use App\Audit\Application\AuditTrail;
use App\Fiscal\Application\Preset\FiscalPresets;
use App\Identity\Application\StepUp\ConfirmStepUp;
use App\Identity\Application\StepUp\StepUpRequired;
use App\Settings\Application\ReadSetting;
use App\Settings\Application\SettingContext;
use App\Shared\Application\Transactions;
use App\Tenancy\Domain\Company;
use App\Tenancy\Domain\CompanyRepository;
use App\Tenancy\Domain\InvitationRepository;
use App\Tenancy\Domain\Membership;
use App\Tenancy\Domain\MembershipRepository;
use App\Tenancy\Domain\Role;
use App\Tenancy\Domain\SupportGrantRepository;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * « Fermer l'entreprise »: its owner, within minutes of proving again who is at the screen, closes the company down. It
 * stops working at once and its members keep reading it until the last day its country's records must be kept: the end
 * of the year it closed plus the preset's years, fixed now, so a later change of the law's reading shortens nothing
 * already promised. No one new comes in: its open invitations go and support's access ends. Within the days the platform
 * sets, its owner reopens it; after them the closing is final, and the nightly run removes it once its retention ends.
 */
final readonly class CloseDownCompany
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
        private ReadSetting $settings,
        private AuditTrail $audit,
        private Transactions $transactions,
        private ClockInterface $clock,
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
        });

        return new ClosedDown($retainUntil, $now->modify(\sprintf('+%d days', $this->reopenDays())));
    }

    /** @throws OwnerOnly|StepUpRequired|ReopenPeriodOver|NotClosedDown */
    public function reopen(Company $company, Uuid $userId): void
    {
        $owner = $this->provedOwner($company, $userId);
        $now = $this->clock->now();
        $closedDownAt = $company->getClosedDownAt() ?? throw new NotClosedDown('Only a closed company is reopened.');
        if ($now > $closedDownAt->modify(\sprintf('+%d days', $this->reopenDays()))) {
            throw new ReopenPeriodOver('The days to take the closing back are over.');
        }

        $this->transactions->run(function () use ($company, $owner, $now): void {
            $company->reopen($now);
            $this->companies->save($company);
            $this->audit->record(new AuditEntry(CreateCompany::ENTITY_TYPE, $company->getId(), self::REOPENED, $owner->getUser()->getId(), [], $company->getId()));
        });
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

    private function reopenDays(): int
    {
        $days = $this->settings->value(new SettingContext(), CompanyClosingSettings::REOPEN_DAYS);
        if (!\is_int($days)) {
            throw new \UnexpectedValueException('The days to reopen a closed company are not a number.');
        }

        return $days;
    }
}
