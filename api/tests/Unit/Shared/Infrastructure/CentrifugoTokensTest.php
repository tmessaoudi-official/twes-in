<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure;

use App\Shared\Infrastructure\Realtime\CentrifugoTokens;
use App\Shared\Infrastructure\Realtime\HmacJwt;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * The connection token decides what a browser hears: Centrifugo subscribes the connection to the channels the
 * token lists, and the browser never names a channel itself (docs/SPEC.md § 7, 2026-09-13).
 */
final class CentrifugoTokensTest extends TestCase
{
    private const string KEY = 'a-development-realtime-key-000000000';

    public function testTheTokenNamesTheUserAndExpiresAfterItsLifetime(): void
    {
        $user = Uuid::v7();

        $issued = $this->tokens(900)->issue($user, null);
        $claims = self::claimsOf($issued->token);

        self::assertSame($user->toRfc4122(), $claims['sub']);
        self::assertSame((new \DateTimeImmutable('2026-09-13T10:15:00+00:00'))->getTimestamp(), $claims['exp']);
        self::assertEquals(new \DateTimeImmutable('2026-09-13T10:15:00+00:00'), $issued->expiresAt);
    }

    public function testWithoutAWorkingCompanyOnlyThePersonalChannelIsListed(): void
    {
        $user = Uuid::v7();

        $claims = self::claimsOf($this->tokens(900)->issue($user, null)->token);

        self::assertSame(['user:'.$user->toRfc4122()], $claims['channels']);
    }

    public function testTheWorkingCompanyAddsItsChannel(): void
    {
        $user = Uuid::v7();
        $company = Uuid::v7();

        $claims = self::claimsOf($this->tokens(900)->issue($user, $company)->token);

        self::assertSame(['user:'.$user->toRfc4122(), 'company:'.$company->toRfc4122()], $claims['channels']);
    }

    public function testItIsSignedWithTheConfiguredKey(): void
    {
        [$header, $payload, $signature] = explode('.', $this->tokens(900)->issue(Uuid::v7(), null)->token);

        self::assertSame(HmacJwt::signature($header.'.'.$payload, self::KEY), $signature);
    }

    public function testALifetimeThatIsNotPositiveIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->tokens(0);
    }

    private function tokens(int $lifetimeSeconds): CentrifugoTokens
    {
        $clock = new class implements ClockInterface {
            public function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable('2026-09-13T10:00:00+00:00');
            }
        };

        return new CentrifugoTokens(self::KEY, $clock, $lifetimeSeconds);
    }

    /** @return array<string, mixed> */
    private static function claimsOf(string $token): array
    {
        $claims = json_decode(HmacJwt::base64UrlDecode(explode('.', $token)[1]), true, 8, \JSON_THROW_ON_ERROR);
        self::assertIsArray($claims);
        $keyed = [];
        foreach ($claims as $name => $value) {
            $keyed[(string) $name] = $value;
        }

        return $keyed;
    }
}
