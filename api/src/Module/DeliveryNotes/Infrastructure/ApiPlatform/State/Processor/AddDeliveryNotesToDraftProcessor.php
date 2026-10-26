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
use App\Module\DeliveryNotes\Domain\Exception\DeliveryNoteTransitionRefused;
use App\Module\DeliveryNotes\Domain\Exception\InvalidDeliveryNote;
use App\Module\DeliveryNotes\Infrastructure\ApiPlatform\Output\InvoiceAnswer;
use App\Module\DeliveryNotes\Infrastructure\ApiPlatform\Resource\InvoiceFromDeliveryNotesResource;
use App\Module\Invoices\Application\InvoiceNotFound;
use App\Module\Invoices\Domain\Exception\InvalidInvoice;
use App\Module\Invoices\Domain\Exception\InvoiceNotDraft;
use App\Module\Invoices\Infrastructure\ApiPlatform\Security\InvoicePermission;
use App\Module\Invoices\Infrastructure\Module\InvoicesModule;
use App\ModuleRegistry\Application\ModuleStates;
use App\Shared\Infrastructure\ApiPlatform\Output\DomainRule;
use App\Tenancy\Infrastructure\ApiPlatform\Payload\CompanyPath;
use App\Tenancy\Infrastructure\ApiPlatform\Security\CompanyGuard;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * Adds delivery notes to an existing draft invoice: an invoice that is not the company's answers 404, one that is not a
 * draft any more, or a note that is not validated or delivered or has nothing left, 409; notes that are not of the draft's
 * customer and establishment, or quantities asking for more than is left, 422.
 *
 * @implements ProcessorInterface<InvoiceFromDeliveryNotesResource, object>
 */
final readonly class AddDeliveryNotesToDraftProcessor implements ProcessorInterface
{
    public function __construct(private InvoiceDeliveryNotes $invoicing, private InvoiceAnswer $answer, private CompanyGuard $guard, private ModuleStates $modules)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): object
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), InvoicePermission::WRITE);
        if (!$this->modules->isEnabled($company->getId(), InvoicesModule::KEY)) {
            throw new NotFoundHttpException('No such company.');
        }

        try {
            $invoiceId = CompanyPath::identifier($uriVariables, 'invoiceId');
            $this->invoicing->addToDraft($company, $invoiceId, array_map(static fn (string $id): Uuid => Uuid::fromString($id), $data->deliveryNoteIds), $this->guard->account()->getId(), $data->quantities);
        } catch (InvoiceNotFound $absent) {
            throw new NotFoundHttpException('No such invoice.', $absent);
        } catch (InvalidDeliveryNote|InvalidInvoice $refused) {
            throw DomainRule::refused($refused->field, $refused->getMessage(), $refused->reason ?? 'invalid_value', $refused->params, $refused);
        } catch (DeliveryNoteTransitionRefused|InvoiceNotDraft $conflict) {
            throw new ConflictHttpException($conflict->getMessage(), $conflict);
        }

        return $this->answer->of($company, $invoiceId);
    }
}
