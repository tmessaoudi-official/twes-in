<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\DeliveryNotes\Infrastructure\ApiPlatform\State\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Module\DeliveryNotes\Application\InvoiceDeliveryNotes;
use App\Module\DeliveryNotes\Infrastructure\ApiPlatform\Resource\InvoicingCheckResource;
use App\Module\Invoices\Application\InvoiceNotFound;
use App\Module\Invoices\Domain\Exception\InvoiceNotDraft;
use App\Module\Invoices\Infrastructure\ApiPlatform\Security\InvoicePermission;
use App\Module\Invoices\Infrastructure\Module\InvoicesModule;
use App\ModuleRegistry\Application\ModuleStates;
use App\Tenancy\Infrastructure\ApiPlatform\Payload\CompanyPath;
use App\Tenancy\Infrastructure\ApiPlatform\Security\CompanyGuard;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

/** @implements ProcessorInterface<InvoicingCheckResource, InvoicingCheckResource> */
final readonly class InvoicingCheckProcessor implements ProcessorInterface
{
    public function __construct(private InvoiceDeliveryNotes $invoicing, private CompanyGuard $guard, private ModuleStates $modules)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): InvoicingCheckResource
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), InvoicePermission::WRITE);
        // The module guard answered for delivery notes, whose resource this is; the invoice it checks for needs invoices on too.
        if (!$this->modules->isEnabled($company->getId(), InvoicesModule::KEY)) {
            throw new NotFoundHttpException('No such company.');
        }

        try {
            $rows = $this->invoicing->check(
                $company,
                array_map(static fn (string $id): Uuid => Uuid::fromString($id), $data->deliveryNoteIds),
                null === $data->invoiceId ? null : Uuid::fromString($data->invoiceId),
            );
        } catch (\InvalidArgumentException $refused) {
            throw new UnprocessableEntityHttpException($refused->getMessage(), $refused);
        } catch (InvoiceNotFound $absent) {
            throw new NotFoundHttpException('No such invoice.', $absent);
        } catch (InvoiceNotDraft $conflict) {
            throw new ConflictHttpException($conflict->getMessage(), $conflict);
        }
        $answer = new InvoicingCheckResource();
        foreach ($rows as $row) {
            $answer->rows[] = [
                'id' => $row->id->toRfc4122(),
                'number' => $row->note?->getNumber(),
                'customerName' => $row->note?->getCustomer()->standingFor()->getProfile()->name,
                'outcome' => $row->included() ? 'included' : 'skipped',
                'reason' => $row->skipped,
            ];
            if ($row->included()) {
                ++$answer->included;
                $answer->customerId ??= $row->note?->getCustomer()->standingFor()->getId()->toRfc4122();
                $answer->establishmentId ??= $row->note?->getEstablishment()->getId()->toRfc4122();
            } else {
                ++$answer->skipped;
            }
        }

        return $answer;
    }
}
