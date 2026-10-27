<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Application\Receiver;

use App\Audit\Application\AuditEntry;
use App\Audit\Application\AuditTrail;
use App\Audit\Application\DeclaresAuditActions;
use App\Audit\Domain\ValueObject\AuditScope;
use App\Audit\Domain\ValueObject\Recorded;
use App\Module\Invoices\Application\InvoiceNotFound;
use App\Module\Invoices\Application\ManageInvoices;
use App\Module\Invoices\Domain\Exception\InvalidInvoice;
use App\Module\Invoices\Domain\Exception\InvoiceTransitionRefused;
use App\Module\Invoices\Domain\Model\Invoice;
use App\Module\Invoices\Domain\Repository\InvoiceRepository;
use App\Settings\Application\ReadSetting;
use App\Shared\Application\Receivers\ChooseReceiver;
use App\Shared\Application\Receivers\ReceiverChoice;
use App\Shared\Application\Transactions;
use App\Shared\Domain\ValueObject\Receiver;
use App\Shared\Domain\ValueObject\Refusal;
use App\Tenancy\Domain\Model\Company;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * « Corriger qui a reçu… » on an issued invoice that handed goods over itself (docs/SPEC.md § 7, « Reçu par »
 * correctable after delivery, audited): what the invoice states fiscally does not move, and its print as issued keeps
 * the name it was issued with.
 */
final readonly class CorrectInvoiceReceiver implements DeclaresAuditActions
{
    public const string RECEIVER_CORRECTED = 'invoice.receiver_corrected';

    public function __construct(
        private InvoiceRepository $invoices,
        private Transactions $transactions,
        private ChooseReceiver $receivers,
        private ReadSetting $settings,
        private AuditTrail $audit,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Who received an issued invoice's goods, put right, or nobody when someone was named by mistake: sent back as read,
     * the receiver is kept as it was copied; a contact's name is copied as it reads now; a name typed is added to the
     * customer's contacts when asked, in this transaction. The customer's rule is read as it stands: off refuses
     * naming someone, required refuses naming nobody. Recorded by the field it changed, never the name.
     *
     * @throws InvoiceNotFound
     * @throws InvoiceTransitionRefused on a draft or a cancelled draft
     * @throws InvalidInvoice           on `receivedBy`
     */
    public function correct(Company $company, Uuid $id, ?ReceiverChoice $receivedBy, ?Uuid $actorUserId): Invoice
    {
        return $this->transactions->run(function () use ($company, $id, $receivedBy, $actorUserId): Invoice {
            $invoice = $this->invoices->lockedOfIdInCompany($id, $company->getId()) ?? throw new InvoiceNotFound();
            $receiver = $this->receiver($company, $invoice, $receivedBy, $actorUserId);
            // A document handing no goods over names nobody, whatever its customer's rule: the domain says so for it.
            if ([] !== $invoice->goodsOfItsOwn()) {
                $refused = InvoiceReceiverSettings::ruleFor($this->settings, $invoice->getCustomer())->refusal($receiver);
                if (null !== $refused) {
                    throw new InvalidInvoice('receivedBy', $refused->message, $refused->reason, $refused->params);
                }
            }
            if ($invoice->correctReceiver($receiver, $this->clock->now())) {
                $this->invoices->save($invoice);
                $this->audit->record(new AuditEntry(ManageInvoices::ENTITY_TYPE, $invoice->getId(), self::RECEIVER_CORRECTED, $actorUserId, ['fields' => ['receivedBy']], $company->getId()));
            }

            return $invoice;
        });
    }

    /** @throws InvalidInvoice */
    private function receiver(Company $company, Invoice $invoice, ?ReceiverChoice $receivedBy, ?Uuid $actorUserId): ?Receiver
    {
        $current = $invoice->getHeader()->receivedBy;
        if (null !== $receivedBy && $receivedBy->names($current)) {
            return $current;
        }
        $receiver = $this->receivers->receiver($company, $invoice->getCustomer()->getId(), $receivedBy, $actorUserId);
        if ($receiver instanceof Refusal) {
            throw new InvalidInvoice('receivedBy', $receiver->message, $receiver->reason, $receiver->params);
        }

        return $receiver;
    }

    public function auditActions(): array
    {
        return [
            self::RECEIVER_CORRECTED => new Recorded(ManageInvoices::ENTITY_TYPE, AuditScope::Company),
        ];
    }
}
