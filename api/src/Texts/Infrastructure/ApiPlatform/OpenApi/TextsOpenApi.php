<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Texts\Infrastructure\ApiPlatform\OpenApi;

use ApiPlatform\OpenApi\Factory\OpenApiFactoryInterface;
use ApiPlatform\OpenApi\Model\MediaType;
use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\OpenApi\Model\Parameter;
use ApiPlatform\OpenApi\Model\PathItem;
use ApiPlatform\OpenApi\Model\RequestBody;
use ApiPlatform\OpenApi\Model\Response;
use ApiPlatform\OpenApi\OpenApi;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;

/**
 * TextsController's endpoints are plain controllers, unknown to API Platform; the SPA's types come from this document,
 * so they are added here with every always-present property required.
 */
#[AsDecorator('api_platform.openapi.factory')]
final readonly class TextsOpenApi implements OpenApiFactoryInterface
{
    public function __construct(private OpenApiFactoryInterface $decorated)
    {
    }

    public function __invoke(array $context = []): OpenApi
    {
        $openApi = ($this->decorated)($context);
        $schemas = $openApi->getComponents()->getSchemas() ?? new \ArrayObject();

        $schemas['ScreenTextOverrides'] = new \ArrayObject([
            'type' => 'object',
            'description' => 'By screen file (`screen`, or `screen:<part>` for a page\'s own texts), each text worded otherwise by its dotted key; a file with none is absent.',
            'additionalProperties' => ['type' => 'object', 'additionalProperties' => ['type' => 'string']],
        ]);
        $schemas['TextOverrideChange'] = new \ArrayObject([
            'type' => 'object',
            'required' => ['value', 'version'],
            'properties' => [
                'value' => ['type' => 'string', 'description' => 'Keeps every {{name}} or %name% of the original, and its plural forms.'],
                'version' => ['type' => 'integer', 'minimum' => 0, 'description' => 'The version read; 0 when the text was not worded otherwise at this level.'],
            ],
        ]);
        $schemas['TextOverride'] = new \ArrayObject([
            'type' => 'object',
            'required' => ['value', 'original', 'version'],
            'properties' => [
                'value' => ['type' => 'string'],
                'original' => ['type' => 'string', 'description' => 'The text shipped when this one was written.'],
                'version' => ['type' => 'integer'],
            ],
        ]);
        $schemas['TextOverrideRefusal'] = new \ArrayObject([
            'type' => 'object',
            'required' => ['error', 'detail'],
            'properties' => [
                'error' => ['type' => 'string', 'enum' => ['invalid_body', 'unknown_text', 'language', 'locked', 'empty', 'too_long', 'placeholders', 'plural'], 'description' => 'Why the text is refused; the screen words it.'],
                'detail' => ['type' => 'string'],
            ],
        ]);

        $schemas['TextCatalogueEntry'] = new \ArrayObject([
            'type' => 'object',
            'required' => ['domain', 'key', 'original', 'installation', 'company', 'version', 'originalChanged'],
            'properties' => [
                'domain' => ['type' => 'string'],
                'key' => ['type' => 'string'],
                'original' => ['type' => 'string', 'description' => 'The text this release ships.'],
                'installation' => ['type' => ['string', 'null'], 'description' => 'The installation\'s wording, null where none.'],
                'company' => ['type' => ['string', 'null'], 'description' => 'The company\'s own wording; always null in the installation\'s catalogue.'],
                'version' => ['type' => 'integer', 'description' => 'The version of the reader\'s own level, 0 where it words nothing.'],
                'originalChanged' => ['type' => 'boolean', 'description' => 'The reader\'s own wording was written over another original than this release ships.'],
            ],
        ]);
        $schemas['TextCataloguePage'] = new \ArrayObject([
            'type' => 'object',
            'required' => ['items', 'total', 'page', 'itemsPerPage'],
            'properties' => [
                'items' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/TextCatalogueEntry'], 'description' => 'In the files\' order: the screens, each page\'s own, then the API\'s.'],
                'total' => ['type' => 'integer'],
                'page' => ['type' => 'integer'],
                'itemsPerPage' => ['type' => 'integer'],
            ],
        ]);

        $json = static fn (string $schema, string $description): Response => new Response($description, new \ArrayObject(['application/json' => new MediaType(new \ArrayObject(['$ref' => '#/components/schemas/'.$schema]))]));
        $language = new Parameter('language', 'path', 'A language the screens speak', true, schema: ['type' => 'string']);
        $company = new Parameter('companyId', 'path', 'The company', true, schema: ['type' => 'string', 'format' => 'uuid']);
        $address = [
            $language,
            new Parameter('domain', 'path', '`screen`, `screen:<part>`, or the API\'s `emails`, `pdf`, `imports`, `roles`', true, schema: ['type' => 'string']),
            new Parameter('key', 'path', 'The text\'s dotted key', true, schema: ['type' => 'string']),
        ];
        $change = new RequestBody('The text and the version read', new \ArrayObject(['application/json' => new MediaType(new \ArrayObject(['$ref' => '#/components/schemas/TextOverrideChange']))]), true);
        $written = [
            '200' => $json('TextOverride', 'Kept'),
            '401' => new Response('Not signed in'),
            '409' => new Response('Saved by someone else since that version was read (/problems/record-changed)'),
            '422' => $json('TextOverrideRefusal', 'Refused, with its reason'),
        ];
        $notSpoken = new Response('Not a language the screens speak');

        $find = [
            new Parameter('q', 'query', 'Words the text says in any wording, or its key; case and accents aside', false, schema: ['type' => 'string']),
            new Parameter('domain', 'query', 'One file', false, schema: ['type' => 'string']),
            new Parameter('changed', 'query', 'Only the texts whose original changed since the reader\'s own was written', false, schema: ['type' => 'boolean']),
            new Parameter('page', 'query', 'From 1', false, schema: ['type' => 'integer', 'minimum' => 1]),
            new Parameter('itemsPerPage', 'query', 'Up to app.texts.catalogue_page_max', false, schema: ['type' => 'integer', 'minimum' => 1]),
        ];
        $badQuery = new Response('invalid_query');

        $paths = $openApi->getPaths();
        $paths->addPath('/api/texts/{language}', new PathItem(get: new Operation(
            operationId: 'installationScreenTexts',
            tags: ['Texts'],
            responses: ['200' => $json('ScreenTextOverrides', 'The installation\'s, with an ETag'), '304' => new Response('Unchanged'), '404' => $notSpoken],
            summary: 'The screens\' texts the installation words otherwise, read with no session',
            parameters: [$language],
        )));
        $paths->addPath('/api/companies/{companyId}/texts/{language}', new PathItem(get: new Operation(
            operationId: 'companyScreenTexts',
            tags: ['Texts'],
            responses: ['200' => $json('ScreenTextOverrides', 'The installation\'s, the company\'s own over them'), '304' => new Response('Unchanged'), '401' => new Response('Not signed in'), '404' => $notSpoken],
            summary: 'The screens\' texts worded otherwise in a company',
            parameters: [$company, $language],
        )));
        $paths->addPath('/api/platform/texts/{language}/catalogue', new PathItem(get: new Operation(
            operationId: 'installationTextCatalogue',
            tags: ['Texts'],
            responses: ['200' => $json('TextCataloguePage', 'Every text the installation may word otherwise'), '401' => new Response('Not signed in'), '403' => new Response('Not an operator'), '404' => $notSpoken, '422' => $badQuery],
            summary: 'The texts an operator may word otherwise, found and paged',
            parameters: [$language, ...$find],
        )));
        $paths->addPath('/api/companies/{companyId}/texts/{language}/catalogue', new PathItem(get: new Operation(
            operationId: 'companyTextCatalogue',
            tags: ['Texts'],
            responses: ['200' => $json('TextCataloguePage', 'Every text the company may word otherwise'), '401' => new Response('Not signed in'), '404' => new Response('No such company for this person, or company.texts not held there; or not a language the screens speak'), '422' => $badQuery],
            summary: 'The texts a company may word otherwise, beside the installation\'s, found and paged',
            parameters: [$company, $language, ...$find],
        )));
        $paths->addPath('/api/platform/texts/{language}/{domain}/{key}', new PathItem(
            put: new Operation(
                operationId: 'overrideInstallationText',
                tags: ['Texts'],
                responses: $written + ['403' => new Response('Not an operator, or step_up_required')],
                summary: 'Word a text otherwise for the whole installation',
                parameters: $address,
                requestBody: $change,
            ),
            delete: new Operation(
                operationId: 'resetInstallationText',
                tags: ['Texts'],
                responses: ['204' => new Response('The original is back'), '401' => new Response('Not signed in'), '403' => new Response('Not an operator, or step_up_required')],
                summary: 'Put the shipped text back for the installation',
                parameters: $address,
            ),
        ));
        $paths->addPath('/api/companies/{companyId}/texts/{language}/{domain}/{key}', new PathItem(
            put: new Operation(
                operationId: 'overrideCompanyText',
                tags: ['Texts'],
                responses: $written + ['404' => new Response('No such company for this person, or company.texts not held there')],
                summary: 'Word a text otherwise for one company, over the installation\'s',
                parameters: [$company, ...$address],
                requestBody: $change,
            ),
            delete: new Operation(
                operationId: 'resetCompanyText',
                tags: ['Texts'],
                responses: ['204' => new Response('The installation\'s text, or the shipped one, is back'), '401' => new Response('Not signed in'), '404' => new Response('No such company for this person, or company.texts not held there')],
                summary: 'Put back for one company what the installation shows',
                parameters: [$company, ...$address],
            ),
        ));

        return $openApi->withComponents($openApi->getComponents()->withSchemas($schemas));
    }
}
