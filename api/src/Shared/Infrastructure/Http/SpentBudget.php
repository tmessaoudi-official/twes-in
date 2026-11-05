<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Infrastructure\Http;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimit;

/**
 * The answer of a spent budget: `too_many_attempts`, and when it may be tried again as the standard `Retry-After`
 * header in seconds (RFC 9110 § 10.2.3), which the screens turn into the hour to come back at. A wait given as a
 * span rather than an hour holds whatever the client's clock says.
 */
final class SpentBudget
{
    public const string TOO_MANY_ATTEMPTS = 'too_many_attempts';

    public static function answer(RateLimit $limit): JsonResponse
    {
        return self::answerIn(self::secondsUntil($limit));
    }

    /**
     * The wait a spent budget says, for an answer that keeps its own shape: a problem's `TooManyRequestsHttpException`
     * takes it as its `Retry-After`.
     */
    public static function secondsUntil(RateLimit $limit): int
    {
        $at = $limit->getRetryAfter();
        // Rounded up to the second, so a client coming back on the dot never finds the window still spent.
        // system-clock: the rate limiter dates its retry on the system's own time, so the wait is measured against it
        $seconds = (int) $at->format('U') - time() + ('000000' === $at->format('u') ? 0 : 1);

        return max(1, $seconds);
    }

    /** For a budget that says its wait some other way, such as the login's in minutes; null when it says none. */
    public static function answerIn(?int $seconds): JsonResponse
    {
        $headers = null === $seconds ? [] : ['Retry-After' => (string) max(1, $seconds)];

        return new JsonResponse(['error' => self::TOO_MANY_ATTEMPTS], Response::HTTP_TOO_MANY_REQUESTS, $headers);
    }
}
