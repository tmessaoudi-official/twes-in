<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Application\CompanyProof;

use App\Identity\Application\Login\SignInFactors;
use App\Identity\Application\Login\SwitchPasskeySignIn;
use App\Identity\Application\StepUp\ScreenProofs;
use App\Identity\Domain\Model\User;
use App\Tenancy\Domain\Model\Company;

/**
 * Whether the sign-in that opened a company already proved the member's entry into it. What an entry asks otherwise is
 * the step-up's canon, `ScreenProofs::ofEntry`.
 */
final readonly class CompanyEntryProofs
{
    public function __construct(
        private ScreenProofs $screenProofs,
        private SwitchPasskeySignIn $passkeySignIn,
    ) {
    }

    /** Whether the factors a sign-in was proved with are ones the company it opens takes. */
    public function satisfiedBy(SignInFactors $factors, User $user, Company $company): bool
    {
        return match ($factors) {
            SignInFactors::PasswordAndFactor => true,
            SignInFactors::Password => !$company->isMfaRequired(),
            // A passkey sign-in exists only while the installation allows one; one switched off since is asked again.
            SignInFactors::Passkey => $this->passkeySignIn->allowedHere() && $this->screenProofs->companyTakesPasskey($user, $company),
        };
    }
}
