<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Application;

use App\Shared\Application\Phones\TypedPhones;
use App\Shared\Infrastructure\Intl\LibPhoneNumbers;
use App\Tests\Support\FixedPhonePolicy;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\IdentityTranslator;

final class TypedPhonesTest extends TestCase
{
    public function testANumberIsKeptAsE164ReadByTheRecordsCountry(): void
    {
        $kept = self::phones()->kept(' 71 900 868 ', null, 'TN');

        self::assertSame(['+21671900868', null, null], [$kept->phone, $kept->refusal, $kept->doubt]);
        self::assertSame('+33123456789', self::phones()->kept('00 33 1 23 45 67 89', null, 'TN')->phone, '« 00 » names another country');
    }

    public function testNoPhoneIsNoPhone(): void
    {
        self::assertNull(self::phones()->kept(null, '+21671900868', 'TN')->phone);
        self::assertSame('', self::phones()->kept("\u{00A0} ", '+21671900868', 'TN')->phone);
    }

    public function testAWrongLengthIsRefusedWithTheCountrysExample(): void
    {
        $refusal = self::phones()->kept('71 900', null, 'TN')->refusal;

        self::assertSame(['phone_length', ['example' => '+216 30 010 123', 'callingCode' => '+216']], [$refusal?->reason, $refusal?->params]);
        self::assertSame('phone_country', self::phones()->kept('71 900 868', null, null)->refusal?->reason);
        self::assertSame('invalid_phone', self::phones()->kept('appeler le standard', null, 'TN')->refusal?->reason);
    }

    /** What the record holds is judged only once it changes: a value kept before the rule is sent back with the form. */
    public function testWhatTheRecordHoldsIsJudgedOnlyOnceItChanges(): void
    {
        $kept = self::phones()->kept('71 900 ', '71 900', 'TN');

        self::assertSame(['71 900', null], [$kept->phone, $kept->refusal]);
        self::assertSame('phone_length', self::phones()->kept('71 901', '71 900', 'TN')->refusal?->reason);
    }

    /** The platform's policy « Refuser », its default (ruled 2026-10-29): refused as typed, with the country's example. */
    public function testANumberOutsideTheRangesHandedOutIsRefusedWithTheCountrysExampleByDefault(): void
    {
        $refusal = self::phones()->kept('+216 11 111 111', null, 'FR')->refusal;

        self::assertSame(['phone_outside_ranges', ['example' => '+216 30 010 123', 'callingCode' => '+216']], [$refusal?->reason, $refusal?->params]);
        $held = self::phones()->kept('+216 11 111 111', '+21611111111', 'TN');
        self::assertSame(['+21611111111', null, null], [$held->phone, $held->refusal, $held->doubt], 'what the record holds, written another way, is not refused');
    }

    /** The platform's policy « Garder et marquer À vérifier »: kept, its doubt said once. */
    public function testANumberOutsideTheRangesHandedOutIsKeptWithADoubtSaidOnceWhereThePlatformKeepsThem(): void
    {
        $kept = self::phones(keep: true)->kept('+216 11 111 111', null, 'TN');
        self::assertSame(['+21611111111', null, 'doubt.phone'], [$kept->phone, $kept->refusal, $kept->doubt]);

        $again = self::phones(keep: true)->kept('+216 11 111 111', '+21611111111', 'TN');
        self::assertSame(['+21611111111', null], [$again->phone, $again->doubt], 'the same number written another way is no new doubt');
    }

    private static function phones(bool $keep = false): TypedPhones
    {
        return new TypedPhones(new LibPhoneNumbers(new IdentityTranslator()), new FixedPhonePolicy($keep));
    }
}
