<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Identity\Infrastructure;

use App\Identity\Infrastructure\Password\HibpBreachedPasswordCheck;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

#[CoversClass(HibpBreachedPasswordCheck::class)]
final class HibpBreachedPasswordCheckTest extends TestCase
{
    private const string PASSWORD = 'correct horse battery staple';

    public function testAPasswordInTheCorpusIsBreached(): void
    {
        $check = $this->check(new MockResponse($this->suffixOf(self::PASSWORD).':42'));

        self::assertTrue($check->isBreached(self::PASSWORD));
    }

    public function testAPasswordAbsentFromTheAnswerIsNotBreached(): void
    {
        $check = $this->check(new MockResponse("0000000000000000000000000000000000A:9\r\n0000000000000000000000000000000000B:3"));

        self::assertFalse($check->isBreached(self::PASSWORD));
    }

    public function testAPaddingEntryIsNotAMatch(): void
    {
        // The API pads answers with real-looking suffixes whose count is zero; treating one as a hit would
        // refuse a perfectly good password.
        $check = $this->check(new MockResponse($this->suffixOf(self::PASSWORD).':0'));

        self::assertFalse($check->isBreached(self::PASSWORD));
    }

    public function testOnlyTheFirstFiveCharactersOfTheDigestAreSent(): void
    {
        $seen = null;
        $client = new MockHttpClient(static function (string $method, string $url) use (&$seen): MockResponse {
            $seen = $url;

            return new MockResponse('');
        });

        (new HibpBreachedPasswordCheck($client, true))->isBreached(self::PASSWORD);

        $digest = strtoupper(sha1(self::PASSWORD));
        self::assertIsString($seen);
        self::assertStringEndsWith(substr($digest, 0, 5), $seen);
        self::assertStringNotContainsString(substr($digest, 5), $seen);
    }

    public function testAnUnreachableServiceAnswersNeitherYesNorNo(): void
    {
        $client = new MockHttpClient(static function (): MockResponse {
            throw new \RuntimeException('connection refused');
        });

        self::assertNull((new HibpBreachedPasswordCheck($client, true))->isBreached(self::PASSWORD));
    }

    public function testAServerErrorAnswersNeitherYesNorNo(): void
    {
        self::assertNull($this->check(new MockResponse('', ['http_code' => 503]))->isBreached(self::PASSWORD));
    }

    public function testTheCheckCanBeSwitchedOffWithoutTouchingTheNetwork(): void
    {
        $client = new MockHttpClient(static function (): MockResponse {
            throw new \LogicException('the network must not be reached when the check is off');
        });

        self::assertFalse((new HibpBreachedPasswordCheck($client, false))->isBreached(self::PASSWORD));
    }

    private function check(MockResponse $response): HibpBreachedPasswordCheck
    {
        return new HibpBreachedPasswordCheck(new MockHttpClient($response), true);
    }

    private function suffixOf(string $password): string
    {
        return substr(strtoupper(sha1($password)), 5);
    }
}
