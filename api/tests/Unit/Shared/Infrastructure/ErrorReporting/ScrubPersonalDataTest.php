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

    public function testALinksTokenLeavesNeitherThePathNorTheMessage(): void
    {
        $token = str_repeat('c0ffee42', 8);
        $event = Event::createEvent();
        $event->setRequest(['url' => 'https://twes.test/api/signup/'.$token.'/complete', 'method' => 'POST']);
        $event->setMessage('Sign-up '.$token.' refused', [], 'Sign-up '.$token.' refused');
        $event->setExceptions([new ExceptionDataBag(new \RuntimeException('No route found for "GET /api/invitations/'.$token.'"'))]);

        $scrubbed = self::scrub($event);

        self::assertSame(['url' => 'https://twes.test/api/signup/[token]/complete', 'method' => 'POST'], $scrubbed->getRequest());
        self::assertSame('Sign-up [token] refused', $scrubbed->getMessage());
        self::assertStringNotContainsString($token, self::payload($scrubbed));
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
        yield "a unique key's values, as PostgreSQL details them" => [
            "SQLSTATE[23505]: Unique violation: 7 ERROR:  duplicate key value violates unique constraint \"uniq_vendor_name\"\nDETAIL:  Key (company_id, name)=(019a2b3c-0000-7000-8000-000000000001, Ben Ali (Sfax)) already exists.",
            "SQLSTATE[23505]: Unique violation: 7 ERROR:  duplicate key value violates unique constraint \"[value]\"\nDETAIL:  Key (company_id, name)=([values]) already exists.",
        ];
        yield "a missing reference's value" => [
            "ERROR:  insert or update on table \"invoice\" violates foreign key constraint \"fk_1\"\nDETAIL:  Key (customer_id)=(Dupont Sfax) is not present in table \"customer\".",
            "ERROR:  insert or update on table \"[value]\" violates foreign key constraint \"[value]\"\nDETAIL:  Key (customer_id)=([values]) is not present in table \"[value]\".",
        ];
        yield "a check's failing row" => [
            "ERROR:  new row for relation \"customer\" violates check constraint \"chk_1\"\nDETAIL:  Failing row contains (019a, Dupont Sfax, ali-ben, 21 rue Habib (bloc B), null).",
            "ERROR:  new row for relation \"[value]\" violates check constraint \"[value]\"\nDETAIL:  Failing row contains ([values]).",
        ];
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
