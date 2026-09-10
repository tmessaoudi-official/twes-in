<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Identity\Domain;

use App\Identity\Domain\RecoveryCode;
use PHPUnit\Framework\TestCase;

/**
 * The way back in when the phone is gone. Stored as SHA-256 like the invitation token and unlike a password:
 * these are high-entropy values nobody types twice, so a slow hash would buy nothing and would turn one
 * verification into ten argon2 passes (ruling of 2026-09-10).
 */
final class RecoveryCodeTest extends TestCase
{
    public function testAGeneratedCodeIsReadableAndUnambiguous(): void
    {
        $code = RecoveryCode::generate();

        // Someone reads these off a screen and types them back months later, so the alphabet excludes the
        // characters people confuse: no O/0, no I/1/l.
        self::assertMatchesRegularExpression('/^[abcdefghjkmnpqrstuvwxyz23456789]{5}-[abcdefghjkmnpqrstuvwxyz23456789]{5}$/', $code->raw);
    }

    public function testTwoCodesAreNotTheSame(): void
    {
        self::assertNotSame(RecoveryCode::generate()->raw, RecoveryCode::generate()->raw);
    }

    public function testTheHashIsWhatGetsStoredAndTheRawValueIsNotDerivableFromIt(): void
    {
        $code = RecoveryCode::generate();

        self::assertSame(hash('sha256', $code->raw), $code->hash());
        self::assertStringNotContainsString($code->raw, $code->hash());
    }

    public function testTheSameCodeAlwaysHashesTheSameWaySoItCanBeLookedUp(): void
    {
        self::assertSame(RecoveryCode::hashOf('abcde-fghjk'), RecoveryCode::hashOf('abcde-fghjk'));
    }

    public function testCaseAndSurroundingSpaceDoNotMatterWhenSomeoneTypesItBack(): void
    {
        // A person copying from paper will capitalise and will leave a trailing space.
        self::assertSame(RecoveryCode::hashOf('abcde-fghjk'), RecoveryCode::hashOf('  ABCDE-FGHJK '));
    }

    public function testASetIsTenDistinctCodes(): void
    {
        $set = RecoveryCode::generateSet();

        self::assertCount(10, $set);
        self::assertCount(10, array_unique(array_map(static fn (RecoveryCode $c): string => $c->raw, $set)));
    }
}
