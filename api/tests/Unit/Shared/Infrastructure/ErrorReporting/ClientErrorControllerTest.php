<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\ErrorReporting;

use App\Shared\Infrastructure\ErrorReporting\ClientErrorController;
use App\Shared\Infrastructure\ErrorReporting\ScrubPersonalData;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

/**
 * A browser's report is held to the operator's sizes (app.client_errors.*), not to numbers of the code's own: the body
 * it refuses unread, the message and the stack it cuts; and a page told to send none is answered as if it had, with
 * nothing logged, so a page that has not yet heard the operator's « none » adds nothing to the tracker either.
 */
final class ClientErrorControllerTest extends TestCase
{
    /** @var list<string> what the controller logged, in order */
    private array $logged = [];

    public function testABodyPastTheOperatorsSizeIsRefusedUnread(): void
    {
        $controller = $this->controller(maxBody: 1_024);

        $answer = $controller($this->report(['message' => str_repeat('m', 1_100)]));

        self::assertSame(Response::HTTP_REQUEST_ENTITY_TOO_LARGE, $answer->getStatusCode());
        self::assertSame([], $this->logged);
    }

    public function testABodyWithinTheOperatorsSizeIsRead(): void
    {
        $controller = $this->controller(maxBody: 65_536);

        $answer = $controller($this->report(['message' => str_repeat('m', 20_000)]));

        self::assertSame(Response::HTTP_NO_CONTENT, $answer->getStatusCode());
        self::assertCount(1, $this->logged);
    }

    public function testTheMessageAndTheStackAreCutToTheOperatorsLengths(): void
    {
        $controller = $this->controller(maxMessage: 100, maxStack: 600);

        $controller($this->report(['message' => str_repeat('m', 300), 'stack' => str_repeat('s', 900)]));

        self::assertCount(1, $this->logged);
        self::assertStringContainsString(str_repeat('m', 100), $this->logged[0]);
        self::assertStringNotContainsString(str_repeat('m', 101), $this->logged[0]);
        self::assertStringContainsString(str_repeat('s', 600), $this->logged[0]);
        self::assertStringNotContainsString(str_repeat('s', 601), $this->logged[0]);
    }

    public function testAPageToldToSendNoneIsAnsweredAndNothingIsLogged(): void
    {
        $controller = $this->controller(perPage: 0);

        $answer = $controller($this->report(['message' => 'TypeError: x']));

        self::assertSame(Response::HTTP_NO_CONTENT, $answer->getStatusCode());
        self::assertSame([], $this->logged);
    }

    private function controller(int $maxBody = 16_384, int $maxMessage = 500, int $maxStack = 4_000, int $perPage = 5): ClientErrorController
    {
        $logger = new class(function (string $line): void {
            $this->logged[] = $line;
        }) extends AbstractLogger {
            /** @param \Closure(string): void $record */
            public function __construct(private \Closure $record)
            {
            }

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                ($this->record)((string) $message);
            }
        };
        $limiter = new RateLimiterFactory(['id' => 'client_errors', 'policy' => 'no_limit'], new InMemoryStorage());

        return new ClientErrorController($logger, $limiter, new ScrubPersonalData(), $maxBody, $maxMessage, $maxStack, $perPage);
    }

    /** @param array<string, string> $body */
    private function report(array $body): Request
    {
        return Request::create('/api/client-errors', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode($body + ['page' => '/invoices', 'build' => '2026.11.02.1'], \JSON_THROW_ON_ERROR));
    }
}
