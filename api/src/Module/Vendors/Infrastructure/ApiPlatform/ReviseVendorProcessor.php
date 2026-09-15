<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Vendors\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Module\Vendors\Application\ManageVendors;
use App\Module\Vendors\Application\VendorNotFound;
use App\Module\Vendors\Application\VendorNumberTaken;
use App\Module\Vendors\Domain\InvalidVendor;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyGuard;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyPath;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/** @implements ProcessorInterface<VendorResource, VendorResource> */
final readonly class ReviseVendorProcessor implements ProcessorInterface
{
    public function __construct(private ManageVendors $manage, private CompanyGuard $guard)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): VendorResource
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), VendorPermission::WRITE);

        try {
            $vendor = $this->manage->revise($company, CompanyPath::identifier($uriVariables, 'vendorId'), $data->input($company), $this->guard->account()->getId());
        } catch (VendorNotFound $absent) {
            throw new NotFoundHttpException('No such vendor.', $absent);
        } catch (VendorNumberTaken $taken) {
            throw new ConflictHttpException($taken->getMessage(), $taken);
        } catch (InvalidVendor $refused) {
            throw new UnprocessableEntityHttpException(\sprintf('%s: %s', $refused->field, $refused->getMessage()), $refused);
        }

        return VendorResource::of($vendor);
    }
}
