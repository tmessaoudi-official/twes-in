<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Application\Mfa;

use App\Identity\Domain\RecoveryCode;
use App\Identity\Domain\RecoveryCodeEntry;
use App\Identity\Domain\RecoveryCodeRepository;
use App\Identity\Domain\User;

/**
 * A fresh set of recovery codes in place of whatever the account had. Only their SHA-256 is stored: the raw codes are
 * returned to be shown once (ruling of 2026-09-10) and exist nowhere else afterwards.
 */
final readonly class IssueRecoveryCodes
{
    public function __construct(private RecoveryCodeRepository $recoveryCodes)
    {
    }

    /** @return list<string> the raw recovery codes */
    public function handle(User $user, \DateTimeImmutable $now): array
    {
        $codes = RecoveryCode::generateSet();
        $this->recoveryCodes->replaceAll($user, array_map(
            static fn (RecoveryCode $c): RecoveryCodeEntry => new RecoveryCodeEntry($user, $c->hash(), $now),
            $codes,
        ));

        return array_map(static fn (RecoveryCode $c): string => $c->raw, $codes);
    }
}
