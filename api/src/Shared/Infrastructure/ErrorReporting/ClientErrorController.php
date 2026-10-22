<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Infrastructure\ErrorReporting;

use App\Shared\Domain\Service\TypedText;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * An error in someone's browser, posted by the page (docs/START.md § 15). The browser never speaks to the error
 * tracker itself, so no tracker address, key or script reaches the page: the API logs the error at error level on the
 * `client` channel, and the Monolog road every API error takes carries it on through ScrubPersonalData. Its values are
 * masked here already, so the instance's own log keeps none either. The page is kept without its query or fragment, the
 * message and the stack are cut to a length, and a body past its size is refused unread.
 */
final readonly class ClientErrorController
{
    private const int MAX_BODY = 16_384;
    private const int MAX_MESSAGE = 500;
    private const int MAX_STACK = 4_000;
    private const int MAX_FIELD = 200;

    public function __construct(
        #[Target('clientLogger')]
        private LoggerInterface $logger,
        #[Target('client_errors')]
        private RateLimiterFactoryInterface $limiter,
    ) {
    }

    #[Route('/api/client-errors', name: 'api_client_errors', methods: ['POST'])]
    public function __invoke(Request $request): Response
    {
        if (!$this->limiter->create($request->getClientIp() ?? '')->consume()->isAccepted()) {
            return new JsonResponse(['error' => 'too_many_attempts'], Response::HTTP_TOO_MANY_REQUESTS);
        }
        if (\strlen($request->getContent()) > self::MAX_BODY) {
            return new JsonResponse(['error' => 'report_too_large'], Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
        }

        $body = json_decode($request->getContent(), true);
        if (!\is_array($body) || !\is_string($body['message'] ?? null) || '' === TypedText::trimmed($body['message'])) {
            return new JsonResponse(['error' => 'invalid_report'], Response::HTTP_BAD_REQUEST);
        }

        $page = self::text($body, 'page', self::MAX_FIELD);
        $page = explode('?', explode('#', $page, 2)[0], 2)[0];
        $line = \sprintf(
            "Web error on %s (build %s): %s\n%s",
            '' === $page ? '?' : $page,
            self::text($body, 'build', self::MAX_FIELD) ?: '?',
            mb_substr($body['message'], 0, self::MAX_MESSAGE),
            self::text($body, 'stack', self::MAX_STACK),
        );
        $this->logger->error(ScrubPersonalData::mask($line));

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    /** @param array<mixed> $body */
    private static function text(array $body, string $key, int $length): string
    {
        $value = $body[$key] ?? null;

        return \is_string($value) ? mb_substr($value, 0, $length) : '';
    }
}
