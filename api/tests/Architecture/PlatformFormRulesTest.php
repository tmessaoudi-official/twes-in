<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Architecture;

use App\Tenancy\Infrastructure\ApiPlatform\Resource\CompanyResource;
use App\Tenancy\Infrastructure\ApiPlatform\Resource\PlatformOwnerInvitationResource;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Constraints\Length;

/**
 * The platform's page says a new company's name or an owner's address is wrong before it asks the operator to prove
 * who is at the screen (web/src/app/platform/platform-rules.ts), since the API asks that proof before it reads what was
 * sent: the lengths it says are the ones the API holds them to, or a value it lets through is refused only after the
 * proof, and one it refuses would be taken.
 */
final class PlatformFormRulesTest extends TestCase
{
    public function testTheLengthsThePageSaysAreTheOnesTheApiHolds(): void
    {
        $web = file_get_contents(\dirname(__DIR__, 3).'/web/src/app/platform/platform-rules.ts');
        self::assertIsString($web);

        self::assertSame(self::lengthOf(CompanyResource::class, 'name'), self::constantIn($web, 'COMPANY_NAME_MAX'));
        self::assertSame(self::lengthOf(PlatformOwnerInvitationResource::class, 'email'), self::constantIn($web, 'OWNER_EMAIL_MAX'));
    }

    private static function constantIn(string $source, string $name): int
    {
        if (1 !== preg_match('/export const '.$name.' = (\d+);/', $source, $found)) {
            self::fail("$name is not found in platform-rules.ts");
        }

        return (int) $found[1];
    }

    /** @param class-string $class */
    private static function lengthOf(string $class, string $property): int
    {
        $lengths = (new \ReflectionProperty($class, $property))->getAttributes(Length::class);
        self::assertCount(1, $lengths, "$class::\$$property has one length");
        $max = $lengths[0]->newInstance()->max;
        self::assertIsInt($max);

        return $max;
    }
}
