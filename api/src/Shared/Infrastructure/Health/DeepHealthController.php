<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Infrastructure\Health;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * What the monitor reads every minute (docs/START.md § 15): the queue, the disk and the archiving, each judged.
 * Answers only the bearer of the instance's monitor token; to anyone else, and where no token is set (development),
 * it is a 404 like any path that does not exist. The firewall lets it through and asks nobody who is signed in, so no
 * session is read or started.
 */
final readonly class DeepHealthController
{
    public function __construct(
        private DeepHealthReader $reader,
        private DeepHealth $health,
        #[Autowire(env: 'MONITOR_TOKEN')] private string $token,
    ) {
    }

    #[Route('/api/health/deep', name: 'api_health_deep', methods: ['GET'])]
    public function __invoke(Request $request): JsonResponse
    {
        $given = (string) $request->headers->get('Authorization', '');
        if ('' === $this->token || !hash_equals('Bearer '.$this->token, $given)) {
            throw new NotFoundHttpException();
        }

        $verdict = $this->health->judge($this->reader->read());

        return new JsonResponse(
            ['status' => $verdict['ok'] ? 'ok' : 'failing', 'failing' => $verdict['failing'], 'readings' => $verdict['readings']],
            $verdict['ok'] ? Response::HTTP_OK : Response::HTTP_SERVICE_UNAVAILABLE,
        );
    }
}
