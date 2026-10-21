<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Tests\Support\RecordedLogs;
use Monolog\Level;
use Monolog\LogRecord;
use Symfony\Component\HttpFoundation\Response;

/**
 * An error in someone's browser reaches the error tracker through the API, never from the browser to the tracker
 * (docs/START.md § 15): the page posts what broke, the API logs it at error level on the `client` channel, and the
 * Monolog road every API error takes carries it on, scrubbed. Anyone may post, signed in or not, within a budget.
 */
final class ClientErrorsTest extends ApiTestCase
{
    private const string PATH = '/api/client-errors';
    private const array REPORT = [
        'message' => 'TypeError: cannot read "total" of nobody@twes.invalid',
        'stack' => "at InvoicePage.total (main-AB12.js:1:4242)\nat 1234567890",
        'page' => '/invoices/42?search=ali#lines',
        'build' => '2026.10.12.3',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        RecordedLogs::$records = [];
    }

    public function testAnErrorIsLoggedAtErrorLevelOnTheClientChannelWithItsValuesMaskedAndItsPageWithoutQuery(): void
    {
        $this->postJson(self::PATH, self::REPORT);

        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
        $records = self::logged();
        self::assertCount(1, $records);
        self::assertSame(Level::Error, $records[0]->level);
        self::assertSame('client', $records[0]->channel);
        $line = $records[0]->message;
        self::assertStringContainsString('/invoices/42', $line);
        self::assertStringNotContainsString('search=ali', $line);
        self::assertStringNotContainsString('#lines', $line);
        self::assertStringContainsString('2026.10.12.3', $line);
        self::assertStringContainsString('TypeError: cannot read "[value]" of [address]', $line);
        self::assertStringContainsString('InvoicePage.total (main-AB12.js:1:4242)', $line);
        self::assertStringNotContainsString('nobody@twes.invalid', $line);
        self::assertStringNotContainsString('1234567890', $line);
    }

    /** The page a link opened (an invitation, a sign-up, a reset) is kept without its token, in any case it is written. */
    public function testAPageUnderALinksTokenIsLoggedWithoutIt(): void
    {
        $token = str_repeat('ab12', 16);
        $this->postJson(self::PATH, ['message' => 'TypeError at /reset-password/'.strtoupper($token), 'page' => '/invitation/'.$token, 'build' => '2026.10.21.1']);

        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
        $line = self::logged()[0]->message;
        self::assertStringNotContainsStringIgnoringCase($token, $line);
        self::assertStringContainsString('Web error on /invitation/[token] ', $line);
        self::assertStringContainsString('TypeError at /reset-password/[token]', $line);
    }

    public function testAMessageAndAStackAreCutToTheirLengths(): void
    {
        $this->postJson(self::PATH, ['message' => str_repeat('m', 900), 'stack' => str_repeat('s', 9000)] + self::REPORT);

        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
        $line = self::logged()[0]->message;
        self::assertStringNotContainsString(str_repeat('m', 501), $line);
        self::assertStringContainsString(str_repeat('m', 500), $line);
        self::assertStringNotContainsString(str_repeat('s', 4001), $line);
    }

    public function testABodyOverItsSizeIsRefusedUnread(): void
    {
        $this->postJson(self::PATH, ['message' => str_repeat('m', 20_000)] + self::REPORT);

        self::assertResponseStatusCodeSame(Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
        self::assertSame([], self::logged());
    }

    public function testAReportWithoutAMessageIsRefused(): void
    {
        $this->postJson(self::PATH, ['message' => ['not', 'text']] + self::REPORT);

        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        self::assertSame(['error' => 'invalid_report'], $this->json());
        self::assertSame([], self::logged());
    }

    public function testAPostWithoutTheCsrfProofIsRefused(): void
    {
        $this->postJson(self::PATH, self::REPORT, false);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertSame([], self::logged());
    }

    public function testAClientPastItsBudgetIsAnswered429AndNothingMoreIsLogged(): void
    {
        for ($i = 0; $i < 20; ++$i) {
            $this->postJson(self::PATH, self::REPORT);
            self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
        }

        $this->postJson(self::PATH, self::REPORT);

        self::assertResponseStatusCodeSame(Response::HTTP_TOO_MANY_REQUESTS);
        self::assertCount(20, self::logged());
    }

    /** @return list<LogRecord> */
    private static function logged(): array
    {
        return array_values(array_filter(RecordedLogs::$records, static fn (LogRecord $record): bool => 'client' === $record->channel));
    }
}
