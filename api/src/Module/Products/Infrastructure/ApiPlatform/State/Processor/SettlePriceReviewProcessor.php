<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Products\Infrastructure\ApiPlatform\State\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Fiscal\Application\CurrencyScales;
use App\Module\Products\Application\NothingToReview;
use App\Module\Products\Application\Photo\ProductPhotos;
use App\Module\Products\Application\ProductMergedAway;
use App\Module\Products\Application\ProductNotFound;
use App\Module\Products\Application\ReviewProductPrice;
use App\Module\Products\Infrastructure\ApiPlatform\Resource\ProductPriceReviewResource;
use App\Module\Products\Infrastructure\ApiPlatform\Resource\ProductResource;
use App\Module\Products\Infrastructure\ApiPlatform\Security\ProductPermission;
use App\Shared\Infrastructure\ApiPlatform\Output\RecordChangedProblem;
use App\Tenancy\Infrastructure\ApiPlatform\Payload\CompanyPath;
use App\Tenancy\Infrastructure\ApiPlatform\Security\CompanyGuard;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** @implements ProcessorInterface<ProductPriceReviewResource, ProductResource> */
final readonly class SettlePriceReviewProcessor implements ProcessorInterface
{
    public function __construct(
        private ReviewProductPrice $review,
        private CompanyGuard $guard,
        private ProductPhotos $photos,
        private CurrencyScales $scales,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ProductResource
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), ProductPermission::WRITE);
        // A margin is read off the cost: whoever may not read costs is told there is no such thing to settle.
        if (!$this->guard->may($company, ProductPermission::COST_READ)) {
            throw new NotFoundHttpException('No such product.');
        }
        if (null === $data->version) {
            throw new \LogicException('The version is required by validation.');
        }

        try {
            $product = $this->review->handle($company, CompanyPath::identifier($uriVariables, 'productId'), 'adjust' === $data->decision, $data->version, $this->guard->account()->getId());
        } catch (ProductMergedAway $merged) {
            throw new RecordChangedProblem($merged);
        } catch (ProductNotFound $absent) {
            throw new NotFoundHttpException('No such product.', $absent);
        } catch (NothingToReview $settled) {
            throw new ConflictHttpException($settled->getMessage(), $settled);
        }

        return ProductResource::of($product, true, $this->scales->of($company->getCurrency()), $this->photos->mainPhotoIdsOf($company, [$product->getId()])[$product->getId()->toRfc4122()] ?? null);
    }
}
