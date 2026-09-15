<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Application\Mfa;

use App\Identity\Application\SecretCipher;
use App\Identity\Application\TotpCodes;
use App\Identity\Domain\UserRepository;
use Symfony\Component\Uid\Uuid;

/**
 * Step one of enrolment: a secret is generated and stored pending.
 *
 * It is not a second factor yet. Nothing about the account changes until a real code proves the authenticator
 * read the secret correctly, which is what keeps a mis-scanned QR code from enabling a factor nobody can
 * satisfy. Starting again replaces a pending secret, never one already in force.
 */
final readonly class BeginTotpEnrolment
{
    public function __construct(
        private UserRepository $users,
        private TotpCodes $totp,
        private SecretCipher $cipher,
        private string $issuer,
    ) {
    }

    public function handle(Uuid $userId): TotpEnrolment
    {
        $user = $this->users->ofId($userId);

        if (null === $user) {
            throw new \DomainException('No such user.');
        }

        if ($user->hasTotp()) {
            throw new SecondFactorAlreadyEnrolled();
        }

        $secret = $this->totp->generateSecret();
        $user->beginTotpEnrolment($this->cipher->encrypt($secret));
        $this->users->save($user);

        // The plaintext secret leaves here exactly once, to be shown to its owner and never stored in clear.
        return new TotpEnrolment($secret, $this->totp->provisioningUri($secret, $user->getEmail()->value, $this->issuer));
    }
}
