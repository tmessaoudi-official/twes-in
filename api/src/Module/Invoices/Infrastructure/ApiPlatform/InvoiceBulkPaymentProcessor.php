<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Module\Invoices\Application\BulkChanged;
use App\Module\Invoices\Application\CashInvoicesInBulk;
use App\Module\Invoices\Domain\InvalidInvoice;
use App\Module\Invoices\Domain\InvoiceTransitionRefused;
use App\Shared\Domain\PaymentMethod;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyGuard;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyPath;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

/** @implements ProcessorInterface<InvoiceBulkPaymentResource, InvoiceBulkPaymentResource> */
final readonly class InvoiceBulkPaymentProcessor implements ProcessorInterface
{
    public function __construct(private CashInvoicesInBulk $cash, private CompanyGuard $guard)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): InvoiceBulkPaymentResource
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), InvoicePermission::PAYMENT_WRITE);
        $rows = array_map(static fn (array $row): array => ['id' => Uuid::fromString($row['id']), 'amount' => $row['amount']], $data->rows);

        try {
            $total = $this->cash->cash($company, $rows, PaymentMethod::from($data->method), new \DateTimeImmutable($data->date, new \DateTimeZone('UTC')), $data->reference, $this->guard->account()->getId());
        } catch (BulkChanged|InvoiceTransitionRefused $changed) {
            // A refusal one invoice meets after the check passed is the same story: it changed between the two reads.
            throw new ConflictHttpException('bulk_changed: '.$changed->getMessage(), $changed);
        } catch (\InvalidArgumentException $refused) {
            throw new UnprocessableEntityHttpException($refused->getMessage(), $refused);
        } catch (InvalidInvoice $refused) {
            throw new UnprocessableEntityHttpException(\sprintf('%s: %s', $refused->field, $refused->getMessage()), $refused);
        }

        $answer = new InvoiceBulkPaymentResource();
        $answer->recorded = \count($rows);
        $answer->total = $total;

        return $answer;
    }
}
