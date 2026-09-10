<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Identity\Infrastructure;

use App\Identity\Application\SecretCipher;
use App\Identity\Infrastructure\Mfa\SodiumSecretCipher;
use PHPUnit\Framework\TestCase;

/**
 * A TOTP secret is a bearer credential: unlike a password hash it keeps minting valid codes for as long as
 * nobody notices, so a database dump alone must not be enough to use it (ruling of 2026-09-10).
 */
final class SodiumSecretCipherTest extends TestCase
{
    private const string KEY = 'Fh0hAlB8Q7xUq0mJ0zRz2s4vXn6yKbPd8eGtWc3AjQY=';

    private SecretCipher $cipher;

    protected function setUp(): void
    {
        $this->cipher = new SodiumSecretCipher(self::KEY);
    }

    public function testWhatGoesInComesBackOut(): void
    {
        self::assertSame('JBSWY3DPEHPK3PXP', $this->cipher->decrypt($this->cipher->encrypt('JBSWY3DPEHPK3PXP')));
    }

    public function testTheSamePlaintextEncryptsDifferentlyEveryTime(): void
    {
        // A fresh nonce per call: two users with the same secret must not be visibly the same row, and
        // neither must one user's secret before and after a re-enrolment.
        self::assertNotSame($this->cipher->encrypt('JBSWY3DPEHPK3PXP'), $this->cipher->encrypt('JBSWY3DPEHPK3PXP'));
    }

    public function testTheCiphertextDoesNotCarryThePlaintext(): void
    {
        self::assertStringNotContainsString('JBSWY3DPEHPK3PXP', $this->cipher->encrypt('JBSWY3DPEHPK3PXP'));
    }

    public function testATamperedCiphertextIsRefusedRatherThanDecryptedToRubbish(): void
    {
        $cipher = $this->cipher->encrypt('JBSWY3DPEHPK3PXP');
        $tampered = substr($cipher, 0, -4).('AAAA' === substr($cipher, -4) ? 'BBBB' : 'AAAA');

        $this->expectException(\RuntimeException::class);
        $this->cipher->decrypt($tampered);
    }

    public function testAnotherKeyCannotOpenIt(): void
    {
        $other = new SodiumSecretCipher(base64_encode(str_repeat("\x01", \SODIUM_CRYPTO_SECRETBOX_KEYBYTES)));

        $this->expectException(\RuntimeException::class);
        $other->decrypt($this->cipher->encrypt('JBSWY3DPEHPK3PXP'));
    }

    public function testRubbishIsRefusedRatherThanCrashing(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->cipher->decrypt('not-base64-and-far-too-short');
    }

    public function testAKeyOfTheWrongSizeIsRefusedAtConstructionRatherThanAtUse(): void
    {
        // Fail fast: a short key discovered on the first enrolment is a production incident, not a config typo.
        $this->expectException(\InvalidArgumentException::class);
        new SodiumSecretCipher(base64_encode('too-short'));
    }
}
