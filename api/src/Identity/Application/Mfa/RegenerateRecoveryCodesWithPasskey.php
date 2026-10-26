<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Application\Mfa;

use App\Audit\Application\AuditEntry;
use App\Audit\Application\AuditTrail;
use App\Audit\Application\DeclaresAuditActions;
use App\Audit\Domain\ValueObject\AuditScope;
use App\Identity\Application\Mfa\Passkey\PasskeyAssertions;
use App\Identity\Application\Mfa\Passkey\PasskeyRefused;
use App\Identity\Application\Security\SecurityChange;
use App\Identity\Application\Security\SecurityNotices;
use App\Identity\Domain\Repository\UserRepository;
use App\Shared\Application\Transactions;
use Symfony\Component\Uid\Uuid;

/**
 * A new set of recovery codes against one of the account's passkeys: the proof an account whose factors are passkeys can
 * give, where RegenerateRecoveryCodes asks for an authenticator code (ruling of 2026-09-10: regenerable with a current
 * factor). A recovery code is still never proof enough, for the reason given there.
 */
final readonly class RegenerateRecoveryCodesWithPasskey implements DeclaresAuditActions
{
    public function __construct(
        private UserRepository $users,
        private PasskeyAssertions $assertions,
        private IssueRecoveryCodes $issueRecoveryCodes,
        private AuditTrail $audit,
        private Transactions $transactions,
        private SecurityNotices $notices,
    ) {
    }

    /**
     * @return list<string> the raw recovery codes, to be shown once
     *
     * @throws PasskeyRefused when the credential is not one of the account's passkeys answering the options
     */
    public function handle(Uuid $userId, string $optionsJson, string $credentialJson, ?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable();
        $user = $this->users->ofId($userId) ?? throw new PasskeyRefused();

        return $this->transactions->run(function () use ($user, $optionsJson, $credentialJson, $now): array {
            // The passkey's new signature counter is saved by the verification, so it commits with the codes it paid for.
            $this->assertions->verify($user, $optionsJson, $credentialJson, $now);
            $codes = $this->issueRecoveryCodes->handle($user, $now);

            $this->audit->record(new AuditEntry('user', $user->getId(), RegenerateRecoveryCodes::REGENERATED, $user->getId(), [
                'count' => \count($codes),
                'method' => 'passkey',
            ]));
            $this->notices->tell($user->getId(), SecurityChange::RecoveryCodesRenewed);

            return $codes;
        });
    }

    public function auditActions(): array
    {
        return [
            RegenerateRecoveryCodes::REGENERATED => AuditScope::Outside,
        ];
    }
}
