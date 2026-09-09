<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Identity\Infrastructure;

use App\Identity\Infrastructure\Security\CsrfRequestListener;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Security\Csrf\SameOriginCsrfTokenManager;

/**
 * The listener delegates to Symfony own stateless manager, configured exactly as framework.yaml does (token id
 * "api", header only). These cases pin the contract the SPA relies on, not the manager internals.
 */
final class CsrfRequestListenerTest extends TestCase
{
    private const string TOKEN = '0123456789abcdef0123456789abcdef';

    public function testAnUnsafeApiRequestWithoutTheHeaderIsRefused(): void
    {
        $event = $this->dispatch(Request::create('/api/auth/login', 'POST', server: ['HTTP_HOST' => 'localhost:8090', 'HTTP_ORIGIN' => 'http://localhost:8090']));

        self::assertSame(403, $event->getResponse()?->getStatusCode());
        self::assertSame(['error' => 'csrf_token_missing'], $this->body($event));
    }

    public function testAShortHeaderIsRefusedAsMissing(): void
    {
        $event = $this->dispatch(Request::create('/api/auth/login', 'POST', server: ['HTTP_HOST' => 'localhost:8090', 'HTTP_CSRF_TOKEN' => 'short']));

        self::assertSame(['error' => 'csrf_token_missing'], $this->body($event));
    }

    public function testTheHeaderFromTheSameOriginPasses(): void
    {
        foreach ([['HTTP_ORIGIN' => 'http://localhost:8090'], ['HTTP_SEC_FETCH_SITE' => 'same-origin'], ['HTTP_REFERER' => 'http://localhost:8090/login']] as $origin) {
            $event = $this->dispatch(Request::create('/api/auth/login', 'POST', server: ['HTTP_HOST' => 'localhost:8090', 'HTTP_CSRF_TOKEN' => self::TOKEN] + $origin));
            self::assertNull($event->getResponse(), json_encode($origin, \JSON_THROW_ON_ERROR));
        }
    }

    public function testTheHeaderFromAForeignOriginIsRefused(): void
    {
        foreach ([['HTTP_ORIGIN' => 'https://evil.example'], ['HTTP_SEC_FETCH_SITE' => 'cross-site'], ['HTTP_ORIGIN' => 'http://localhost:9999']] as $origin) {
            $event = $this->dispatch(Request::create('/api/auth/login', 'POST', server: ['HTTP_HOST' => 'localhost:8090', 'HTTP_CSRF_TOKEN' => self::TOKEN] + $origin));
            self::assertSame(['error' => 'csrf_token_invalid'], $this->body($event), json_encode($origin, \JSON_THROW_ON_ERROR));
        }
    }

    public function testSafeMethodsHealthAndNonApiPathsAreNotChecked(): void
    {
        foreach ([['/api/auth/me', 'GET'], ['/api/auth/me', 'HEAD'], ['/api/auth/me', 'OPTIONS'], ['/api/health', 'POST'], ['/index.html', 'POST']] as [$path, $method]) {
            $event = $this->dispatch(Request::create($path, $method, server: ['HTTP_HOST' => 'localhost:8090', 'HTTP_ORIGIN' => 'https://evil.example']));
            self::assertNull($event->getResponse(), "$method $path must not be CSRF-checked");
        }
    }

    public function testASubRequestIsIgnored(): void
    {
        $event = $this->dispatch(Request::create('/api/auth/login', 'POST'), HttpKernelInterface::SUB_REQUEST);

        self::assertNull($event->getResponse());
    }

    private function dispatch(Request $request, int $type = HttpKernelInterface::MAIN_REQUEST): RequestEvent
    {
        $stack = new RequestStack();
        $stack->push($request);
        $manager = new SameOriginCsrfTokenManager($stack, null, null, [CsrfRequestListener::TOKEN_ID], SameOriginCsrfTokenManager::CHECK_ONLY_HEADER);
        $event = new RequestEvent($this->createStub(HttpKernelInterface::class), $request, $type);
        (new CsrfRequestListener($manager))($event);

        return $event;
    }

    /** @return array<string, mixed> */
    private function body(RequestEvent $event): array
    {
        $decoded = json_decode((string) $event->getResponse()?->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        $out = [];
        foreach ($decoded as $k => $v) {
            $out[(string) $k] = $v;
        }

        return $out;
    }
}
