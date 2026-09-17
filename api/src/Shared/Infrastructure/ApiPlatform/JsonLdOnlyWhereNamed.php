<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\HttpOperation;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Resource\Factory\ResourceMetadataCollectionFactoryInterface;
use ApiPlatform\Metadata\Resource\ResourceMetadataCollection;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;

/**
 * JSON-LD where an operation names it as its only output format, plain JSON everywhere else (docs/SPEC.md § 7, lists
 * at scale). A paged list says `outputFormats: ['jsonld' => ['application/ld+json']]` and answers Hydra with its total;
 * a single record, a write and every list not yet paged keep answering and reading plain JSON only. Runs after
 * API Platform has filled each operation's formats from `api_platform.formats` (priority 200) and before the metadata
 * is cached (-10).
 */
#[AsDecorator('api_platform.metadata.resource.metadata_collection_factory', priority: 100)]
final readonly class JsonLdOnlyWhereNamed implements ResourceMetadataCollectionFactoryInterface
{
    private const string JSONLD = 'jsonld';

    public function __construct(#[AutowireDecorated] private ResourceMetadataCollectionFactoryInterface $inner)
    {
    }

    public function create(string $resourceClass): ResourceMetadataCollection
    {
        $collection = $this->inner->create($resourceClass);
        foreach ($collection as $index => $resource) {
            $operations = $resource->getOperations();
            if (null === $operations) {
                continue;
            }
            foreach ($operations as $name => $operation) {
                $operations->add($name, $this->plainUnlessNamed($operation));
            }
            $collection[$index] = $resource->withOperations($operations);
        }

        return $collection;
    }

    private function plainUnlessNamed(Operation $operation): Operation
    {
        if (!$operation instanceof HttpOperation) {
            return $operation;
        }
        $output = $operation->getOutputFormats();
        if (\is_array($output) && [self::JSONLD] !== array_keys($output)) {
            unset($output[self::JSONLD]);
            $operation = $operation->withOutputFormats($output);
        }
        $input = $operation->getInputFormats();
        if (\is_array($input)) {
            unset($input[self::JSONLD]);
            $operation = $operation->withInputFormats($input);
        }

        return $operation;
    }
}
