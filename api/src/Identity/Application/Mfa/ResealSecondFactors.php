<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Application\Mfa;

use App\Identity\Application\SecretCipher;
use App\Identity\Domain\Repository\UserRepository;
use App\Shared\Application\Transactions;

/**
 * Moves every stored authenticator secret from the previous APP_MFA_KEY to the current one, so a key rotation strands
 * nobody. Every value is opened before any is written: one that neither key opens refuses the whole run, since
 * resealing the rest would leave a table half under each key and nobody able to say which. A value the current key
 * already opens is left as it is, so a run repeated after a success writes nothing.
 */
final readonly class ResealSecondFactors
{
    public function __construct(
        private UserRepository $users,
        private SecretCipher $current,
        private Transactions $transactions,
    ) {
    }

    /** @throws SecondFactorsUnreadable when a stored secret opens with neither key; nothing is written then */
    public function from(SecretCipher $previous): int
    {
        return $this->transactions->run(function () use ($previous): int {
            $resealed = [];
            $unreadable = 0;
            foreach ($this->users->withTotpSecret() as $user) {
                $sealed = (string) $user->getTotpSecret();
                if (null !== self::opened($this->current, $sealed)) {
                    continue;
                }
                $plain = self::opened($previous, $sealed);
                if (null === $plain) {
                    ++$unreadable;
                    continue;
                }
                $resealed[] = [$user, $plain];
            }
            if ($unreadable > 0) {
                throw new SecondFactorsUnreadable($unreadable);
            }
            foreach ($resealed as [$user, $plain]) {
                $user->resealTotpSecret($this->current->encrypt($plain));
                $this->users->save($user);
            }

            return \count($resealed);
        });
    }

    private static function opened(SecretCipher $cipher, string $sealed): ?string
    {
        try {
            return $cipher->decrypt($sealed);
        } catch (\RuntimeException) {
            return null;
        }
    }
}
