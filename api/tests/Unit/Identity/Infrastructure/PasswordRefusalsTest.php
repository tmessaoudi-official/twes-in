<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Identity\Infrastructure;

use App\Identity\Application\Password\NewPasswordRefused;
use App\Identity\Infrastructure\Password\PasswordRefusals;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A new password refused is a violation on the field the person typed it in, as the change and reset screens send it:
 * a wrong current password on `currentPassword`, every reason of the new one on `newPassword`.
 */
final class PasswordRefusalsTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function reasons(): iterable
    {
        yield 'the current password is wrong' => [NewPasswordRefused::CURRENT_PASSWORD, 'currentPassword'];
        yield 'the new one is too short' => [NewPasswordRefused::TOO_SHORT, 'newPassword'];
        yield 'the new one is the current one' => [NewPasswordRefused::UNCHANGED, 'newPassword'];
        yield 'the new one is in a known breach' => [NewPasswordRefused::BREACHED, 'newPassword'];
    }

    #[DataProvider('reasons')]
    public function testEachReasonIsRefusedOnTheFieldItWasTypedIn(string $reason, string $field): void
    {
        $found = [];
        foreach (PasswordRefusals::of(new NewPasswordRefused($reason))->getConstraintViolationList() as $violation) {
            $payload = $violation->getConstraint()?->payload;
            self::assertIsArray($payload);
            $found[] = [$violation->getPropertyPath(), $payload['reason'] ?? null, $payload['params'] ?? []];
        }

        self::assertSame([[$field, $reason, []]], $found);
    }

    public function testEveryReasonTheRefusalDeclaresIsPlaced(): void
    {
        $declared = array_values((new \ReflectionClass(NewPasswordRefused::class))->getConstants());

        self::assertEqualsCanonicalizing($declared, array_column(iterator_to_array(self::reasons(), false), 0), 'a reason added to NewPasswordRefused needs its field here');
    }
}
