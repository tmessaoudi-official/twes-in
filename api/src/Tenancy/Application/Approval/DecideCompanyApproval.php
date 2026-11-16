<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Application\Approval;

use App\Audit\Application\AuditEntry;
use App\Audit\Application\AuditTrail;
use App\Audit\Application\DeclaresAuditActions;
use App\Audit\Domain\ValueObject\AuditScope;
use App\Audit\Domain\ValueObject\Recorded;
use App\Identity\Domain\Model\User;
use App\Settings\Application\PlatformSettings;
use App\Settings\Application\ReadSetting;
use App\Settings\Application\SettingContext;
use App\Shared\Application\Transactions;
use App\Shared\Domain\Service\TypedText;
use App\Tenancy\Application\Company\CompanyNotFound;
use App\Tenancy\Application\Company\CreateCompany;
use App\Tenancy\Domain\Exception\SuspensionReasonUnchanged;
use App\Tenancy\Domain\Model\Company;
use App\Tenancy\Domain\Model\Role;
use App\Tenancy\Domain\Repository\CompanyRepository;
use App\Tenancy\Domain\Repository\MembershipRepository;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;

/**
 * An operator's decision on a company (docs/SPEC.md § 7, 2026-09-15, 2026-10-27 21:37). Approving opens one that waits
 * or was refused and tells its owners it is approved; opening again one that was suspended restores its access and
 * tells them so, never that it was approved. Rejecting refuses one never opened and suspends one that was, recorded as
 * such, with the reason the operator gave if any, which the owners' mail and their sign-in page say. A decision the
 * company already carries changes nothing: no second mail, no second audit entry.
 */
final readonly class DecideCompanyApproval implements DeclaresAuditActions
{
    public const string APPROVED = 'company.approved';
    public const string REJECTED = 'company.rejected';
    /** An open company closed by an operator, whose suspend stays available at any time (docs/SPEC.md § 7, 2026-09-17). */
    public const string SUSPENDED = 'company.suspended';
    /** A suspended company opened again: its access is back, which is not its approval. */
    public const string RESTORED = 'company.restored';
    /** A suspended company's reason said anew by a second « Suspendre », where the operators have it so. */
    public const string REASON_CHANGED = 'company.suspension_reason_changed';

    public function __construct(
        private CompanyRepository $companies,
        private MembershipRepository $memberships,
        private CompanyApprovalMailer $mailer,
        private AuditTrail $audit,
        private ClockInterface $clock,
        #[Autowire(param: 'app.signup.login_url')]
        private string $loginUrl,
        private Transactions $transactions,
        private ReadSetting $settings,
    ) {
    }

    /** @throws CompanyNotFound */
    public function approve(Uuid $companyId, Uuid $operatorId): Company
    {
        // Decided on the row held, never on an earlier read: a suspension saved in between is what is approved.
        [$company, $action] = $this->transactions->run(function () use ($companyId, $operatorId): array {
            $company = $this->heldCompany($companyId);
            if ($company->isActive()) {
                return [$company, null];
            }
            $from = $company->getStatus();
            $action = $company->wasOpened() ? self::RESTORED : self::APPROVED;
            $company->activate($this->clock->now());
            $this->companies->save($company);
            $this->record($company, $action, $from, $operatorId);

            return [$company, $action];
        });
        if (null === $action) {
            return $company;
        }

        // The owners hear of it once the decision is stored, never of one that rolled back.
        foreach ($this->owners($company) as $owner) {
            $mail = new CompanyApprovedMail($owner->getEmail()->value, $company->getName(), $this->loginUrl, $owner->getLocale());
            self::RESTORED === $action ? $this->mailer->restored($mail) : $this->mailer->approved($mail);
        }

        return $company;
    }

    /**
     * Refuses a company never opened or suspends one that was; a company already suspended keeps its reason, unless the
     * operators have a second « Suspendre » say it anew (`approval.resuspend_updates_reason`), when another reason
     * replaces it and its owners are told again; the reason it already has is refused then, as nothing would change.
     *
     * @throws CompanyNotFound
     * @throws SuspensionReasonUnchanged
     */
    public function reject(Uuid $companyId, Uuid $operatorId, ?string $reason = null): Company
    {
        $reason = null === $reason ? null : TypedText::trimmed($reason);
        $reason = '' === $reason ? null : $reason;
        [$company, $action] = $this->transactions->run(function () use ($companyId, $operatorId, $reason): array {
            $company = $this->heldCompany($companyId);
            if (Company::STATUS_SUSPENDED === $company->getStatus()) {
                if (!$this->reasonSaidAnew()) {
                    return [$company, null];
                }
                if ($reason === $company->getSuspensionReason()) {
                    throw new SuspensionReasonUnchanged('The company is already suspended with this reason.');
                }
                $company->changeSuspensionReason($reason);
                $this->companies->save($company);
                $this->record($company, self::REASON_CHANGED, Company::STATUS_SUSPENDED, $operatorId);

                return [$company, self::REASON_CHANGED];
            }
            $from = $company->getStatus();
            $action = $company->wasOpened() ? self::SUSPENDED : self::REJECTED;
            $company->suspend($this->clock->now(), $reason);
            $this->companies->save($company);
            $this->record($company, $action, $from, $operatorId);

            return [$company, $action];
        });
        if (null === $action) {
            return $company;
        }

        // A company an operator made waits for its first owner, so a refusal of it reaches nobody, as an approval would.
        foreach ($this->owners($company) as $owner) {
            $mail = new CompanyDecisionMail($owner->getEmail()->value, $company->getName(), $owner->getLocale(), $reason);
            $company->wasOpened() ? $this->mailer->suspended($mail) : $this->mailer->refused($mail);
        }

        return $company;
    }

    private function reasonSaidAnew(): bool
    {
        return true === $this->settings->value(new SettingContext(), PlatformSettings::RESUSPEND_UPDATES_REASON);
    }

    /** @return list<User> */
    private function owners(Company $company): array
    {
        $owners = [];
        foreach ($this->memberships->ofCompany($company->getId()) as $membership) {
            if (Role::OWNER === $membership->getRole()->getName()) {
                $owners[] = $membership->getUser();
            }
        }

        return $owners;
    }

    private function heldCompany(Uuid $companyId): Company
    {
        return $this->companies->lockedOfId($companyId) ?? throw new CompanyNotFound('No such company.');
    }

    private function record(Company $company, string $action, string $from, Uuid $operatorId): void
    {
        $this->audit->record(new AuditEntry(
            CreateCompany::ENTITY_TYPE,
            $company->getId(),
            $action,
            $operatorId,
            ['from' => $from, 'status' => $company->getStatus()],
            $company->getId(),
        ));
    }

    public function auditActions(): array
    {
        return [
            self::APPROVED => new Recorded(CreateCompany::ENTITY_TYPE, AuditScope::Company),
            self::REJECTED => new Recorded(CreateCompany::ENTITY_TYPE, AuditScope::Company),
            self::SUSPENDED => new Recorded(CreateCompany::ENTITY_TYPE, AuditScope::Company),
            self::RESTORED => new Recorded(CreateCompany::ENTITY_TYPE, AuditScope::Company),
            self::REASON_CHANGED => new Recorded(CreateCompany::ENTITY_TYPE, AuditScope::Company),
        ];
    }
}
