<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\CustomFields\Infrastructure\ApiPlatform\State\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\CustomFields\Application\CustomFieldNotFound;
use App\CustomFields\Application\ManageCustomFields;
use App\CustomFields\Domain\Exception\InvalidCustomFieldDefinition;
use App\CustomFields\Infrastructure\ApiPlatform\Resource\CustomFieldResource;
use App\Shared\Infrastructure\ApiPlatform\Output\DomainRule;
use App\Tenancy\Infrastructure\ApiPlatform\Payload\CompanyPath;
use App\Tenancy\Infrastructure\ApiPlatform\Security\CompanyGuard;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** @implements ProcessorInterface<CustomFieldResource, CustomFieldResource> */
final readonly class ReviseCustomFieldProcessor implements ProcessorInterface
{
    public function __construct(private ManageCustomFields $manage, private CompanyGuard $guard)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): CustomFieldResource
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), CustomFieldResource::WRITE_PERMISSION);

        try {
            $field = $this->manage->revise($company, CompanyPath::identifier($uriVariables, 'fieldId'), $data->input(), $this->guard->account()->getId(), $data->version);
        } catch (CustomFieldNotFound $missing) {
            throw new NotFoundHttpException($missing->getMessage(), $missing);
        } catch (InvalidCustomFieldDefinition $refused) {
            throw DomainRule::refused($refused->field, $refused->getMessage(), $refused->reason ?? 'invalid_value', $refused->params, $refused);
        }

        return CustomFieldResource::of($field);
    }
}
