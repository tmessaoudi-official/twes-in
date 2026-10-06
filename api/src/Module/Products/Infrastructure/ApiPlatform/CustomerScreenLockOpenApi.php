<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Products\Infrastructure\ApiPlatform;

use ApiPlatform\OpenApi\Factory\OpenApiFactoryInterface;
use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\OpenApi\Model\Parameter;
use ApiPlatform\OpenApi\Model\PathItem;
use ApiPlatform\OpenApi\Model\Response;
use ApiPlatform\OpenApi\OpenApi;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;

/** Documents the plain controller that holds a sign-in on the customer screen, which API Platform does not describe. */
#[AsDecorator('api_platform.openapi.factory')]
final readonly class CustomerScreenLockOpenApi implements OpenApiFactoryInterface
{
    public function __construct(private OpenApiFactoryInterface $decorated)
    {
    }

    public function __invoke(array $context = []): OpenApi
    {
        $openApi = ($this->decorated)($context);

        $openApi->getPaths()->addPath('/api/companies/{companyId}/customer-screen/lock', new PathItem(post: new Operation(
            operationId: 'lockCustomerScreen',
            tags: ['Products'],
            responses: [
                '204' => new Response('The sign-in is held on this company\'s customer screen'),
                '401' => new Response('Not signed in'),
                '403' => new Response('The sign-in is already held on a customer screen (customer_screen_locked)'),
                '404' => new Response('No such company, or no product.read'),
            ],
            summary: 'Opens the customer screen and holds the sign-in on it',
            description: 'Until it is left through DELETE /api/auth/customer-screen, every tab of this sign-in reaches only what the screen reads.',
            parameters: [new Parameter('companyId', 'path', 'The company', true, schema: ['type' => 'string', 'format' => 'uuid'])],
        )));

        return $openApi;
    }
}
