<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\DeliveryNotes\Infrastructure\ApiPlatform\State\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Module\DeliveryNotes\Application\Invoicing\DeliveryNoteInvoicingSettings;
use App\Module\DeliveryNotes\Application\Invoicing\InvoiceDeliveryNotes;
use App\Module\DeliveryNotes\Domain\Exception\DeliveryNoteTransitionRefused;
use App\Module\DeliveryNotes\Domain\Exception\InvalidDeliveryNote;
use App\Module\DeliveryNotes\Infrastructure\ApiPlatform\Resource\InvoicesFromDeliveryNotesResource;
use App\Module\Invoices\Domain\Exception\InvalidInvoice;
use App\Module\Invoices\Infrastructure\ApiPlatform\Security\InvoicePermission;
use App\Module\Invoices\Infrastructure\Module\InvoicesModule;
use App\ModuleRegistry\Application\ModuleStates;
use App\Settings\Application\ReadSetting;
use App\Shared\Infrastructure\ApiPlatform\Output\DomainRule;
use App\Tenancy\Infrastructure\ApiPlatform\Payload\CompanyPath;
use App\Tenancy\Infrastructure\ApiPlatform\Security\CompanyGuard;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * Drafts the invoices of the delivery notes chosen, one per customer and establishment where the company says so.
 *
 * @implements ProcessorInterface<InvoicesFromDeliveryNotesResource, InvoicesFromDeliveryNotesResource>
 */
final readonly class DraftInvoicesFromDeliveryNotesProcessor implements ProcessorInterface
{
    public function __construct(private InvoiceDeliveryNotes $invoicing, private CompanyGuard $guard, private ModuleStates $modules, private ReadSetting $settings)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): InvoicesFromDeliveryNotesResource
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), InvoicePermission::WRITE);
        // The module guard answered for delivery notes, whose resource this is; the invoices it drafts need invoices on too.
        if (!$this->modules->isEnabled($company->getId(), InvoicesModule::KEY)) {
            throw new NotFoundHttpException('No such company.');
        }

        try {
            $drafts = $this->invoicing->draftInvoices($company, array_map(static fn (string $id): Uuid => Uuid::fromString($id), $data->deliveryNoteIds), $this->guard->account()->getId(), DeliveryNoteInvoicingSettings::invoicesEachParty($this->settings, $company));
        } catch (InvalidDeliveryNote|InvalidInvoice $refused) {
            throw DomainRule::refused($refused->field, $refused->getMessage(), $refused->reason ?? 'invalid_value', $refused->params, $refused);
        } catch (DeliveryNoteTransitionRefused $conflict) {
            throw DomainRule::conflict(DomainRule::WHOLE_REQUEST, $conflict->getMessage(), $conflict->reason, $conflict->params, $conflict);
        }
        $answer = new InvoicesFromDeliveryNotesResource();
        $answer->invoiceIds = array_map(static fn (Uuid $id): string => $id->toRfc4122(), $drafts);

        return $answer;
    }
}
