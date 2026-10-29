<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Application\CompanyProof;

use App\Audit\Application\AuditEntry;
use App\Audit\Application\AuditTrail;
use App\Audit\Application\DeclaresAuditActions;
use App\Audit\Domain\ValueObject\AuditScope;
use App\Audit\Domain\ValueObject\Recorded;
use App\Identity\Application\Mfa\Passkey\BeginPasskeyAssertion;
use App\Identity\Application\Mfa\SecondFactorRefused;
use App\Identity\Application\Mfa\SecondFactorUnreadable;
use App\Identity\Application\Mfa\VerifySecondFactor;
use App\Identity\Application\StepUp\ConfirmStepUp;
use App\Identity\Application\StepUp\StepUpExhausted;
use App\Identity\Application\StepUp\StepUpRefused;
use App\Identity\Domain\Model\User;
use App\Identity\Domain\Repository\UserRepository;
use App\Shared\Application\Transactions;
use App\Tenancy\Application\Member\NotAMember;
use App\Tenancy\Application\Session\CompanyEntry;
use App\Tenancy\Domain\Model\Company;
use App\Tenancy\Domain\Repository\CompanyRepository;
use App\Tenancy\Domain\Repository\MembershipRepository;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * A member of several companies proves, once in this sign-in, that they are the one entering this company, with the
 * proof it takes (CompanyEntryProofs). A wrong answer is this sign-in's own, counted with the step-up's, and the last
 * one the sign-in may give ends it, as a wrong step-up does: never a step toward the account lock.
 */
final readonly class ProveCompanyEntry implements DeclaresAuditActions
{
    public const string PROVED = 'auth.company_proved';

    public function __construct(
        private UserRepository $users,
        private CompanyRepository $companies,
        private MembershipRepository $memberships,
        private CompanyEntryProofs $proofs,
        private CompanyEntry $entry,
        private ConfirmStepUp $stepUp,
        private VerifySecondFactor $secondFactor,
        private BeginPasskeyAssertion $passkeyAssertion,
        private AuditTrail $audit,
        private Transactions $transactions,
        private ClockInterface $clock,
    ) {
    }

    /** What the company takes of this member, for the screen that asks it. */
    public function asked(Uuid $userId, Uuid $companyId): EntryProof
    {
        [$user, $company] = $this->memberIn($userId, $companyId);

        return $this->proofs->of($user, $company);
    }

    /**
     * @throws NotAMember        when the company is not the account's, which answers as a company that is not there
     * @throws EntryProofRefused when the password or the code is wrong, or the company takes only the account's passkey
     * @throws StepUpExhausted   when that wrong answer was the last this sign-in may give
     */
    public function withPassword(Uuid $userId, Uuid $companyId, string $password, ?string $code): void
    {
        [$user, $company] = $this->memberIn($userId, $companyId);
        $asked = $this->proofs->of($user, $company);
        // Refused before the password is read, so a person sent to the wrong form spends none of the sign-in's answers.
        if (!$asked->password) {
            throw new EntryProofRefused(EntryProofRefused::PASSKEY_REQUIRED);
        }
        try {
            $this->stepUp->withPassword($userId, $password);
        } catch (StepUpRefused) {
            throw new EntryProofRefused(EntryProofRefused::PASSWORD);
        }
        if ($asked->code) {
            try {
                $this->secondFactor->handle($userId, $code ?? '', $this->clock->now());
            } catch (SecondFactorRefused|SecondFactorUnreadable) {
                $this->stepUp->countWrong($userId, 'code');

                throw new EntryProofRefused(EntryProofRefused::CODE);
            }
        }
        $this->proved($user, $company, 'password');
    }

    /**
     * The options for a passkey, naming the account's own, where the company takes one.
     *
     * @throws NotAMember
     * @throws EntryProofRefused when the company takes no passkey or the account holds none
     */
    public function passkeyOptions(Uuid $userId, Uuid $companyId): string
    {
        [$user, $company] = $this->memberIn($userId, $companyId);
        if (!$this->proofs->of($user, $company)->passkey) {
            throw new EntryProofRefused(EntryProofRefused::PASSKEY_NOT_TAKEN);
        }

        return $this->passkeyAssertion->handle($userId);
    }

    /**
     * @throws NotAMember
     * @throws EntryProofRefused when the company takes no passkey, or this one did not verify
     * @throws StepUpExhausted   when that wrong answer was the last this sign-in may give
     */
    public function withPasskey(Uuid $userId, Uuid $companyId, string $optionsJson, string $credentialJson): void
    {
        [$user, $company] = $this->memberIn($userId, $companyId);
        if (!$this->proofs->of($user, $company)->passkey) {
            throw new EntryProofRefused(EntryProofRefused::PASSKEY_NOT_TAKEN);
        }
        try {
            $this->stepUp->withPasskey($userId, $optionsJson, $credentialJson);
        } catch (StepUpRefused) {
            throw new EntryProofRefused(EntryProofRefused::PASSKEY);
        }
        $this->proved($user, $company, 'passkey');
    }

    public function auditActions(): array
    {
        // The account's trail: who proved which company, and how, without the company's journal saying its members' sign-ins.
        return [self::PROVED => new Recorded('company', AuditScope::Outside)];
    }

    /** @return array{User, Company} */
    private function memberIn(Uuid $userId, Uuid $companyId): array
    {
        $user = $this->users->ofId($userId);
        $company = $this->companies->ofId($companyId);
        if (null === $user || null === $company || null === $this->memberships->ofUserInCompany($userId, $companyId)) {
            throw new NotAMember(\sprintf('%s is not a member of that company.', $userId->toRfc4122()));
        }

        return [$user, $company];
    }

    private function proved(User $user, Company $company, string $method): void
    {
        $this->entry->proved($company->getId());
        $this->transactions->run(fn () => $this->audit->record(new AuditEntry('company', $company->getId(), self::PROVED, $user->getId(), ['method' => $method])));
    }
}
