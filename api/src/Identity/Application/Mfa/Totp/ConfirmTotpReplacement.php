<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Application\Mfa\Totp;

use App\Audit\Application\AuditEntry;
use App\Audit\Application\AuditTrail;
use App\Audit\Application\DeclaresAuditActions;
use App\Audit\Domain\ValueObject\AuditScope;
use App\Audit\Domain\ValueObject\Recorded;
use App\Identity\Application\Mfa\IssueRecoveryCodes;
use App\Identity\Application\Mfa\SecondFactorRefused;
use App\Identity\Application\Mfa\SecondFactorUnreadable;
use App\Identity\Application\SecretCipher;
use App\Identity\Application\Security\SecurityChange;
use App\Identity\Application\Security\SecurityNotices;
use App\Identity\Application\Session\ManageSessions;
use App\Identity\Application\TotpCodes;
use App\Identity\Domain\Repository\UserRepository;
use App\Shared\Application\Transactions;
use Symfony\Component\Uid\Uuid;

/**
 * Step two: a code from the new app proves it read the waiting secret, and in one write the new app takes the old one's
 * place, with a new set of recovery codes (the old ones may have been kept with the phone that went). Every other
 * session of the account then ends, as one may be open on that phone; the one confirming stays.
 */
final readonly class ConfirmTotpReplacement implements DeclaresAuditActions
{
    public const string REPLACED = 'auth.totp_replaced';

    public function __construct(
        private UserRepository $users,
        private IssueRecoveryCodes $issueRecoveryCodes,
        private TotpCodes $totp,
        private SecretCipher $cipher,
        private AuditTrail $audit,
        private Transactions $transactions,
        private ManageSessions $sessions,
        private SecurityNotices $notices,
    ) {
    }

    /**
     * @return list<string> the raw recovery codes, to be shown once
     *
     * @throws SecondFactorRefused    when no replacement waits or the code is not the new app's
     * @throws SecondFactorUnreadable when the waiting secret was sealed under a key since rotated
     */
    public function handle(Uuid $userId, string $code, string $currentSessionId, ?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable();
        $waiting = $this->users->ofId($userId)?->getTotpReplacementSecret() ?? throw new SecondFactorRefused();

        try {
            $timestep = $this->totp->verify($this->cipher->decrypt($waiting), $code, $now);
        } catch (\RuntimeException) {
            // Sealed under a key since rotated: no code can confirm it; starting again seals a new one under the current key.
            throw new SecondFactorUnreadable();
        }
        if (null === $timestep) {
            throw new SecondFactorRefused();
        }

        $codes = $this->transactions->run(function () use ($userId, $waiting, $timestep, $now): array {
            // Read again under the row lock: the same code sent twice finds the replacement already made.
            $user = $this->users->lockedOfId($userId) ?? throw new SecondFactorRefused();
            if ($user->getTotpReplacementSecret() !== $waiting) {
                throw new SecondFactorRefused();
            }
            $user->confirmTotpReplacement($timestep, $now);
            $this->users->save($user);
            $codes = $this->issueRecoveryCodes->handle($user, $now);
            $this->audit->record(new AuditEntry('user', $user->getId(), self::REPLACED, $user->getId(), []));
            $this->notices->tell($user->getId(), SecurityChange::AuthenticatorReplaced);

            return $codes;
        });

        $user = $this->users->ofId($userId) ?? throw new SecondFactorRefused();
        $this->sessions->endOthers($user, $currentSessionId);

        return $codes;
    }

    public function auditActions(): array
    {
        return [
            self::REPLACED => new Recorded('user', AuditScope::Outside),
        ];
    }
}
