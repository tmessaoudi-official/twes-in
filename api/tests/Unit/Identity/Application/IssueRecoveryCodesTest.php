<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Identity\Application;

use App\Identity\Application\Mfa\IssueRecoveryCodes;
use App\Identity\Domain\Model\RecoveryCodeEntry;
use App\Identity\Domain\Model\User;
use App\Identity\Domain\Repository\RecoveryCodeRepository;
use App\Identity\Domain\ValueObject\Email;
use App\Identity\Domain\ValueObject\RecoveryCode;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

/**
 * A set holds as many codes as the operator's app.recovery_codes.per_set says (ten as shipped), each stored as its hash
 * alone, the raw codes handed back to be shown once.
 */
final class IssueRecoveryCodesTest extends TestCase
{
    #[TestWith([10])]
    #[TestWith([8])]
    #[TestWith([16])]
    public function testASetHoldsAsManyCodesAsTheOperatorSets(int $perSet): void
    {
        /** @var \ArrayObject<int, RecoveryCodeEntry> $stored */
        $stored = new \ArrayObject();
        $repository = new class($stored) implements RecoveryCodeRepository {
            /** @param \ArrayObject<int, RecoveryCodeEntry> $stored */
            public function __construct(private readonly \ArrayObject $stored)
            {
            }

            public function unspent(User $user, string $codeHash): ?RecoveryCodeEntry
            {
                return null;
            }

            public function ofUser(User $user): array
            {
                return array_values($this->stored->getArrayCopy());
            }

            public function replaceAll(User $user, array $entries): void
            {
                $this->stored->exchangeArray($entries);
            }

            public function spend(RecoveryCodeEntry $entry): void
            {
            }

            public function countFor(User $user): int
            {
                return \count($this->stored);
            }
        };
        $user = new User(Email::fromString('someone@example.test'), 'Someone', 'fr', new \DateTimeImmutable());

        $raw = new IssueRecoveryCodes($repository, $perSet)->handle($user, new \DateTimeImmutable('2026-11-02 12:00:00'));

        self::assertCount($perSet, $raw);
        self::assertCount($perSet, $stored);
        self::assertSame(
            array_map(static fn (string $code): string => RecoveryCode::hashOf($code), $raw),
            array_map(static fn (RecoveryCodeEntry $entry): string => $entry->getCodeHash(), $stored->getArrayCopy()),
        );
    }
}
