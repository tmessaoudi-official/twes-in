<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\DeliveryNotes\Infrastructure\ApiPlatform;

use ApiPlatform\OpenApi\Factory\OpenApiFactoryInterface;
use ApiPlatform\OpenApi\Model\MediaType;
use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\OpenApi\Model\Parameter;
use ApiPlatform\OpenApi\Model\PathItem;
use ApiPlatform\OpenApi\Model\Response;
use ApiPlatform\OpenApi\OpenApi;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;

/** Documents the PDF download, a plain controller API Platform does not describe (DeliveryNotePdfController). */
#[AsDecorator('api_platform.openapi.factory')]
final readonly class DeliveryNotesOpenApi implements OpenApiFactoryInterface
{
    public function __construct(private OpenApiFactoryInterface $decorated)
    {
    }

    public function __invoke(array $context = []): OpenApi
    {
        $openApi = ($this->decorated)($context);
        $uuid = static fn (string $name, string $description): Parameter => new Parameter($name, 'path', $description, true, schema: ['type' => 'string', 'format' => 'uuid']);

        $openApi->getPaths()->addPath('/api/companies/{companyId}/delivery-notes/{deliveryNoteId}/pdf', new PathItem(get: new Operation(
            operationId: 'deliveryNotePdf',
            tags: ['DeliveryNote'],
            responses: [
                '200' => new Response(
                    'The PDF: a validated or delivered note as it was issued; a draft or a cancelled note rendered across a watermark',
                    new \ArrayObject(['application/pdf' => new MediaType(new \ArrayObject(['type' => 'string', 'format' => 'binary']))]),
                ),
                '401' => new Response('Not signed in'),
                '404' => new Response('No such delivery note in this company, no delivery_note.read, or the module is switched off'),
                '503' => new Response('The renderer could not produce the PDF'),
            ],
            summary: 'A delivery note as a PDF',
            parameters: [$uuid('companyId', 'The company'), $uuid('deliveryNoteId', 'The delivery note')],
        )));

        return $openApi;
    }
}
