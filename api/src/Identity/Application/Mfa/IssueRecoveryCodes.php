<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Application\Mfa;

use App\Identity\Domain\Model\RecoveryCodeEntry;
use App\Identity\Domain\Model\User;
use App\Identity\Domain\Repository\RecoveryCodeRepository;
use App\Identity\Domain\ValueObject\RecoveryCode;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * A fresh set of recovery codes in place of whatever the account had. Only their SHA-256 is stored: the raw codes are
 * returned to be shown once (ruling of 2026-09-10) and exist nowhere else afterwards.
 */
final readonly class IssueRecoveryCodes
{
    public function __construct(
        private RecoveryCodeRepository $recoveryCodes,
        /** How many codes a set holds, the operator's within RecoveryCodeBounds. */
        #[Autowire(param: 'app.recovery_codes.per_set')]
        private int $perSet,
    ) {
    }

    /** @return list<string> the raw recovery codes */
    public function handle(User $user, \DateTimeImmutable $now): array
    {
        $codes = RecoveryCode::generateSet($this->perSet);
        $this->recoveryCodes->replaceAll($user, array_map(
            static fn (RecoveryCode $c): RecoveryCodeEntry => new RecoveryCodeEntry($user, $c->hash(), $now),
            $codes,
        ));

        return array_map(static fn (RecoveryCode $c): string => $c->raw, $codes);
    }
}
