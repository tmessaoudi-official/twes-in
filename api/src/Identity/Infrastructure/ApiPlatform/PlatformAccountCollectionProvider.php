<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Identity\Application\Account\ManageAccounts;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/** @implements ProviderInterface<PlatformAccountResource> */
final readonly class PlatformAccountCollectionProvider implements ProviderInterface
{
    /** A search names a few accounts; the platform never pages through all of them. */
    private const int LIMIT = 50;

    public function __construct(private ManageAccounts $accounts)
    {
    }

    /** @return list<PlatformAccountResource> */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $filters = $context['filters'] ?? [];
        $text = \is_array($filters) ? ($filters['q'] ?? '') : '';
        if (!\is_string($text)) {
            throw new BadRequestHttpException('q: expected a single value.');
        }

        return array_map(PlatformAccountResource::of(...), $this->accounts->find($text, self::LIMIT));
    }
}
