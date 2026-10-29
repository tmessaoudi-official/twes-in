<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Application\Login;

use App\Audit\Application\AuditEntry;
use App\Audit\Application\AuditTrail;
use App\Audit\Application\DeclaresAuditActions;
use App\Audit\Domain\ValueObject\AuditScope;
use App\Audit\Domain\ValueObject\Recorded;
use App\Identity\Application\Mfa\Passkey\PasskeyRefused;
use App\Identity\Application\PasskeyCeremonies;
use App\Identity\Domain\Model\User;
use App\Identity\Domain\Repository\PasskeyRepository;
use App\Shared\Application\Transactions;

/**
 * A sign-in with a passkey alone: the device found the passkey without an address typed, and its user verification (a
 * PIN, a fingerprint, a face) stands for the second step. Whose account it opens comes from the passkey, never from the
 * screen; the account must have let it, beside an authenticator app, at an installation that allows it. Every refusal is
 * the same to the caller, so nobody learns from it that an account exists or how it signs in; the trail keeps why.
 * Neither the password's lock nor its attempts are looked at: a passkey is another key.
 */
final readonly class SignInWithPasskey implements DeclaresAuditActions
{
    public const string REFUSED = 'auth.passkey_sign_in_refused';

    public function __construct(
        private PasskeyRepository $passkeys,
        private PasskeyCeremonies $ceremonies,
        private SwitchPasskeySignIn $switch,
        private AuditTrail $audit,
        private Transactions $transactions,
    ) {
    }

    /** Request options naming no passkey, so the device offers whichever of its own it holds for this site. */
    public function options(): string
    {
        return $this->ceremonies->requestOptions([]);
    }

    /** @throws PasskeyRefused whatever the reason, which the trail records */
    public function handle(string $optionsJson, string $credentialJson, ?\DateTimeImmutable $now = null): User
    {
        $now ??= new \DateTimeImmutable();
        $credentialId = $this->ceremonies->credentialIdOf($credentialJson);
        $passkey = null === $credentialId ? null : $this->passkeys->ofCredentialId($credentialId);
        if (null === $passkey) {
            throw $this->refused(null, 'unknown_passkey');
        }
        $user = $passkey->getUser();
        try {
            $record = $this->ceremonies->verifyAssertion($optionsJson, $credentialJson, $passkey->getRecord(), null);
        } catch (PasskeyRefused $refused) {
            throw $this->refused($user, 'bad_assertion', $refused);
        }
        // Looked at once the passkey is proved, so the answer to anyone else is the same as for a passkey nobody holds.
        if (!$this->switch->allowedHere()) {
            throw $this->refused($user, 'installation_off');
        }
        if (!$user->signsInWithPasskey()) {
            throw $this->refused($user, 'switched_off');
        }
        if (!$user->hasTotp()) {
            throw $this->refused($user, 'no_authenticator');
        }
        // A refusal is recorded on its own, the sign-in failing; the passkey's new counter is kept as one change.
        $this->transactions->run(function () use ($passkey, $record, $now): void {
            $passkey->recordUse($record, $now);
            $this->passkeys->save($passkey);
        });

        return $user;
    }

    private function refused(?User $user, string $reason, ?\Throwable $previous = null): PasskeyRefused
    {
        $this->audit->record(new AuditEntry('user', $user?->getId(), self::REFUSED, $user?->getId(), ['reason' => $reason]));

        return PasskeyRefused::because($reason, $previous);
    }

    public function auditActions(): array
    {
        return [
            self::REFUSED => new Recorded('user', AuditScope::Outside),
        ];
    }
}
