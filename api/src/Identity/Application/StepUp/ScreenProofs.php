<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Application\StepUp;

use App\Identity\Application\CompanyProof\CompanyEntrySettings;
use App\Identity\Application\CompanyProof\EntryProof;
use App\Identity\Domain\Model\User;
use App\Identity\Domain\Repository\PasskeyRepository;
use App\Settings\Application\ReadSetting;
use App\Settings\Application\SettingContext;
use App\Tenancy\Application\Mfa\MfaRequirement;
use App\Tenancy\Domain\Model\Company;
use App\Tenancy\Domain\Repository\MembershipRepository;

/**
 * What a prompt asking who is at the screen asks, one answer for the step-up and a company's entry: only the proofs the
 * account holds. The password always, but where the account's only second factor is a passkey and one is required, as
 * a password alone would be the weaker proof. The authenticator code beside the password where a second factor is
 * required and the account has an authenticator, never in its place. A passkey wherever the account holds one, whatever
 * the installation's passkey sign-in; a company's or role's own switch still decides whether one ENTERS that company.
 *
 * A step-up asks the code where a company of the account requires a second factor, as its sign-in does; a company's
 * entry where that company requires one.
 */
final readonly class ScreenProofs
{
    public function __construct(
        private MfaRequirement $requirement,
        private PasskeyRepository $passkeys,
        private ReadSetting $settings,
        private MembershipRepository $memberships,
    ) {
    }

    public function ofStepUp(User $user): EntryProof
    {
        return $this->asked($user, $this->requirement->appliesTo($user), true);
    }

    public function ofEntry(User $user, Company $company): EntryProof
    {
        return $this->asked($user, $company->isMfaRequired(), $this->companyTakesPasskey($user, $company));
    }

    /**
     * Whether a proof counts for this account only when it was given with a second factor: one is required of it and it
     * holds one. An account that holds none yet is held by the enrolment it is asked for, which its password opens.
     */
    public function needsSecondFactor(User $user): bool
    {
        return $this->requirement->appliesTo($user) && ($user->hasTotp() || $this->passkeys->countFor($user) > 0);
    }

    /** The company's, or the member's role's, word on a passkey entering it. */
    public function companyTakesPasskey(User $user, Company $company): bool
    {
        $role = $this->memberships->ofUserInCompany($user->getId(), $company->getId())?->getRole();

        return true === $this->settings->value(new SettingContext($company, $role?->getId()), CompanyEntrySettings::PASSKEY);
    }

    private function asked(User $user, bool $required, bool $passkeyTaken): EntryProof
    {
        $holdsPasskey = $this->passkeys->countFor($user) > 0;
        $passkeyIsTheFactor = $required && !$user->hasTotp() && $holdsPasskey;

        return new EntryProof(
            $holdsPasskey && ($passkeyIsTheFactor || $passkeyTaken),
            $required && $user->hasTotp(),
            !$passkeyIsTheFactor,
        );
    }
}
