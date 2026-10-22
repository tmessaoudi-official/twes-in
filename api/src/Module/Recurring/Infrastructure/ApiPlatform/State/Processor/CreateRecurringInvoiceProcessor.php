<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Recurring\Infrastructure\ApiPlatform\State\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Module\Invoices\Infrastructure\ApiPlatform\Security\InvoicePermission;
use App\Module\Recurring\Application\ManageRecurringInvoices;
use App\Module\Recurring\Domain\Exception\InvalidRecurringInvoice;
use App\Module\Recurring\Infrastructure\ApiPlatform\Resource\RecurringInvoiceResource;
use App\Shared\Domain\Exception\InvalidSchedule;
use App\Shared\Infrastructure\ApiPlatform\Output\DomainRule;
use App\Shared\Infrastructure\ApiPlatform\Payload\RequestedSchedule;
use App\Tenancy\Infrastructure\ApiPlatform\Payload\CompanyPath;
use App\Tenancy\Infrastructure\ApiPlatform\Security\CompanyGuard;
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
                RequestedSchedule::of($data),
                RequestedSchedule::day($data->startsOn),
                RequestedSchedule::dayOrNull($data->endsOn),
                $this->guard->account()->getId(),
            );
        } catch (InvalidSchedule|InvalidRecurringInvoice $refused) {
            throw DomainRule::refused($refused->field, $refused->getMessage(), $refused->reason, $refused->params, $refused);
        }

        return RecurringInvoiceResource::of($recurring, $this->manage->models($company, [$recurring])[$recurring->getModelInvoiceId()->toRfc4122()] ?? null);
    }
}
