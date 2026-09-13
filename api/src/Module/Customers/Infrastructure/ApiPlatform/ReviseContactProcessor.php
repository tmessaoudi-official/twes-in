<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Customers\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Module\Customers\Application\ContactNotFound;
use App\Module\Customers\Application\CustomerNotFound;
use App\Module\Customers\Application\ManageContacts;
use App\Module\Customers\Domain\InvalidContact;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyGuard;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyPath;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/** @implements ProcessorInterface<ContactResource, ContactResource> */
final readonly class ReviseContactProcessor implements ProcessorInterface
{
    public function __construct(private ManageContacts $manage, private CompanyGuard $guard)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ContactResource
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), CustomerPermission::WRITE);

        try {
            $contact = $this->manage->revise(
                $company,
                CompanyPath::identifier($uriVariables, 'customerId'),
                CompanyPath::identifier($uriVariables, 'contactId'),
                $data->details(),
                $data->isPrimary,
                $this->guard->account()->getId(),
            );
        } catch (CustomerNotFound|ContactNotFound $absent) {
            throw new NotFoundHttpException('No such contact.', $absent);
        } catch (InvalidContact $refused) {
            throw new UnprocessableEntityHttpException(\sprintf('%s: %s', $refused->field, $refused->getMessage()), $refused);
        }

        return ContactResource::of($contact);
    }
}
