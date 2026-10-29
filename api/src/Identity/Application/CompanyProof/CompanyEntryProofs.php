<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Application\CompanyProof;

use App\Identity\Application\Login\SignInFactors;
use App\Identity\Application\Login\SwitchPasskeySignIn;
use App\Identity\Domain\Model\User;
use App\Identity\Domain\Repository\PasskeyRepository;
use App\Settings\Application\ReadSetting;
use App\Settings\Application\SettingContext;
use App\Tenancy\Domain\Model\Company;
use App\Tenancy\Domain\Repository\MembershipRepository;

/**
 * The proof a company takes of a member entering it. A passkey with user verification, where the installation lets one
 * sign in and the company, or the member's role in it, takes one: it is a stronger proof than a password, and one touch.
 * The password everywhere, with the authenticator code where the company requires a second factor; an account whose only
 * second factor is a passkey gives that passkey instead of a code, as it does when it signs in.
 */
final readonly class CompanyEntryProofs
{
    public function __construct(
        private ReadSetting $settings,
        private MembershipRepository $memberships,
        private PasskeyRepository $passkeys,
        private SwitchPasskeySignIn $passkeySignIn,
    ) {
    }

    public function of(User $user, Company $company): EntryProof
    {
        $code = $company->isMfaRequired() && $user->hasTotp();
        $passkeyAsFactor = $company->isMfaRequired() && !$user->hasTotp() && $this->passkeys->countFor($user) > 0;

        return new EntryProof(
            ($passkeyAsFactor || $this->takesPasskey($user, $company)) && $this->passkeys->countFor($user) > 0,
            $code,
            // A password alone would enter a company that requires a second factor; an account with none at all is held
            // by the enrolment the company requires before it acts anywhere, so its password stays its way in.
            !$passkeyAsFactor,
        );
    }

    /** Whether the factors a sign-in was proved with are ones the company it opens takes. */
    public function satisfiedBy(SignInFactors $factors, User $user, Company $company): bool
    {
        return match ($factors) {
            SignInFactors::PasswordAndFactor => true,
            SignInFactors::Password => !$company->isMfaRequired(),
            SignInFactors::Passkey => $this->takesPasskey($user, $company),
        };
    }

    public function takesPasskey(User $user, Company $company): bool
    {
        if (!$this->passkeySignIn->allowedHere()) {
            return false;
        }
        $role = $this->memberships->ofUserInCompany($user->getId(), $company->getId())?->getRole();

        return true === $this->settings->value(new SettingContext($company, $role?->getId()), CompanyEntrySettings::PASSKEY);
    }
}
