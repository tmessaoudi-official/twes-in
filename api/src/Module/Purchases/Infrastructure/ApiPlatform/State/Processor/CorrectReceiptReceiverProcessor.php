<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Purchases\Infrastructure\ApiPlatform\State\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Module\Purchases\Application\PurchaseOrderNotFound;
use App\Module\Purchases\Application\PurchaseOrderWorkflow;
use App\Module\Purchases\Application\Receivers\GoodsReceiptNotFound;
use App\Module\Purchases\Domain\Exception\InvalidPurchaseOrder;
use App\Module\Purchases\Infrastructure\ApiPlatform\Output\PurchaseOrderView;
use App\Module\Purchases\Infrastructure\ApiPlatform\Resource\GoodsReceiptResource;
use App\Module\Purchases\Infrastructure\ApiPlatform\Resource\PurchaseOrderResource;
use App\Module\Purchases\Infrastructure\ApiPlatform\Security\PurchaseOrderPermission;
use App\Shared\Infrastructure\ApiPlatform\Output\DomainRule;
use App\Tenancy\Infrastructure\ApiPlatform\Payload\CompanyPath;
use App\Tenancy\Infrastructure\ApiPlatform\Security\CompanyGuard;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Who received a receipt's goods, put right by whoever receives goods: the order answered as it now stands; a receipt
 * of another order, or of another company's, is not there.
 *
 * @implements ProcessorInterface<GoodsReceiptResource, PurchaseOrderResource>
 */
final readonly class CorrectReceiptReceiverProcessor implements ProcessorInterface
{
    public function __construct(private PurchaseOrderWorkflow $workflow, private PurchaseOrderView $view, private CompanyGuard $guard)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): PurchaseOrderResource
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), PurchaseOrderPermission::RECEIVE);

        try {
            $order = $this->workflow->correctReceiver(
                $company,
                CompanyPath::identifier($uriVariables, 'purchaseOrderId'),
                CompanyPath::identifier($uriVariables, 'receiptId'),
                $data->receiverChoice(),
                $this->guard->account()->getId(),
            );
        } catch (PurchaseOrderNotFound $absent) {
            throw new NotFoundHttpException('No such purchase order.', $absent);
        } catch (GoodsReceiptNotFound $absent) {
            throw new NotFoundHttpException('No such receipt of this purchase order.', $absent);
        } catch (InvalidPurchaseOrder $refused) {
            throw DomainRule::refused($refused->field, $refused->getMessage(), $refused->reason ?? 'invalid_value', $refused->params, $refused);
        }

        return $this->view->of($order);
    }
}
