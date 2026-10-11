<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Module\Invoices\Application\BulkAction;
use App\Module\Invoices\Application\CheckInvoicesInBulk;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyGuard;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyPath;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

/** @implements ProcessorInterface<InvoiceBulkCheckResource, InvoiceBulkCheckResource> */
final readonly class InvoiceBulkCheckProcessor implements ProcessorInterface
{
    public function __construct(private CheckInvoicesInBulk $check, private CompanyGuard $guard)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): InvoiceBulkCheckResource
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), InvoicePermission::READ);
        $ids = array_map(static fn (string $id): Uuid => Uuid::fromString($id), $data->ids);

        $day = null === $data->date || '' === $data->date ? null : new \DateTimeImmutable($data->date, new \DateTimeZone('UTC'));

        try {
            $rows = $this->check->check($company, BulkAction::from($data->action), $ids, day: $day);
        } catch (\InvalidArgumentException $refused) {
            throw new UnprocessableEntityHttpException($refused->getMessage(), $refused);
        }
        $answer = new InvoiceBulkCheckResource();
        foreach ($rows as $row) {
            $answer->rows[] = [
                'id' => $row->id->toRfc4122(),
                'number' => $row->invoice?->getNumber(),
                'customerName' => $row->invoice?->getCustomer()->getProfile()->name,
                'outcome' => $row->included() ? 'included' : 'skipped',
                'reason' => $row->skipped,
                'amount' => $row->amount,
            ];
            if ($row->included()) {
                ++$answer->included;
            } else {
                ++$answer->skipped;
            }
        }

        return $answer;
    }
}
