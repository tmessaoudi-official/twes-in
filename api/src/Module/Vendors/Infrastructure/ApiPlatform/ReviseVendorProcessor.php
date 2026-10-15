<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Vendors\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Identity\Infrastructure\StepUp\RecentProof;
use App\Module\Vendors\Application\ManageVendors;
use App\Module\Vendors\Application\VendorNotFound;
use App\Module\Vendors\Application\VendorNumberTaken;
use App\Module\Vendors\Domain\InvalidVendor;
use App\Module\Vendors\Domain\VendorRepository;
use App\Shared\Infrastructure\ApiPlatform\DomainRule;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyGuard;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyPath;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * A vendor whose IBAN or BIC is set or changed asks who is at the screen again, as the company's own bank details do
 * (docs/SPEC.md § 7, the step-up list); the rest of a vendor does not. Asked last, so a vendor that is not there is
 * not found as before.
 *
 * @implements ProcessorInterface<VendorResource, VendorResource>
 */
final readonly class ReviseVendorProcessor implements ProcessorInterface
{
    public function __construct(private ManageVendors $manage, private CompanyGuard $guard, private VendorRepository $vendors, private RecentProof $proof)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): VendorResource
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), VendorPermission::WRITE);

        $vendorId = CompanyPath::identifier($uriVariables, 'vendorId');
        try {
            $input = $data->input($company);
            $held = $this->vendors->ofIdInCompany($vendorId, $company->getId());
            if (null !== $held && $input->profile->changesBankDetailsOf($held->getProfile())) {
                $this->proof->demand($this->guard->account()->getId());
            }
            $vendor = $this->manage->revise($company, $vendorId, $input, $this->guard->account()->getId());
        } catch (VendorNotFound $absent) {
            throw new NotFoundHttpException('No such vendor.', $absent);
        } catch (VendorNumberTaken $taken) {
            throw new ConflictHttpException($taken->getMessage(), $taken);
        } catch (InvalidVendor $refused) {
            throw DomainRule::refused($refused->field, $refused->getMessage(), $refused->reason, $refused->params, $refused);
        }

        return VendorResource::of($vendor);
    }
}
