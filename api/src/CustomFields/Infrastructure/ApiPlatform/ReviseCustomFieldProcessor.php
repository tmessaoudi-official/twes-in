<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\CustomFields\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\CustomFields\Application\CustomFieldNotFound;
use App\CustomFields\Application\ManageCustomFields;
use App\CustomFields\Domain\InvalidCustomFieldDefinition;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyGuard;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyPath;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

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
            $field = $this->manage->revise($company, CompanyPath::identifier($uriVariables, 'fieldId'), $data->input(), $this->guard->account()->getId());
        } catch (CustomFieldNotFound $missing) {
            throw new NotFoundHttpException($missing->getMessage(), $missing);
        } catch (InvalidCustomFieldDefinition $refused) {
            throw new UnprocessableEntityHttpException(\sprintf('%s: %s', $refused->field, $refused->getMessage()), $refused);
        }

        return CustomFieldResource::of($field);
    }
}
