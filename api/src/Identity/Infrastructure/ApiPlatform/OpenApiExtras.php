<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Infrastructure\ApiPlatform;

use ApiPlatform\OpenApi\Factory\OpenApiFactoryInterface;
use ApiPlatform\OpenApi\Model\MediaType;
use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\OpenApi\Model\PathItem;
use ApiPlatform\OpenApi\Model\RequestBody;
use ApiPlatform\OpenApi\Model\Response;
use ApiPlatform\OpenApi\OpenApi;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;

/**
 * POST /api/auth/login and POST /api/auth/logout are firewall endpoints (json_login, logout) and GET /api/health
 * is a plain controller, so API Platform does not know them. This adds them to the OpenAPI document, which is
 * where the TypeScript client's types come from: LoginRequest, the error shape, the health shape, and the
 * fact that a login answers a Me.
 */
#[AsDecorator('api_platform.openapi.factory')]
final readonly class OpenApiExtras implements OpenApiFactoryInterface
{
    public function __construct(private OpenApiFactoryInterface $decorated)
    {
    }

    public function __invoke(array $context = []): OpenApi
    {
        $openApi = ($this->decorated)($context);
        $schemas = $openApi->getComponents()->getSchemas() ?? new \ArrayObject();

        $schemas['LoginRequest'] = new \ArrayObject([
            'type' => 'object',
            'required' => ['email', 'password'],
            'properties' => [
                'email' => ['type' => 'string', 'format' => 'email'],
                'password' => ['type' => 'string', 'format' => 'password'],
            ],
        ]);
        $schemas['AuthError'] = new \ArrayObject([
            'type' => 'object',
            'required' => ['error'],
            'properties' => [
                'error' => [
                    'type' => 'string',
                    'enum' => ['invalid_credentials', 'account_locked', 'account_disabled', 'too_many_attempts', 'authentication_required', 'csrf_token_missing', 'csrf_token_invalid'],
                ],
            ],
        ]);
        $schemas['Health'] = new \ArrayObject([
            'type' => 'object',
            'required' => ['status', 'database'],
            'properties' => [
                'status' => ['type' => 'string', 'enum' => ['ok', 'degraded']],
                'database' => ['type' => 'string', 'enum' => ['ok', 'unreachable']],
            ],
        ]);

        $me = $this->meSchemaName($schemas);
        $errorResponse = static fn (string $description): Response => new Response($description, new \ArrayObject(['application/json' => new MediaType(new \ArrayObject(['$ref' => '#/components/schemas/AuthError']))]));

        $openApi->getPaths()->addPath('/api/auth/login', new PathItem(post: new Operation(
            operationId: 'login',
            tags: ['Auth'],
            responses: [
                '200' => new Response('Signed in; the session cookie is set', new \ArrayObject(['application/json' => new MediaType(new \ArrayObject(['$ref' => '#/components/schemas/'.$me]))])),
                '400' => $errorResponse('Malformed body'),
                '401' => $errorResponse('Wrong credentials, locked or disabled account'),
                '403' => $errorResponse('CSRF check failed'),
                '429' => $errorResponse('Too many attempts'),
            ],
            summary: 'Sign in with email and password',
            requestBody: new RequestBody('Credentials', new \ArrayObject(['application/json' => new MediaType(new \ArrayObject(['$ref' => '#/components/schemas/LoginRequest']))]), true),
        )));
        $openApi->getPaths()->addPath('/api/auth/logout', new PathItem(post: new Operation(
            operationId: 'logout',
            tags: ['Auth'],
            responses: [
                '204' => new Response('Signed out; the session is invalidated'),
                '403' => $errorResponse('CSRF check failed'),
            ],
            summary: 'Sign out',
        )));
        $health = static fn (string $description): Response => new Response($description, new \ArrayObject(['application/json' => new MediaType(new \ArrayObject(['$ref' => '#/components/schemas/Health']))]));
        $openApi->getPaths()->addPath('/api/health', new PathItem(get: new Operation(
            operationId: 'health',
            tags: ['Health'],
            responses: ['200' => $health('The database answers'), '503' => $health('The database does not answer')],
            summary: 'Liveness, public',
        )));

        return $openApi->withComponents($openApi->getComponents()->withSchemas($schemas));
    }

    /**
     * API Platform names the Me schema by resource and format ("Me", "Me.jsonld", …); pick whatever it produced.
     *
     * @param \ArrayObject<string, mixed> $schemas
     */
    private function meSchemaName(\ArrayObject $schemas): string
    {
        foreach (array_keys($schemas->getArrayCopy()) as $name) {
            if ('Me' === $name || str_starts_with($name, 'Me.') || str_starts_with($name, 'Me-')) {
                return $name;
            }
        }

        throw new \LogicException('The Me resource must produce an OpenAPI schema before the login endpoint can reference it.');
    }
}
