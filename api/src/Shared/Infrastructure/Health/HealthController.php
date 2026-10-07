<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Infrastructure\Health;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * GET /api/health — liveness for the web tier, CI's end-to-end job and any uptime monitor.
 * Public and unauthenticated by design; it discloses reachability and which build answers: the version and commit the
 * image was built as (scripts/build-version.sh), the kernel's environment and the deployment, which the web's footer
 * shows to everyone (docs/SPEC.md § 7, the build line). The source they name is public under the AGPL already.
 */
final readonly class HealthController
{
    public function __construct(
        private DatabaseProbe $database,
        #[Autowire(env: 'BUILD_VERSION')] private string $version,
        #[Autowire(env: 'BUILD_COMMIT')] private string $commit,
        #[Autowire('%kernel.environment%')] private string $mode,
        #[Autowire(env: 'DEPLOY_ENV')] private string $deployment,
    ) {
    }

    #[Route('/api/health', name: 'api_health', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        $reachable = $this->database->isReachable();

        return new JsonResponse(
            [
                'status' => $reachable ? 'ok' : 'degraded',
                'database' => $reachable ? 'ok' : 'unreachable',
                'build' => ['version' => self::given($this->version), 'commit' => self::given($this->commit), 'mode' => $this->mode],
                'deployment' => self::given($this->deployment),
            ],
            $reachable ? Response::HTTP_OK : Response::HTTP_SERVICE_UNAVAILABLE,
        );
    }

    /** An empty value is one nobody gave: an image built without its version, a deployment that names none. */
    private static function given(string $value): ?string
    {
        return '' === $value ? null : $value;
    }
}
