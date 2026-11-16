<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Identity\Application;

use App\Identity\Application\Password\NewPasswordPolicy;
use App\Identity\Application\Password\NewPasswordRefused;
use App\Tests\Support\FakeBreachedPasswordCheck;
use PHPUnit\Framework\TestCase;

final class NewPasswordPolicyTest extends TestCase
{
    public function testAPasswordIsHeldToTheLengthTheOperatorSet(): void
    {
        $policy = new NewPasswordPolicy(new FakeBreachedPasswordCheck(false), 14);

        self::assertSame(14, $policy->minLength());
        self::assertFalse($policy->check(str_repeat('a', 14)));
        try {
            $policy->check(str_repeat('a', 13));
            self::fail('a password one character short of the length was accepted');
        } catch (NewPasswordRefused $refused) {
            self::assertSame(NewPasswordRefused::TOO_SHORT, $refused->reason);
            self::assertSame(14, $refused->min, 'the refusal names the length asked, so the screen can say it');
        }
    }

    public function testWithNoLengthSetItIsTheFloorOfTwelve(): void
    {
        $policy = new NewPasswordPolicy(new FakeBreachedPasswordCheck(false));

        self::assertSame(NewPasswordPolicy::MIN_LENGTH, $policy->minLength());
        self::assertSame(12, NewPasswordPolicy::MIN_LENGTH);
        self::assertFalse($policy->check(str_repeat('a', 12)));
    }

    public function testNoLengthBelowTheFloorIsTaken(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new NewPasswordPolicy(new FakeBreachedPasswordCheck(false), 11);
    }

    public function testTheLongestPasswordStaysTheSameWhateverTheLength(): void
    {
        $policy = new NewPasswordPolicy(new FakeBreachedPasswordCheck(false), 20);

        $this->expectException(NewPasswordRefused::class);
        $policy->check(str_repeat('a', NewPasswordPolicy::MAX_LENGTH + 1));
    }
}
