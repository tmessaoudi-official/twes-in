<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Application\Mfa\Passkey;

use App\Audit\Application\AuditEntry;
use App\Audit\Application\AuditTrail;
use App\Audit\Application\DeclaresAuditActions;
use App\Audit\Domain\ValueObject\AuditScope;
use App\Identity\Application\Mfa\IssueRecoveryCodes;
use App\Identity\Application\Mfa\SecondFactors;
use App\Identity\Application\PasskeyCeremonies;
use App\Identity\Application\Security\SecurityChange;
use App\Identity\Application\Security\SecurityNotices;
use App\Identity\Domain\Model\Passkey;
use App\Identity\Domain\Repository\PasskeyRepository;
use App\Identity\Domain\Repository\UserRepository;
use App\Shared\Application\Transactions;
use Symfony\Component\Uid\Uuid;

/**
 * Step two: the credential answers the options, and the passkey is in force.
 *
 * When it is the account's first factor the recovery codes are issued with it, as they are with a first authenticator
 * app, because from this moment a lost device would otherwise lock the account. A later passkey issues none: the codes
 * already out there stay valid.
 */
final readonly class RegisterPasskey implements DeclaresAuditActions
{
    public const string REGISTERED = 'auth.passkey_registered';

    public function __construct(
        private UserRepository $users,
        private PasskeyRepository $passkeys,
        private IssueRecoveryCodes $issueRecoveryCodes,
        private PasskeyCeremonies $ceremonies,
        private SecondFactors $secondFactors,
        private AuditTrail $audit,
        private Transactions $transactions,
        private SecurityNotices $notices,
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

        return $this->transactions->run(function () use ($user, $verified, $name, $now): RegisteredPasskey {
            $first = !$this->secondFactors->has($user);
            $passkey = new Passkey($user, $verified->credentialId, $verified->record, $name, $now);
            $this->passkeys->save($passkey);

            $codes = $first ? $this->issueRecoveryCodes->handle($user, $now) : [];

            $this->audit->record(new AuditEntry('user', $user->getId(), self::REGISTERED, $user->getId(), [
                'passkey' => $passkey->getId()->toRfc4122(),
                'recoveryCodesIssued' => $first,
            ]));
            $this->notices->tell($user->getId(), SecurityChange::PasskeyAdded, $name);

            return new RegisteredPasskey($passkey, $codes);
        });
    }

    public function auditActions(): array
    {
        return [
            self::REGISTERED => AuditScope::Outside,
        ];
    }
}
