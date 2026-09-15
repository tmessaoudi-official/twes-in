<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Application\Mfa;

use App\Identity\Application\PasskeyCeremonies;
use App\Identity\Domain\PasskeyRepository;
use App\Identity\Domain\User;

/**
 * Proof that the person holds one of the account's own passkeys, which both the second step of a login and the
 * replacement of the recovery codes ask for.
 *
 * The credential is looked up among that account's passkeys only, so someone else's passkey fails exactly as an unknown
 * one does. The record saved after a success carries the new signature counter.
 */
final readonly class PasskeyAssertions
{
    public function __construct(
        private PasskeyRepository $passkeys,
        private PasskeyCeremonies $ceremonies,
    ) {
    }

    /** @throws PasskeyRefused whose reason is unknown_passkey or bad_assertion */
    public function verify(User $user, string $optionsJson, string $credentialJson, \DateTimeImmutable $now): void
    {
        $credentialId = $this->ceremonies->credentialIdOf($credentialJson);
        $passkey = null === $credentialId ? null : $this->passkeys->ofUserAndCredentialId($user, $credentialId);

        if (null === $passkey) {
            throw PasskeyRefused::because('unknown_passkey');
        }

        try {
            $record = $this->ceremonies->verifyAssertion($optionsJson, $credentialJson, $passkey->getRecord(), $user->getId()->toRfc4122());
        } catch (PasskeyRefused $refused) {
            throw PasskeyRefused::because('bad_assertion', $refused);
        }

        $passkey->recordUse($record, $now);
        $this->passkeys->save($passkey);
    }
}
