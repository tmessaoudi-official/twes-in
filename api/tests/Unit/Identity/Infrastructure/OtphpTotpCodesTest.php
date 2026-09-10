<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Identity\Infrastructure;

use App\Identity\Application\TotpCodes;
use App\Identity\Infrastructure\Mfa\OtphpTotpCodes;
use PHPUnit\Framework\TestCase;

/**
 * The TOTP arithmetic. The port answers with the timestep a code belonged to rather than a bare bool,
 * because that is what makes a replay refusable: a code is valid arithmetically for as long as its window
 * lasts, so the only thing that distinguishes the second use from the first is which step it came from.
 */
final class OtphpTotpCodesTest extends TestCase
{
    private const int PERIOD = 30;

    private TotpCodes $codes;

    protected function setUp(): void
    {
        $this->codes = new OtphpTotpCodes();
    }

    public function testAGeneratedSecretIsBase32AndLongEnoughToBeWorthGenerating(): void
    {
        $secret = $this->codes->generateSecret();

        self::assertMatchesRegularExpression('/^[A-Z2-7]+$/', $secret);
        // RFC 4226 asks for 128 bits and recommends 160; base32 of 160 bits is 32 characters.
        self::assertGreaterThanOrEqual(32, \strlen($secret));
    }

    public function testTwoSecretsAreNotTheSame(): void
    {
        self::assertNotSame($this->codes->generateSecret(), $this->codes->generateSecret());
    }

    public function testTheProvisioningUriCarriesTheIssuerAndTheAccountSoAnAuthenticatorCanLabelIt(): void
    {
        $uri = $this->codes->provisioningUri('JBSWY3DPEHPK3PXP', 'someone@twes.local', 'twes-in');

        self::assertStringStartsWith('otpauth://totp/', $uri);
        self::assertStringContainsString('secret=JBSWY3DPEHPK3PXP', $uri);
        self::assertStringContainsString('issuer=twes-in', $uri);
        self::assertStringContainsString(rawurlencode('someone@twes.local'), $uri);
    }

    public function testTheCurrentCodeVerifiesAndReportsItsTimestep(): void
    {
        $secret = $this->codes->generateSecret();
        $now = new \DateTimeImmutable('@1757462400');

        $code = $this->codes->codeAt($secret, $now);

        self::assertSame(intdiv(1757462400, self::PERIOD), $this->codes->verify($secret, $code, $now));
    }

    public function testAWrongCodeIsRefused(): void
    {
        $secret = $this->codes->generateSecret();
        $now = new \DateTimeImmutable('@1757462400');
        $wrong = '000000' === $this->codes->codeAt($secret, $now) ? '111111' : '000000';

        self::assertNull($this->codes->verify($secret, $wrong, $now));
    }

    public function testTheCodeFromTheStepBeforeStillVerifies(): void
    {
        // One step of leeway, because a person typing six digits routinely crosses the boundary.
        $secret = $this->codes->generateSecret();
        $now = new \DateTimeImmutable('@1757462400');
        $previous = $this->codes->codeAt($secret, $now->modify('-'.self::PERIOD.' seconds'));

        self::assertSame(intdiv(1757462400, self::PERIOD) - 1, $this->codes->verify($secret, $previous, $now));
    }

    public function testACodeTwoStepsOldIsTooOld(): void
    {
        $secret = $this->codes->generateSecret();
        $now = new \DateTimeImmutable('@1757462400');
        $old = $this->codes->codeAt($secret, $now->modify('-'.(2 * self::PERIOD).' seconds'));

        self::assertNull($this->codes->verify($secret, $old, $now));
    }

    public function testACodeFromTheNextStepVerifiesToo(): void
    {
        // Clock skew on the phone runs both ways.
        $secret = $this->codes->generateSecret();
        $now = new \DateTimeImmutable('@1757462400');
        $next = $this->codes->codeAt($secret, $now->modify('+'.self::PERIOD.' seconds'));

        self::assertSame(intdiv(1757462400, self::PERIOD) + 1, $this->codes->verify($secret, $next, $now));
    }

    public function testAMalformedCodeIsRefusedWithoutReachingTheArithmetic(): void
    {
        $secret = $this->codes->generateSecret();
        $now = new \DateTimeImmutable('@1757462400');

        self::assertNull($this->codes->verify($secret, 'not-a-code', $now));
        self::assertNull($this->codes->verify($secret, '', $now));
        self::assertNull($this->codes->verify($secret, '12345', $now));
    }
}
