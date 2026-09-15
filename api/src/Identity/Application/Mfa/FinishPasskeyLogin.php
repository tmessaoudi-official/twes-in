<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Application\Mfa;

use App\Audit\Application\AuditEntry;
use App\Audit\Application\AuditTrail;
use App\Identity\Application\PasskeyCeremonies;
use App\Identity\Domain\PasskeyRepository;
use App\Identity\Domain\User;
use App\Identity\Domain\UserRepository;
use Symfony\Component\Uid\Uuid;

/**
 * Completes a login that owed a second factor with a passkey instead of a code.
 *
 * The credential is looked up among the pending account's own passkeys only, so someone else's passkey fails exactly as
 * an unknown one does. The record saved after a success carries the new signature counter.
 */
final readonly class FinishPasskeyLogin
{
    public function __construct(
        private UserRepository $users,
        private PasskeyRepository $passkeys,
        private PasskeyCeremonies $ceremonies,
        private AuditTrail $audit,
    ) {
    }

    /** @throws PasskeyRefused */
    public function handle(Uuid $userId, string $optionsJson, string $credentialJson, ?\DateTimeImmutable $now = null): User
    {
        $now ??= new \DateTimeImmutable();
        $user = $this->users->ofId($userId);

        if (null === $user) {
            $this->audit->record(new AuditEntry('user', null, VerifySecondFactor::FAILED, null, ['reason' => 'no_factor']));

            throw new PasskeyRefused();
        }

        $credentialId = $this->ceremonies->credentialIdOf($credentialJson);
        $passkey = null === $credentialId ? null : $this->passkeys->ofUserAndCredentialId($user, $credentialId);

        if (null === $passkey) {
            $this->audit->record(new AuditEntry('user', $user->getId(), VerifySecondFactor::FAILED, $user->getId(), ['reason' => 'unknown_passkey']));

            throw new PasskeyRefused();
        }

        try {
            $record = $this->ceremonies->verifyAssertion($optionsJson, $credentialJson, $passkey->getRecord(), $user->getId()->toRfc4122());
        } catch (PasskeyRefused $refused) {
            $this->audit->record(new AuditEntry('user', $user->getId(), VerifySecondFactor::FAILED, $user->getId(), ['reason' => 'bad_assertion']));

            throw $refused;
        }

        $passkey->recordUse($record, $now);
        $this->passkeys->save($passkey);
        $this->audit->record(new AuditEntry('user', $user->getId(), VerifySecondFactor::VERIFIED, $user->getId(), ['method' => 'passkey']));

        return $user;
    }
}
