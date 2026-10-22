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
use App\Module\Products\Application\ManageProducts;
use App\Module\Products\Application\Photo\ProductPhotos;
use App\Module\Products\Application\ProductBarcodeTaken;
use App\Module\Products\Application\ProductCheckScopes;
use App\Module\Products\Application\ProductMergedAway;
use App\Module\Products\Application\ProductNotFound;
use App\Module\Products\Application\ProductReferenceTaken;
use App\Module\Products\Domain\Exception\InvalidProduct;
use App\Module\Products\Infrastructure\ApiPlatform\Output\BarcodeConflict;
use App\Module\Products\Infrastructure\ApiPlatform\Output\ProductGrossPrice;
use App\Module\Products\Infrastructure\ApiPlatform\Resource\ProductResource;
use App\Module\Products\Infrastructure\ApiPlatform\Security\ProductPermission;
use App\Shared\Infrastructure\ApiPlatform\Output\DomainRule;
use App\Shared\Infrastructure\ApiPlatform\Output\RecordChangedProblem;
use App\Tenancy\Infrastructure\ApiPlatform\Payload\CompanyPath;
use App\Tenancy\Infrastructure\ApiPlatform\Security\CompanyGuard;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** @implements ProcessorInterface<ProductResource, ProductResource> */
final readonly class ReviseProductProcessor implements ProcessorInterface
{
    public function __construct(private ManageProducts $manage, private CompanyGuard $guard, private ProductPhotos $photos, private CurrencyScales $scales, private ProductCheckScopes $checkScopes, private ProductGrossPrice $gross)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ProductResource
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), ProductPermission::WRITE);

        try {
            $seesCosts = $this->guard->may($company, ProductPermission::COST_READ);
            $product = $this->manage->revise($company, CompanyPath::identifier($uriVariables, 'productId'), $data->input($seesCosts), $this->guard->account()->getId(), $data->version);
        } catch (ProductMergedAway $merged) {
            // It changed since the form was opened: the screen reads it again and finds it merged away, read-only.
            throw new RecordChangedProblem($merged);
        } catch (ProductNotFound $absent) {
            throw new NotFoundHttpException('No such product.', $absent);
        } catch (ProductBarcodeTaken $taken) {
            throw BarcodeConflict::of($taken);
        } catch (ProductReferenceTaken $taken) {
            throw new ConflictHttpException($taken->getMessage(), $taken);
        } catch (InvalidProduct $refused) {
            throw DomainRule::refused($refused->field, $refused->getMessage(), $refused->reason ?? 'invalid_value', $refused->params, $refused);
        }

        return ProductResource::of($product, $this->checkScopes->of($company, $seesCosts), $this->scales->of($company->getCurrency()), $this->photos->mainPhotoIdsOf($company, [$product->getId()])[$product->getId()->toRfc4122()] ?? null, unitPriceGross: $this->gross->of($product));
    }
}
