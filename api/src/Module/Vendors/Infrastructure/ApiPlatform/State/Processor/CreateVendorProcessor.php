<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Vendors\Infrastructure\ApiPlatform\State\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Identity\Infrastructure\StepUp\RecentProof;
use App\Module\Vendors\Application\ManageVendors;
use App\Module\Vendors\Application\VendorNumberTaken;
use App\Module\Vendors\Domain\Exception\InvalidVendor;
use App\Module\Vendors\Infrastructure\ApiPlatform\Resource\VendorResource;
use App\Module\Vendors\Infrastructure\ApiPlatform\Security\VendorPermission;
use App\Shared\Infrastructure\ApiPlatform\Output\DomainRule;
use App\Tenancy\Infrastructure\ApiPlatform\Payload\CompanyPath;
use App\Tenancy\Infrastructure\ApiPlatform\Security\CompanyGuard;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * A vendor created with an IBAN or a BIC asks who is at the screen again, as the company's own bank details do
 * (docs/SPEC.md § 7, the step-up list), after the company, the permission and the body.
 *
 * @implements ProcessorInterface<VendorResource, VendorResource>
 */
final readonly class CreateVendorProcessor implements ProcessorInterface
{
    public function __construct(private ManageVendors $manage, private CompanyGuard $guard, private RecentProof $proof)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): VendorResource
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), VendorPermission::WRITE);

        try {
            $input = $data->input($company);
            if ($input->profile->changesBankDetailsOf(null)) {
                $this->proof->demand($this->guard->account()->getId());
            }
            $vendor = $this->manage->create($company, $input, $this->guard->account()->getId());
        } catch (VendorNumberTaken $taken) {
            throw new ConflictHttpException($taken->getMessage(), $taken);
        } catch (InvalidVendor $refused) {
            throw DomainRule::refused($refused->field, $refused->getMessage(), $refused->reason, $refused->params, $refused);
        }

        return VendorResource::of($vendor);
    }
}
