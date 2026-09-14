<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\DeliveryNotes\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Module\DeliveryNotes\Application\InvoiceDeliveryNotes;
use App\Module\DeliveryNotes\Domain\DeliveryNoteTransitionRefused;
use App\Module\DeliveryNotes\Domain\InvalidDeliveryNote;
use App\Module\Invoices\Application\InvoiceTotals;
use App\Module\Invoices\Domain\InvalidInvoice;
use App\Module\Invoices\Infrastructure\ApiPlatform\InvoicePermission;
use App\Module\Invoices\Infrastructure\ApiPlatform\InvoiceResource;
use App\Module\Invoices\Infrastructure\Module\InvoicesModule;
use App\ModuleRegistry\Application\ModuleStates;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyGuard;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyPath;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * Drafts an invoice from delivery notes: a note that is not validated or delivered, or already on an invoice that is not
 * cancelled, answers 409; notes that make no one invoice answer 422 on `deliveryNoteIds`.
 *
 * @implements ProcessorInterface<InvoiceFromDeliveryNotesResource, InvoiceResource>
 */
final readonly class DraftInvoiceFromDeliveryNotesProcessor implements ProcessorInterface
{
    public function __construct(private InvoiceDeliveryNotes $invoicing, private InvoiceTotals $totals, private CompanyGuard $guard, private ModuleStates $modules)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): InvoiceResource
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), InvoicePermission::WRITE);
        // The module guard answered for delivery notes, whose resource this is; the invoice it drafts needs invoices on too.
        if (!$this->modules->isEnabled($company->getId(), InvoicesModule::KEY)) {
            throw new NotFoundHttpException('No such company.');
        }

        try {
            $invoice = $this->invoicing->draftInvoice($company, array_map(static fn (string $id): Uuid => Uuid::fromString($id), $data->deliveryNoteIds), $this->guard->account()->getId());
        } catch (InvalidDeliveryNote|InvalidInvoice $refused) {
            throw new UnprocessableEntityHttpException(\sprintf('%s: %s', $refused->field, $refused->getMessage()), $refused);
        } catch (DeliveryNoteTransitionRefused $conflict) {
            throw new ConflictHttpException($conflict->getMessage(), $conflict);
        }

        return InvoiceResource::of($invoice, $this->totals->figures($invoice));
    }
}
