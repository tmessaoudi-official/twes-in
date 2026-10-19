<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Infrastructure\ApiPlatform\State\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Module\Inventory\Application\KeepReorderPoints;
use App\Module\Inventory\Application\ProductNotInCompany;
use App\Module\Inventory\Domain\Exception\InvalidReorderPoint;
use App\Module\Inventory\Infrastructure\ApiPlatform\Resource\ProductReorderPointResource;
use App\Module\Products\Infrastructure\ApiPlatform\Security\ProductPermission;
use App\Tenancy\Domain\Model\Company;
use App\Tenancy\Infrastructure\ApiPlatform\Payload\CompanyPath;
use App\Tenancy\Infrastructure\ApiPlatform\Security\CompanyGuard;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * Sets a product's reorder point in the establishment the path names, changing the one it already had there, or clears
 * it when the quantity is null. Either answers the product's version it left.
 *
 * @implements ProcessorInterface<ProductReorderPointResource, ProductReorderPointResource>
 */
final readonly class SetProductReorderPointProcessor implements ProcessorInterface
{
    public function __construct(private KeepReorderPoints $points, private CompanyGuard $guard)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ProductReorderPointResource
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), ProductPermission::WRITE);

        $productId = CompanyPath::identifier($uriVariables, 'productId');
        $establishmentId = CompanyPath::identifier($uriVariables, 'establishmentId');
        try {
            if (null === $data->quantity) {
                $version = $this->points->clear($company, $productId, $establishmentId, $this->guard->account()->getId(), $data->version);

                return $this->cleared($company, $productId, $establishmentId, $version);
            }
            $point = $this->points->set($company, $productId, $establishmentId, $data->quantity, $this->guard->account()->getId(), $data->version);
        } catch (ProductNotInCompany $absent) {
            throw new NotFoundHttpException($absent->getMessage(), $absent);
        } catch (InvalidReorderPoint $refused) {
            throw new UnprocessableEntityHttpException(\sprintf('%s: %s', $refused->field, $refused->getMessage()), $refused);
        }

        $written = ProductReorderPointResource::of($point->getEstablishment(), $point);
        // Read once the transaction is stored: the version moves as the change is written.
        $written->productVersion = $point->getProduct()->getVersion();

        return $written;
    }

    /**
     * The establishment as the list reads it without a point.
     *
     * @throws ProductNotInCompany
     */
    private function cleared(Company $company, Uuid $productId, Uuid $establishmentId, int $version): ProductReorderPointResource
    {
        foreach ($this->points->of($company, $productId) as [$establishment, $point]) {
            if ($establishment->getId()->equals($establishmentId)) {
                $written = ProductReorderPointResource::of($establishment, $point);
                $written->productVersion = $version;

                return $written;
            }
        }
        throw new NotFoundHttpException('No establishment of this company has this id.');
    }
}
