<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\CustomFields\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\CustomFields\Application\ManageCustomFields;
use App\CustomFields\Domain\CustomFieldDefinition;
use App\CustomFields\Domain\CustomFieldEntity;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyGuard;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyPath;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/** @implements ProviderInterface<CustomFieldResource> */
final readonly class CustomFieldCollectionProvider implements ProviderInterface
{
    public function __construct(private ManageCustomFields $manage, private CompanyGuard $guard)
    {
    }

    /** @return list<CustomFieldResource> */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), CustomFieldResource::READ_PERMISSION);
        $filters = $context['filters'] ?? [];
        $named = \is_array($filters) && \is_string($filters['entity'] ?? null) ? $filters['entity'] : CustomFieldEntity::Customer->value;
        $entity = CustomFieldEntity::tryFrom($named) ?? throw new BadRequestHttpException(\sprintf('entity: no kind of record "%s" takes custom fields.', $named));

        return array_map(static fn (CustomFieldDefinition $field) => CustomFieldResource::of($field), $this->manage->list($company, $entity));
    }
}
