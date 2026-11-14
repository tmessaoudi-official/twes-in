<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Support;

use App\Identity\Application\CompanyProof\CompanyEntrySettings;
use App\Identity\Application\Mfa\SecondFactors;
use App\Identity\Application\Mfa\VerifySecondFactor;
use App\Identity\Application\SecretCipher;
use App\Identity\Application\StepUp\ScreenProofs;
use App\Identity\Application\TotpCodes;
use App\Identity\Domain\Repository\PasskeyRepository;
use App\Identity\Domain\Repository\RecoveryCodeRepository;
use App\Identity\Domain\Repository\UserRepository;
use App\Settings\Application\ReadSetting;
use App\Settings\Application\ResolveSettings;
use App\Settings\Application\SettingCatalog;
use App\Tenancy\Application\Mfa\MfaRequirement;
use App\Tenancy\Domain\Repository\MembershipRepository;
use Psr\Clock\ClockInterface;

/** The step-up's real collaborators over the doubles a unit test gives them: what it asks, and the second factor's check. */
final class StepUpDoubles
{
    public static function screenProofs(MembershipRepository $memberships, PasskeyRepository $passkeys): ScreenProofs
    {
        return new ScreenProofs(
            new MfaRequirement($memberships),
            $passkeys,
            new ReadSetting(new ResolveSettings(new SettingCatalog([new CompanyEntrySettings()]), new InMemorySettings())),
            $memberships,
        );
    }

    public static function secondFactor(UserRepository $users, RecoveryCodeRepository $recoveryCodes, TotpCodes $totp, SecretCipher $cipher, PasskeyRepository $passkeys, ClockInterface $clock): VerifySecondFactor
    {
        return new VerifySecondFactor($users, $recoveryCodes, $totp, $cipher, new InMemoryAuditTrail(), new SecondFactors($passkeys), new FakeTransactions(), $clock);
    }
}
