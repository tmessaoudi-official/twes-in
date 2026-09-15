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
use App\Identity\Domain\Passkey;
use App\Identity\Domain\PasskeyRepository;
use App\Identity\Domain\RecoveryCode;
use App\Identity\Domain\RecoveryCodeEntry;
use App\Identity\Domain\RecoveryCodeRepository;
use App\Identity\Domain\UserRepository;
use Symfony\Component\Uid\Uuid;

/**
 * Step two: the credential answers the options, and the passkey is in force.
 *
 * When it is the account's first factor the recovery codes are issued with it, as they are with a first authenticator
 * app, because from this moment a lost device would otherwise lock the account. A later passkey issues none: the codes
 * already out there stay valid.
 */
final readonly class RegisterPasskey
{
    public const string REGISTERED = 'auth.passkey_registered';

    public function __construct(
        private UserRepository $users,
        private PasskeyRepository $passkeys,
        private RecoveryCodeRepository $recoveryCodes,
        private PasskeyCeremonies $ceremonies,
        private SecondFactors $secondFactors,
        private AuditTrail $audit,
    ) {
    }

    /** @throws PasskeyRefused when the credential does not verify or is already registered */
    public function handle(Uuid $userId, string $optionsJson, string $credentialJson, string $name, ?\DateTimeImmutable $now = null): RegisteredPasskey
    {
        $now ??= new \DateTimeImmutable();
        $user = $this->users->ofId($userId) ?? throw new PasskeyRefused();
        $verified = $this->ceremonies->verifyRegistration($optionsJson, $credentialJson);

        if ($this->passkeys->existsWithCredentialId($verified->credentialId)) {
            throw new PasskeyRefused();
        }

        $first = !$this->secondFactors->has($user);
        $passkey = new Passkey($user, $verified->credentialId, $verified->record, $name, $now);
        $this->passkeys->save($passkey);

        $codes = [];
        if ($first) {
            $set = RecoveryCode::generateSet();
            $this->recoveryCodes->replaceAll($user, array_map(
                static fn (RecoveryCode $c): RecoveryCodeEntry => new RecoveryCodeEntry($user, $c->hash(), $now),
                $set,
            ));
            $codes = array_map(static fn (RecoveryCode $c): string => $c->raw, $set);
        }

        $this->audit->record(new AuditEntry('user', $user->getId(), self::REGISTERED, $user->getId(), [
            'passkey' => $passkey->getId()->toRfc4122(),
            'recoveryCodesIssued' => $first,
        ]));

        return new RegisteredPasskey($passkey, $codes);
    }
}
