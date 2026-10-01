<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Infrastructure\ApiPlatform;

use ApiPlatform\OpenApi\Factory\OpenApiFactoryInterface;
use ApiPlatform\OpenApi\Model\MediaType;
use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\OpenApi\Model\Parameter;
use ApiPlatform\OpenApi\Model\PathItem;
use ApiPlatform\OpenApi\Model\RequestBody;
use ApiPlatform\OpenApi\Model\Response;
use ApiPlatform\OpenApi\OpenApi;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;

/** Documents what the plain logo controller answers, which API Platform does not describe (CompanyLogoController). */
#[AsDecorator('api_platform.openapi.factory')]
final readonly class CompanyLogoOpenApi implements OpenApiFactoryInterface
{
    public function __construct(private OpenApiFactoryInterface $decorated)
    {
    }

    public function __invoke(array $context = []): OpenApi
    {
        $openApi = ($this->decorated)($context);
        $company = new Parameter('companyId', 'path', 'The company', true, schema: ['type' => 'string', 'format' => 'uuid']);

        $openApi->getPaths()->addPath('/api/companies/{companyId}/logo', new PathItem(
            get: new Operation(
                operationId: 'companyLogoContent',
                tags: ['CompanyProfile'],
                responses: [
                    '200' => new Response('The logo as it was uploaded', new \ArrayObject(['image/*' => new MediaType(new \ArrayObject(['type' => 'string', 'format' => 'binary']))])),
                    '401' => new Response('Not signed in'),
                    '404' => new Response('No such company, no company.read, or the company has no logo'),
                ],
                summary: 'The company\'s logo',
                parameters: [$company],
            ),
            post: new Operation(
                operationId: 'uploadCompanyLogo',
                tags: ['CompanyProfile'],
                responses: [
                    '201' => new Response('The logo is kept; its version is the id of the stored file', new \ArrayObject(['application/json' => new MediaType(new \ArrayObject(['type' => 'object', 'required' => ['logoVersion'], 'properties' => ['logoVersion' => ['type' => 'string', 'format' => 'uuid']]]))])),
                    '401' => new Response('Not signed in'),
                    '404' => new Response('No such company, or no company.settings'),
                    '422' => new Response('No file, an empty one, one too large, not a PNG, JPEG or WebP picture, or one too many pixels on a side'),
                ],
                summary: 'Sets the company\'s logo, replacing the one it had',
                description: 'PNG, JPEG or WebP, read from the bytes; at most 2 MiB and 4000 pixels on a side. A vector file is refused.',
                parameters: [$company],
                requestBody: new RequestBody(
                    'The picture, as a multipart part named file',
                    new \ArrayObject(['multipart/form-data' => new MediaType(new \ArrayObject(['type' => 'object', 'required' => ['file'], 'properties' => ['file' => ['type' => 'string', 'format' => 'binary']]]))]),
                    true,
                ),
            ),
            delete: new Operation(
                operationId: 'removeCompanyLogo',
                tags: ['CompanyProfile'],
                responses: [
                    '204' => new Response('The company has no logo (also when it had none)'),
                    '401' => new Response('Not signed in'),
                    '404' => new Response('No such company, or no company.settings'),
                ],
                summary: 'Removes the company\'s logo',
                parameters: [$company],
            ),
        ));

        return $openApi;
    }
}
