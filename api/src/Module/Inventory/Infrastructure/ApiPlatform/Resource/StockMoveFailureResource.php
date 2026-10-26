<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Infrastructure\ApiPlatform\Resource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;
use ApiPlatform\OpenApi\Model\Parameter;
use App\Module\Inventory\Domain\Model\StockMoveFailure;
use App\Module\Inventory\Domain\ValueObject\FailedMove;
use App\Module\Inventory\Infrastructure\ApiPlatform\State\Processor\ReplayStockMoveProcessor;
use App\Module\Inventory\Infrastructure\ApiPlatform\State\Processor\SettleStockMoveProcessor;
use App\Module\Inventory\Infrastructure\ApiPlatform\State\Provider\StockMoveFailureCollectionProvider;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * A document's stock move that failed after the document was kept (workflow hunt, CON-1): read with stock.read, by the
 * document's page and « À surveiller »; moved again or said settled with stock.write. Moving it again answers whether
 * the stock moved (`settled`): one that failed again stays, and is said so. An unknown failure, or one settled
 * already, answers 404.
 */
#[ApiResource(
    shortName: 'StockMoveFailure',
    operations: [
        new GetCollection(
            uriTemplate: '/companies/{companyId}/stock-move-failures',
            provider: StockMoveFailureCollectionProvider::class,
            security: 'is_granted("ROLE_USER")',
            normalizationContext: ['groups' => [self::READ]],
            openapi: new OpenApiOperation(parameters: [
                new Parameter('document', 'query', 'The delivery note, invoice or credit note whose failed moves are read.', true, schema: ['type' => 'string', 'format' => 'uuid']),
            ]),
        ),
        new Post(
            uriTemplate: '/companies/{companyId}/stock-move-failures/{failureId}/replay',
            status: 200,
            processor: ReplayStockMoveProcessor::class,
            security: 'is_granted("ROLE_USER")',
            read: false,
            deserialize: false,
            input: false,
            normalizationContext: ['groups' => [self::READ]],
        ),
        new Delete(
            uriTemplate: '/companies/{companyId}/stock-move-failures/{failureId}',
            processor: SettleStockMoveProcessor::class,
            security: 'is_granted("ROLE_USER")',
            read: false,
        ),
    ],
)]
final class StockMoveFailureResource
{
    public const string READ = 'stock_move_failure:read';

    #[ApiProperty(identifier: false, writable: false)]
    #[Groups([self::READ])]
    public string $id = '';

    /** Which move failed: a delivery note's validation or cancellation, an invoice's issue, a credit note's return. */
    #[ApiProperty(schema: ['type' => 'string', 'enum' => ['delivery_note', 'delivery_note_cancelled', 'invoice', 'credit_note']])]
    #[Groups([self::READ])]
    public string $move = FailedMove::DeliveryNote->value;

    #[Groups([self::READ])]
    public string $documentId = '';

    /** The document's number when its move failed. */
    #[Groups([self::READ])]
    public string $number = '';

    /** When it failed, ISO 8601. */
    #[Groups([self::READ])]
    public string $failedAt = '';

    /** In the answer to moving it again: whether the stock has moved, the failure gone with it. */
    #[Groups([self::READ])]
    public bool $settled = false;

    public static function of(StockMoveFailure $failure, bool $settled = false): self
    {
        $resource = new self();
        $resource->id = $failure->getId()->toRfc4122();
        $resource->move = $failure->getMove()->value;
        $resource->documentId = $failure->getDocumentId()->toRfc4122();
        $resource->number = $failure->getNumber();
        $resource->failedAt = $failure->getFailedAt()->format(\DATE_ATOM);
        $resource->settled = $settled;

        return $resource;
    }
}
