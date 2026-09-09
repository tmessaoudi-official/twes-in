<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Infrastructure\Security;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Csrf\SameOriginCsrfTokenManager;

/**
 * CSRF for the whole API through Symfony own stateless mechanism (framework.yaml: csrf_protection with
 * stateless_token_ids [api], header only). The SPA sends a random value of at least 24 characters in the
 * csrf-token header on every request; a cross-site page cannot set that header. The manager also enforces
 * the origin (Sec-Fetch-Site, Origin, Referer) and remembers in the session which proof a client gave, so
 * a proof that disappears later is refused too. Runs before the firewall so the login itself is covered.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 24)]
final readonly class CsrfRequestListener
{
    public const string TOKEN_ID = 'api';
    public const string HEADER = 'csrf-token';
    public const string TOKEN_MISSING = 'csrf_token_missing';
    public const string TOKEN_INVALID = 'csrf_token_invalid';

    private const array EXEMPT_PATHS = ['/api/health'];

    public function __construct(private CsrfTokenManagerInterface $tokens)
    {
    }

    public function __invoke(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || $request->isMethodSafe() || !$this->isCovered($request)) {
            return;
        }

        $value = (string) $request->headers->get(self::HEADER, '');
        if (\strlen($value) < SameOriginCsrfTokenManager::TOKEN_MIN_LENGTH) {
            $event->setResponse(self::refuse(self::TOKEN_MISSING));

            return;
        }
        if (!$this->tokens->isTokenValid(new CsrfToken(self::TOKEN_ID, $value))) {
            $event->setResponse(self::refuse(self::TOKEN_INVALID));
        }
    }

    private function isCovered(Request $request): bool
    {
        $path = $request->getPathInfo();

        return str_starts_with($path, '/api/') && !\in_array($path, self::EXEMPT_PATHS, true);
    }

    private static function refuse(string $error): Response
    {
        return new JsonResponse(['error' => $error], Response::HTTP_FORBIDDEN);
    }
}
