<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Infrastructure\Health;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * GET /api/health — liveness for the web tier, CI's end-to-end job and any uptime monitor.
 * Public and unauthenticated by design; it discloses nothing but reachability.
 */
final readonly class HealthController
{
    public function __construct(private DatabaseProbe $database)
    {
    }

    #[Route('/api/health', name: 'api_health', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        $reachable = $this->database->isReachable();

        return new JsonResponse(
            ['status' => $reachable ? 'ok' : 'degraded', 'database' => $reachable ? 'ok' : 'unreachable'],
            $reachable ? Response::HTTP_OK : Response::HTTP_SERVICE_UNAVAILABLE,
        );
    }
}
