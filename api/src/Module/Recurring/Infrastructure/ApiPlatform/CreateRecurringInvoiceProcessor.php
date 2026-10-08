<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Recurring\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Module\Invoices\Infrastructure\ApiPlatform\InvoicePermission;
use App\Module\Recurring\Application\ManageRecurringInvoices;
use App\Module\Recurring\Domain\InvalidRecurringInvoice;
use App\Module\Recurring\Domain\RecurringFrequency;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyGuard;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyPath;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

/** @implements ProcessorInterface<RecurringInvoiceResource, RecurringInvoiceResource> */
final readonly class CreateRecurringInvoiceProcessor implements ProcessorInterface
{
    public function __construct(private ManageRecurringInvoices $manage, private CompanyGuard $guard)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): RecurringInvoiceResource
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), InvoicePermission::WRITE);

        try {
            $recurring = $this->manage->create(
                $company,
                Uuid::fromString($data->modelInvoiceId),
                RecurringFrequency::from($data->frequency),
                RequestedDay::of('startsOn', $data->startsOn),
                RequestedDay::orNull('endsOn', $data->endsOn),
                $this->guard->account()->getId(),
            );
        } catch (InvalidRecurringInvoice $refused) {
            throw new UnprocessableEntityHttpException(\sprintf('%s: %s', $refused->field, $refused->getMessage()), $refused);
        }

        return RecurringInvoiceResource::of($recurring, $this->manage->models($company, [$recurring])[$recurring->getModelInvoiceId()->toRfc4122()] ?? null);
    }
}
