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
use App\Module\Recurring\Application\RecurringInvoiceNotFound;
use App\Module\Recurring\Infrastructure\ApiPlatform\Resource\RecurringInvoiceResource;
use App\Shared\Domain\Exception\InvalidSchedule;
use App\Shared\Infrastructure\ApiPlatform\Output\DomainRule;
use App\Shared\Infrastructure\ApiPlatform\Payload\RequestedSchedule;
use App\Tenancy\Infrastructure\ApiPlatform\Payload\CompanyPath;
use App\Tenancy\Infrastructure\ApiPlatform\Security\CompanyGuard;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** @implements ProcessorInterface<RecurringInvoiceResource, RecurringInvoiceResource> */
final readonly class ReviseRecurringInvoiceProcessor implements ProcessorInterface
{
    public function __construct(private ManageRecurringInvoices $manage, private CompanyGuard $guard)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): RecurringInvoiceResource
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), InvoicePermission::WRITE);

        try {
            $recurring = $this->manage->revise(
                $company,
                CompanyPath::identifier($uriVariables, 'recurringInvoiceId'),
                RequestedSchedule::of($data),
                RequestedSchedule::dayOrNull($data->endsOn),
                $data->paused,
                $this->guard->account()->getId(),
                $data->version,
            );
        } catch (RecurringInvoiceNotFound $absent) {
            throw new NotFoundHttpException($absent->getMessage(), $absent);
        } catch (InvalidSchedule $refused) {
            throw DomainRule::refused($refused->field, $refused->getMessage(), $refused->reason, previous: $refused);
        }

        return RecurringInvoiceResource::of($recurring, $this->manage->models($company, [$recurring])[$recurring->getModelInvoiceId()->toRfc4122()] ?? null);
    }
}
