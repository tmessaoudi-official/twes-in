<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Scanning\Infrastructure\ApiPlatform;

use ApiPlatform\OpenApi\Factory\OpenApiFactoryInterface;
use ApiPlatform\OpenApi\Model\MediaType;
use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\OpenApi\Model\Parameter;
use ApiPlatform\OpenApi\Model\PathItem;
use ApiPlatform\OpenApi\Model\RequestBody;
use ApiPlatform\OpenApi\Model\Response;
use ApiPlatform\OpenApi\OpenApi;
use App\Module\Scanning\Infrastructure\Http\PairedPhoneController;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;

/**
 * The phone pairing's plain controllers (docs/SPEC.md § 7, 2026-09-23 09:45, slice 4), for the SPA's types: the tab's
 * side, signed in under a company, and the phone's, with no session and its key in the X-Pairing-Key header.
 */
#[AsDecorator('api_platform.openapi.factory')]
final readonly class ScanningOpenApi implements OpenApiFactoryInterface
{
    public function __construct(private OpenApiFactoryInterface $decorated)
    {
    }

    public function __invoke(array $context = []): OpenApi
    {
        $openApi = ($this->decorated)($context);
        $schemas = $openApi->getComponents()->getSchemas() ?? new \ArrayObject();
        $uuid = ['type' => 'string', 'format' => 'uuid'];
        $schemas['ScanPairingOpened'] = new \ArrayObject([
            'type' => 'object',
            'required' => ['id', 'link', 'address'],
            'properties' => [
                'id' => $uuid,
                'link' => ['type' => 'string', 'description' => 'The single-use secret the phone claims; shown once, stored only as its hash.'],
                'address' => ['type' => ['string', 'null'], 'description' => 'The origin a phone opens the link at, when the deployment names one (PAIRING_ORIGIN); null: the tab\'s own.'],
            ],
        ]);
        $schemas['ScanPairingClaimed'] = new \ArrayObject([
            'type' => 'object',
            'required' => ['id', 'key'],
            'properties' => [
                'id' => $uuid,
                'key' => ['type' => 'string', 'description' => 'What the phone presents in X-Pairing-Key from now on.'],
            ],
        ]);
        $schemas['ScanPairingRefusal'] = new \ArrayObject([
            'type' => 'object',
            'required' => ['error'],
            'properties' => ['error' => ['type' => 'string', 'enum' => ['unknown', 'key', 'claimed', 'expired', 'ended', 'invalid']]],
        ]);
        $schemas['ScanPairingEcho'] = new \ArrayObject([
            'type' => 'object',
            'required' => ['id', 'scan', 'outcome', 'message', 'params', 'product', 'choices'],
            'properties' => [
                'id' => $uuid,
                'scan' => ['anyOf' => [$uuid, ['type' => 'null']], 'description' => 'The phone scan this answers.'],
                'outcome' => ['type' => 'string', 'enum' => ['done', 'refused', 'unclaimed']],
                'message' => ['type' => 'string', 'description' => 'A translation key.'],
                'params' => ['type' => 'object', 'additionalProperties' => ['anyOf' => [['type' => 'string'], ['type' => 'integer']]]],
                'product' => ['anyOf' => [
                    ['type' => 'object', 'required' => ['name', 'price'], 'properties' => ['name' => ['type' => 'string'], 'price' => ['type' => 'string']], 'additionalProperties' => false],
                    ['type' => 'null'],
                ]],
                'details' => ['type' => 'array', 'maxItems' => 6, 'items' => ['type' => 'string', 'maxLength' => 200], 'description' => 'Short lines the tab wrote about what was read.'],
                'choices' => ['type' => 'array', 'maxItems' => 8, 'items' => [
                    'type' => 'object', 'required' => ['id', 'label'], 'additionalProperties' => false,
                    'properties' => ['id' => ['type' => 'string'], 'label' => ['type' => 'string', 'description' => 'A translation key.']],
                ]],
            ],
        ]);
        $schemas['ScanPairingScan'] = new \ArrayObject([
            'type' => 'object',
            'required' => ['code', 'scan'],
            'properties' => ['code' => ['type' => 'string', 'maxLength' => 512], 'scan' => $uuid],
        ]);
        $schemas['ScanPairingChoice'] = new \ArrayObject([
            'type' => 'object',
            'required' => ['echo', 'choice'],
            'properties' => ['echo' => $uuid, 'choice' => ['type' => 'string']],
        ]);
        $schemas['ScanPairingLink'] = new \ArrayObject([
            'type' => 'object',
            'required' => ['link'],
            'properties' => ['link' => ['type' => 'string']],
        ]);

        $json = static fn (string $schema, string $description): Response => new Response($description, new \ArrayObject(['application/json' => new MediaType(new \ArrayObject(['$ref' => '#/components/schemas/'.$schema]))]));
        $body = static fn (string $schema): RequestBody => new RequestBody(content: new \ArrayObject(['application/json' => new MediaType(new \ArrayObject(['$ref' => '#/components/schemas/'.$schema]))]), required: true);
        $refused = $json('ScanPairingRefusal', 'Refused: unknown (404), key (403), claimed, expired or ended (410), invalid (422)');
        $company = new Parameter('companyId', 'path', 'The company the tab acts for', true, schema: $uuid);
        $pairing = new Parameter('id', 'path', 'The pairing', true, schema: $uuid);
        $key = new Parameter(PairedPhoneController::KEY_HEADER, 'header', 'The key the claim gave the phone', true, schema: ['type' => 'string']);
        $tab = new Parameter('X-Tab', 'header', 'The tab the scans go to', true, schema: ['type' => 'string', 'maxLength' => 64]);
        $tags = ['Scanning'];

        $paths = $openApi->getPaths();
        $paths->addPath('/api/companies/{companyId}/scan-pairings', new PathItem(post: new Operation(
            operationId: 'openScanPairing',
            tags: $tags,
            responses: ['201' => $json('ScanPairingOpened', 'A link for a phone to claim'), '404' => new Response('No such company, or no product.read in it'), '422' => $refused],
            summary: 'Lend a phone to this tab as a scanner',
            parameters: [$company, $tab],
        )));
        $paths->addPath('/api/companies/{companyId}/scan-pairings/{id}', new PathItem(delete: new Operation(
            operationId: 'endScanPairing',
            tags: $tags,
            responses: ['204' => new Response('Ended'), '404' => $refused],
            summary: 'The tab lets its phone go',
            parameters: [$company, $pairing],
        )));
        $paths->addPath('/api/companies/{companyId}/scan-pairings/{id}/heartbeat', new PathItem(post: new Operation(
            operationId: 'renewScanPairing',
            tags: $tags,
            responses: ['204' => new Response('Alive for another while'), '404' => $refused, '410' => $refused],
            summary: 'The tab says it is still there',
            parameters: [$company, $pairing],
        )));
        $paths->addPath('/api/companies/{companyId}/scan-pairings/{id}/echo', new PathItem(post: new Operation(
            operationId: 'echoScanPairing',
            tags: $tags,
            responses: ['202' => new Response('Sent to the phone'), '404' => $refused, '410' => $refused, '422' => $refused],
            summary: 'Tell the phone what a scan did',
            parameters: [$company, $pairing],
            requestBody: $body('ScanPairingEcho'),
        )));
        $paths->addPath('/api/scan-pairings/claim', new PathItem(post: new Operation(
            operationId: 'claimScanPairing',
            tags: $tags,
            responses: ['200' => $json('ScanPairingClaimed', 'Claimed'), '404' => $refused, '410' => $refused, '429' => new Response('Too many links tried from here')],
            summary: 'The phone claims the link, once',
            requestBody: $body('ScanPairingLink'),
        )));
        $paths->addPath('/api/scan-pairings/{id}/scans', new PathItem(post: new Operation(
            operationId: 'scanOnPairing',
            tags: $tags,
            responses: ['202' => new Response('Handed to the tab'), '403' => $refused, '404' => $refused, '410' => $refused, '422' => $refused, '429' => new Response('Too fast')],
            summary: 'The phone hands a code to its tab',
            parameters: [$pairing, $key],
            requestBody: $body('ScanPairingScan'),
        )));
        $paths->addPath('/api/scan-pairings/{id}/photos', new PathItem(post: new Operation(
            operationId: 'photoOnPairing',
            tags: $tags,
            responses: ['202' => new Response('Held for the tab, which is told'), '403' => $refused, '404' => $refused, '410' => $refused, '422' => $refused, '429' => new Response('Too many photos at once')],
            summary: 'The phone hands a photo it took to its tab',
            description: 'A JPEG, PNG or WebP picture, read from its bytes, no larger than a product photo may be (5 MB by default); at most 3 wait for the tab at once, each for a quarter of an hour.',
            parameters: [$pairing, $key],
            requestBody: new RequestBody(
                'The picture as a multipart part named file, and the phone\'s own id for it, which the echo answers',
                new \ArrayObject(['multipart/form-data' => new MediaType(new \ArrayObject([
                    'type' => 'object',
                    'required' => ['file', 'scan'],
                    'properties' => ['file' => ['type' => 'string', 'format' => 'binary'], 'scan' => $uuid],
                ]))]),
                true,
            ),
        )));
        $paths->addPath('/api/companies/{companyId}/scan-pairings/{id}/photos/{photoId}/take', new PathItem(post: new Operation(
            operationId: 'takeScanPairingPhoto',
            tags: $tags,
            responses: [
                '200' => new Response('The photo as the phone sent it; it no longer waits', new \ArrayObject([
                    'image/jpeg' => new MediaType(new \ArrayObject(['type' => 'string', 'format' => 'binary'])),
                    'image/png' => new MediaType(new \ArrayObject(['type' => 'string', 'format' => 'binary'])),
                    'image/webp' => new MediaType(new \ArrayObject(['type' => 'string', 'format' => 'binary'])),
                ])),
                '404' => $refused,
            ],
            summary: 'The tab takes a photo its phone took, once',
            description: 'Only the person who lent the phone, and only within a quarter of an hour of the photo.',
            parameters: [$company, $pairing, new Parameter('photoId', 'path', 'The photo, as the tab was told', true, schema: $uuid)],
        )));
        $paths->addPath('/api/scan-pairings/{id}/choices', new PathItem(post: new Operation(
            operationId: 'chooseOnPairing',
            tags: $tags,
            responses: ['202' => new Response('Handed to the tab'), '403' => $refused, '404' => $refused, '410' => $refused, '422' => $refused, '429' => new Response('Too fast')],
            summary: 'The phone taps one of the choices its tab offered',
            parameters: [$pairing, $key],
            requestBody: $body('ScanPairingChoice'),
        )));
        $paths->addPath('/api/scan-pairings/{id}/realtime-token', new PathItem(post: new Operation(
            operationId: 'scanPairingRealtimeToken',
            tags: $tags,
            responses: ['200' => $json('RealtimeToken', 'A connection token for the pairing\'s channel alone'), '403' => $refused, '404' => $refused, '410' => $refused],
            summary: 'The phone\'s realtime connection token',
            parameters: [$pairing, $key],
        )));

        return $openApi->withComponents($openApi->getComponents()->withSchemas($schemas));
    }
}
