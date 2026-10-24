<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Application\Mfa\Totp;

use App\Identity\Application\SecretCipher;
use App\Identity\Application\StepUp\ConfirmStepUp;
use App\Identity\Application\StepUp\StepUpRequired;
use App\Identity\Application\TotpCodes;
use App\Identity\Domain\Repository\UserRepository;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;

/**
 * Step one of moving an authenticator to a new phone or app: a new secret waits beside the one in force, which keeps
 * signing in until a code from the new app confirms it (ConfirmTotpReplacement). Without it, an account whose last
 * factor is its authenticator, every operator's, could change apps only from the server: removing the last factor is
 * refused, and a new one is refused while one is in force. The proof asked is the password or a passkey, never the old
 * app's code, which is what someone whose phone was lost no longer has.
 */
final readonly class BeginTotpReplacement
{
    public function __construct(
        private UserRepository $users,
        private TotpCodes $totp,
        private SecretCipher $cipher,
        private ConfirmStepUp $stepUp,
        #[Autowire(param: 'app.mfa.issuer')]
        private string $issuer,
    ) {
    }

    /**
     * @throws StepUpRequired           when this sign-in has no fresh proof: a taken session must not swap in an app of its own
     * @throws AuthenticatorNotEnrolled when the account has no authenticator in force to replace
     */
    public function handle(Uuid $userId): TotpEnrolment
    {
        if (!$this->stepUp->isRecent($userId)) {
            throw new StepUpRequired();
        }

        $user = $this->users->ofId($userId);
        if (null === $user || !$user->hasTotp()) {
            throw new AuthenticatorNotEnrolled();
        }

        $secret = $this->totp->generateSecret();
        $user->beginTotpReplacement($this->cipher->encrypt($secret));
        $this->users->save($user);

        // The plaintext secret leaves here exactly once, to be shown to its owner and never stored in clear.
        return new TotpEnrolment($secret, $this->totp->provisioningUri($secret, $user->getEmail()->value, $this->issuer));
    }
}
