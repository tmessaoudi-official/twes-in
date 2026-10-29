<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure;

use App\Shared\Infrastructure\Intl\LibPhoneNumbers;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\IdentityTranslator;

/**
 * The API and the screens judge a phone alike: every number in web/src/app/shared/form/phone-vectors.json, which the
 * web's `phone-vectors.spec.ts` reads through the screens' own `phoneVerdict`, is judged here to the same verdict and the
 * same E.164. The two read different releases of Google's plans (giggsey's port and libphonenumber-js), so a range one
 * knows and the other does not reds one of the two.
 */
final class PhoneVectorsTest extends TestCase
{
    private const string VECTORS = __DIR__.'/../../../../../web/src/app/shared/form/phone-vectors.json';

    /** @return list<array{plan: ?string, typed: string, country: ?string, verdict: string, e164: ?string}> */
    private static function vectors(): array
    {
        /** @var list<array{plan: ?string, typed: string, country: ?string, verdict: string, e164: ?string}> $vectors */
        $vectors = json_decode((string) file_get_contents(self::VECTORS), true, flags: \JSON_THROW_ON_ERROR);

        return $vectors;
    }

    /** @return iterable<string, array{string, ?string, string, ?string}> typed, country, verdict, E.164 */
    public static function numbers(): iterable
    {
        foreach (self::vectors() as $vector) {
            yield \sprintf('« %s » read by %s', $vector['typed'], $vector['country'] ?? 'no country') => [$vector['typed'], $vector['country'], $vector['verdict'], $vector['e164']];
        }
    }

    #[DataProvider('numbers')]
    public function testTheApiJudgesEachNumberAsTheScreenDoes(string $typed, ?string $country, string $verdict, ?string $e164): void
    {
        $judged = new LibPhoneNumbers(new IdentityTranslator())->judge($typed, $country);

        self::assertSame([$verdict, $e164], [$judged->verdict, $judged->e164]);
    }

    public function testTheVectorsHoldEveryVerdictOfTunisiaAndFrance(): void
    {
        $held = array_map(static fn (array $vector): string => ($vector['plan'] ?? '-').' '.$vector['verdict'], self::vectors());
        foreach (['TN', 'FR'] as $plan) {
            foreach (['valid', 'to_check', 'wrong_length'] as $verdict) {
                self::assertContains("$plan $verdict", $held);
            }
        }
        self::assertContains('- needs_country', $held);
        self::assertContains('- not_a_number', $held);
    }
}
