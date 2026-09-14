<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\DeliveryNotes\Application;

use App\Audit\Application\AuditEntry;
use App\Audit\Application\AuditTrail;
use App\Module\DeliveryNotes\Domain\DeliveryNote;
use App\Module\DeliveryNotes\Domain\DeliveryNoteLine;
use App\Module\DeliveryNotes\Domain\DeliveryNoteNotDraft;
use App\Module\DeliveryNotes\Domain\DeliveryNoteRepository;
use App\Module\DeliveryNotes\Domain\DeliveryNoteTransitionRefused;
use App\Module\DeliveryNotes\Domain\InvalidDeliveryNote;
use App\Module\Invoices\Domain\InvoiceRepository;
use App\Shared\Application\DomainEvents;
use App\Shared\Application\Transactions;
use App\Tenancy\Application\Numbering\AllocateNumber;
use App\Tenancy\Application\Numbering\NoNumberingSeries;
use App\Tenancy\Domain\Company;
use App\Tenancy\Domain\InvalidNumbering;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * A delivery note past its draft: validated (numbered, in the transaction that stores it), delivered, or cancelled
 * unless an invoice that is not cancelled carries it. What a note records happening is published once it is stored,
 * and every move is audited.
 */
final readonly class DeliveryNoteWorkflow
{
    public const string DOCUMENT_TYPE = 'delivery_note';
    public const string VALIDATED = 'delivery_note.validated';
    public const string DELIVERED = 'delivery_note.delivered';
    public const string CANCELLED = 'delivery_note.cancelled';

    public function __construct(
        private DeliveryNoteRepository $notes,
        private InvoiceRepository $invoices,
        private AllocateNumber $numbers,
        private Transactions $transactions,
        private DeliveryNoteTotals $totals,
        private DomainEvents $events,
        private AuditTrail $audit,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws DeliveryNoteNotFound
     * @throws DeliveryNoteNotDraft
     * @throws InvalidDeliveryNote
     * @throws NoNumberingSeries       when the note's establishment numbers no delivery note
     * @throws InvalidNumbering        when the company's day comes before the month of the series' last number
     * @throws DeliveryNoteNumberTaken when another establishment of the company already gave the number
     */
    public function validate(Company $company, Uuid $id, ?Uuid $actorUserId): DeliveryNote
    {
        $note = $this->transactions->run(function () use ($company, $id, $actorUserId): DeliveryNote {
            $note = $this->get($company, $id);
            $allocated = $this->numbers->allocate($company, $note->getEstablishment(), self::DOCUMENT_TYPE);
            if ($this->notes->numberTaken($company->getId(), $allocated->number)) {
                throw new DeliveryNoteNumberTaken(\sprintf('The number %s is already on another delivery note of this company: give the delivery note series of establishment %s a format with {EST}, so that establishments number apart.', $allocated->number, $note->getEstablishment()->getCode()));
            }
            $note->validate($allocated->number, $allocated->issueDate, $this->clock->now());
            $this->totals->checked($note);
            $this->notes->save($note);
            $this->record($company, $note, self::VALIDATED, ['number' => $allocated->number], $actorUserId);

            return $note;
        });
        $this->events->publish(...$note->releaseEvents());

        return $note;
    }

    /**
     * @param \DateTimeImmutable|null $deliveredOn the day the goods arrived; left out, the company's today
     *
     * @throws DeliveryNoteNotFound
     * @throws DeliveryNoteTransitionRefused
     * @throws InvalidDeliveryNote
     */
    public function deliver(Company $company, Uuid $id, ?\DateTimeImmutable $deliveredOn, ?Uuid $actorUserId): DeliveryNote
    {
        $note = $this->get($company, $id);
        $now = $this->clock->now();
        $today = new \DateTimeImmutable($now->setTimezone(new \DateTimeZone($company->getTimezone()))->format('Y-m-d'), new \DateTimeZone('UTC'));
        $note->deliver($deliveredOn ?? $today, $today, $now);
        $this->notes->save($note);
        $this->record($company, $note, self::DELIVERED, ['deliveryDate' => $note->getHeader()->deliveryDate?->format('Y-m-d')], $actorUserId);
        $this->events->publish(...$note->releaseEvents());

        return $note;
    }

    /**
     * @throws DeliveryNoteNotFound
     * @throws DeliveryNoteTransitionRefused
     */
    public function cancel(Company $company, Uuid $id, ?Uuid $actorUserId): DeliveryNote
    {
        $note = $this->get($company, $id);
        $lines = array_map(static fn (DeliveryNoteLine $line): Uuid => $line->getId(), $note->getLines());
        $invoice = [] === $lines ? null : ($this->invoices->carryingDeliveryNoteLines($company->getId(), $lines)[0] ?? null);
        if (null !== $invoice) {
            throw new DeliveryNoteTransitionRefused(\sprintf('The delivery note %s is on the invoice %s: cancel that draft first.', $note->getNumber() ?? $note->getId()->toRfc4122(), $invoice->getNumber() ?? $invoice->getId()->toRfc4122()));
        }
        $note->cancel($this->clock->now());
        $this->notes->save($note);
        $this->record($company, $note, self::CANCELLED, [], $actorUserId);
        $this->events->publish(...$note->releaseEvents());

        return $note;
    }

    private function get(Company $company, Uuid $id): DeliveryNote
    {
        return $this->notes->ofIdInCompany($id, $company->getId()) ?? throw new DeliveryNoteNotFound();
    }

    /** @param array<string, mixed> $changes */
    private function record(Company $company, DeliveryNote $note, string $action, array $changes, ?Uuid $actorUserId): void
    {
        $this->audit->record(new AuditEntry(ManageDeliveryNotes::ENTITY_TYPE, $note->getId(), $action, $actorUserId, $changes, $company->getId()));
    }
}
