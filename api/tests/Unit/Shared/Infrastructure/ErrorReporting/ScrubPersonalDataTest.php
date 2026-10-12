<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\ErrorReporting;

use App\Shared\Infrastructure\ErrorReporting\ScrubPersonalData;
use PHPUnit\Framework\TestCase;
use Sentry\Breadcrumb;
use Sentry\Event;
use Sentry\ExceptionDataBag;
use Sentry\Frame;
use Sentry\Stacktrace;
use Sentry\UserDataBag;

final class ScrubPersonalDataTest extends TestCase
{
    public function testARequestKeepsItsMethodAndPathAlone(): void
    {
        $event = Event::createEvent();
        $event->setRequest([
            'url' => 'https://twes.test/api/auth/login?email=ali%40shop.tn',
            'method' => 'POST',
            'query_string' => 'email=ali%40shop.tn',
            'data' => ['email' => 'ali@shop.tn', 'password' => 'hunter2-secret'],
            'cookies' => ['twes_session' => 'abc'],
            'headers' => ['cookie' => ['twes_session=abc'], 'authorization' => ['Bearer x'], 'user-agent' => ['Firefox']],
            'env' => ['REMOTE_ADDR' => '198.51.100.7'],
        ]);

        $scrubbed = self::scrub($event);

        self::assertSame(['url' => 'https://twes.test/api/auth/login', 'method' => 'POST'], $scrubbed->getRequest());
        self::assertStringNotContainsString('hunter2', self::payload($scrubbed));
        self::assertStringNotContainsString('198.51.100.7', self::payload($scrubbed));
    }

    public function testNoPersonIsAttached(): void
    {
        $event = Event::createEvent();
        $event->setUser(UserDataBag::createFromUserIdentifier('0192-user')->setEmail('ali@shop.tn')->setIpAddress('198.51.100.7'));
        $event->setExtra(['customer' => 'Dupont']);
        $event->setBreadcrumb([new Breadcrumb(Breadcrumb::LEVEL_INFO, Breadcrumb::TYPE_DEFAULT, 'log', 'Signed in ali@shop.tn')]);

        $scrubbed = self::scrub($event);

        self::assertNull($scrubbed->getUser());
        self::assertSame([], $scrubbed->getExtra());
        self::assertSame([], $scrubbed->getBreadcrumbs());
    }

    /** @return iterable<string, array{string, string}> */
    public static function messages(): iterable
    {
        yield 'an address' => ['No account for ali.ben@shop.tn here', 'No account for [address] here'];
        yield 'a value in quotes' => ['Customer "Dupont SARL" already exists', 'Customer "[value]" already exists'];
        yield 'a value in French quotes' => ['Le client « Dupont SARL » existe', 'Le client « [value] » existe'];
        yield 'a value in single quotes' => ["Key 'TN-1234567A' refused", "Key '[value]' refused"];
        yield 'a long number' => ['Phone 21698765432 or IBAN TN5904018104004942712345 refused', 'Phone [number] or IBAN TN[number] refused'];
        yield 'a short number stays, as a line or a count does' => ['Line 12 of 40 refused', 'Line 12 of 40 refused'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('messages')]
    public function testMessagesKeepTheirShapeAndLoseTheirValues(string $message, string $kept): void
    {
        $event = Event::createEvent();
        $event->setMessage($message, [], $message);
        $event->setExceptions([new ExceptionDataBag(new \RuntimeException($message))]);

        $scrubbed = self::scrub($event);

        self::assertSame($kept, $scrubbed->getMessage());
        self::assertSame($kept, $scrubbed->getMessageFormatted());
        self::assertSame($kept, $scrubbed->getExceptions()[0]->getValue());
    }

    public function testAStackKeepsItsFramesAndLosesTheirVariables(): void
    {
        $frame = new Frame('handle', '/app/src/Handler.php', 42);
        $frame->setVars(['password' => 'hunter2-secret']);
        $event = Event::createEvent();
        $event->setExceptions([new ExceptionDataBag(new \RuntimeException('boom'), new Stacktrace([$frame]))]);

        $scrubbed = self::scrub($event);

        $frames = $scrubbed->getExceptions()[0]->getStacktrace()?->getFrames() ?? [];
        self::assertCount(1, $frames);
        self::assertSame(42, $frames[0]->getLine());
        self::assertSame([], $frames[0]->getVars());
    }

    private static function scrub(Event $event): Event
    {
        return new ScrubPersonalData()($event, null);
    }

    private static function payload(Event $event): string
    {
        return json_encode([$event->getRequest(), $event->getUser()?->getIpAddress(), $event->getExtra()], \JSON_THROW_ON_ERROR);
    }
}
