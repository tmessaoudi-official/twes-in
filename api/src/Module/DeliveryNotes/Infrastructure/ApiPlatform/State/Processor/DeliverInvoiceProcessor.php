<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\DeliveryNotes\Infrastructure\ApiPlatform\State\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Module\DeliveryNotes\Application\Invoices\DeliveredInvoiceNotFound;
use App\Module\DeliveryNotes\Application\Invoices\DeliverInvoices;
use App\Module\DeliveryNotes\Domain\Exception\DeliveryNoteTransitionRefused;
use App\Module\DeliveryNotes\Domain\Exception\InvalidDeliveryNote;
use App\Module\DeliveryNotes\Infrastructure\ApiPlatform\Output\DeliveryNoteView;
use App\Module\DeliveryNotes\Infrastructure\ApiPlatform\Resource\DeliveryNoteFromInvoiceResource;
use App\Module\DeliveryNotes\Infrastructure\ApiPlatform\Resource\DeliveryNoteResource;
use App\Module\DeliveryNotes\Infrastructure\ApiPlatform\Security\DeliveryNotePermission;
use App\Module\Invoices\Infrastructure\ApiPlatform\Security\InvoicePermission;
use App\Module\Invoices\Infrastructure\Module\InvoicesModule;
use App\ModuleRegistry\Application\ModuleStates;
use App\Tenancy\Infrastructure\ApiPlatform\Payload\CompanyPath;
use App\Tenancy\Infrastructure\ApiPlatform\Security\CompanyGuard;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/** @implements ProcessorInterface<DeliveryNoteFromInvoiceResource, DeliveryNoteResource> */
final readonly class DeliverInvoiceProcessor implements ProcessorInterface
{
    public function __construct(private DeliverInvoices $delivering, private DeliveryNoteView $view, private CompanyGuard $guard, private ModuleStates $modules)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): DeliveryNoteResource
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), DeliveryNotePermission::WRITE);
        // The module guard answered for delivery notes, whose resource this is; the invoice it delivers needs invoices on too.
        if (!$this->modules->isEnabled($company->getId(), InvoicesModule::KEY)) {
            throw new NotFoundHttpException('No such company.');
        }
        if (!$this->guard->may($company, InvoicePermission::READ)) {
            throw new AccessDeniedHttpException('Delivering an invoice reads it: invoice.read is needed as well.');
        }

        try {
            $note = $this->delivering->draftFrom($company, CompanyPath::identifier($uriVariables, 'invoiceId'), $data->quantities, $this->guard->account()->getId());
        } catch (DeliveredInvoiceNotFound $absent) {
            throw new NotFoundHttpException('No such invoice.', $absent);
        } catch (InvalidDeliveryNote $refused) {
            throw new UnprocessableEntityHttpException(\sprintf('%s: %s', $refused->field, $refused->getMessage()), $refused);
        } catch (DeliveryNoteTransitionRefused $conflict) {
            throw new ConflictHttpException($conflict->getMessage(), $conflict);
        }

        return $this->view->of($note);
    }
}
