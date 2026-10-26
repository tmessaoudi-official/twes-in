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
use App\Module\Purchases\Application\PurchaseOrderNumberTaken;
use App\Module\Purchases\Application\PurchaseOrderWorkflow;
use App\Module\Purchases\Domain\Exception\InvalidPurchaseOrder;
use App\Module\Purchases\Domain\Exception\PurchaseOrderTransitionRefused;
use App\Module\Purchases\Infrastructure\ApiPlatform\Output\PurchaseOrderView;
use App\Module\Purchases\Infrastructure\ApiPlatform\Resource\PurchaseOrderResource;
use App\Module\Purchases\Infrastructure\ApiPlatform\Security\PurchaseOrderPermission;
use App\Shared\Infrastructure\ApiPlatform\Output\DomainRule;
use App\Tenancy\Application\Numbering\NoNumberingSeries;
use App\Tenancy\Domain\Exception\InvalidNumbering;
use App\Tenancy\Infrastructure\ApiPlatform\Payload\CompanyPath;
use App\Tenancy\Infrastructure\ApiPlatform\Security\CompanyGuard;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Numbers a draft and keeps who the vendor was that day. What the company's numbering cannot give is a 409, like an
 * order that is no longer a draft; what the order lacks is a 422.
 *
 * @implements ProcessorInterface<mixed, PurchaseOrderResource>
 */
final readonly class SendPurchaseOrderProcessor implements ProcessorInterface
{
    public function __construct(private PurchaseOrderWorkflow $workflow, private PurchaseOrderView $view, private CompanyGuard $guard)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): PurchaseOrderResource
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), PurchaseOrderPermission::WRITE);

        try {
            $order = $this->workflow->send($company, CompanyPath::identifier($uriVariables, 'purchaseOrderId'), $this->guard->account()->getId());
        } catch (PurchaseOrderNotFound $absent) {
            throw new NotFoundHttpException('No such purchase order.', $absent);
        } catch (PurchaseOrderTransitionRefused|NoNumberingSeries|InvalidNumbering|PurchaseOrderNumberTaken $conflict) {
            throw new ConflictHttpException($conflict->getMessage(), $conflict);
        } catch (InvalidPurchaseOrder $refused) {
            throw DomainRule::refused($refused->field, $refused->getMessage(), $refused->reason ?? 'invalid_value', $refused->params, $refused);
        }

        return $this->view->of($order);
    }
}
