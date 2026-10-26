<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Purchases\Infrastructure\ApiPlatform\State\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Module\Products\Infrastructure\ApiPlatform\Security\ProductPermission;
use App\Module\Purchases\Application\IntakeCost;
use App\Module\Purchases\Application\PurchaseOrderNotFound;
use App\Module\Purchases\Application\PurchaseOrderWorkflow;
use App\Module\Purchases\Domain\Exception\InvalidPurchaseOrder;
use App\Module\Purchases\Domain\Exception\PurchaseOrderTransitionRefused;
use App\Module\Purchases\Domain\ValueObject\ReceivedGoods;
use App\Module\Purchases\Infrastructure\ApiPlatform\Output\PurchaseOrderView;
use App\Module\Purchases\Infrastructure\ApiPlatform\Resource\GoodsReceiptResource;
use App\Module\Purchases\Infrastructure\ApiPlatform\Resource\PurchaseOrderResource;
use App\Module\Purchases\Infrastructure\ApiPlatform\Security\PurchaseOrderPermission;
use App\Shared\Infrastructure\ApiPlatform\Output\DomainRule;
use App\Tenancy\Infrastructure\ApiPlatform\Payload\CompanyPath;
use App\Tenancy\Infrastructure\ApiPlatform\Security\CompanyGuard;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * Records goods that arrived against an order and puts them on the shelves, answering the order as it now stands. An
 * order that takes no goods (a draft, closed, cancelled, received) is a 409; what a line gets wrong is a 422 naming it.
 *
 * @implements ProcessorInterface<GoodsReceiptResource, PurchaseOrderResource>
 */
final readonly class ReceiveGoodsProcessor implements ProcessorInterface
{
    public function __construct(private PurchaseOrderWorkflow $workflow, private PurchaseOrderView $view, private CompanyGuard $guard)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): PurchaseOrderResource
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), PurchaseOrderPermission::RECEIVE);
        $id = CompanyPath::identifier($uriVariables, 'purchaseOrderId');

        try {
            $goods = [];
            foreach ($data->lines as $index => $line) {
                try {
                    $expires = self::text($line, 'lotExpiresOn');
                    $goods[] = new ReceivedGoods(
                        Uuid::fromString(self::text($line, 'orderLineId') ?? ''),
                        Uuid::fromString(self::text($line, 'locationId') ?? ''),
                        self::text($line, 'quantity') ?? '',
                        self::text($line, 'lotCode'),
                        null === $expires ? null : new \DateTimeImmutable($expires, new \DateTimeZone('UTC')),
                        self::text($line, 'unitCost'),
                    );
                } catch (InvalidPurchaseOrder $refused) {
                    throw $refused->within(\sprintf('lines[%d]', $index));
                }
            }
            $receipt = $this->workflow->receive(
                $company,
                $id,
                new \DateTimeImmutable($data->receivedOn, new \DateTimeZone('UTC')),
                $data->supplierReference,
                $goods,
                $this->guard->may($company, ProductPermission::COST_READ),
                null === $data->apply ? null : IntakeCost::from($data->apply),
                $this->guard->account()->getId(),
            );
        } catch (PurchaseOrderNotFound $absent) {
            throw new NotFoundHttpException('No such purchase order.', $absent);
        } catch (PurchaseOrderTransitionRefused $conflict) {
            throw new ConflictHttpException($conflict->getMessage(), $conflict);
        } catch (InvalidPurchaseOrder $refused) {
            throw DomainRule::refused($refused->field, $refused->getMessage(), $refused->reason ?? 'invalid_value', $refused->params, $refused);
        }

        return $this->view->of($receipt->getOrder());
    }

    /** @param array<string, mixed> $line */
    private static function text(array $line, string $key): ?string
    {
        $value = $line[$key] ?? null;

        return \is_string($value) ? $value : null;
    }
}
