<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\DeliveryNotes\Application;

use App\Files\Application\Files;
use App\Files\Application\StoredFileCorrupted;
use App\Files\Application\StoredFileMissing;
use App\Fiscal\Application\DocumentNames;
use App\Fiscal\Domain\Calculation\Decimal;
use App\Fiscal\Domain\Calculation\QuantityTotals;
use App\Fiscal\Domain\Model\Unit;
use App\Module\Customers\Domain\ValueObject\CustomerSnapshot;
use App\Module\DeliveryNotes\Application\Invoices\DeliverableInvoices;
use App\Module\DeliveryNotes\Application\Invoices\InvoiceToDeliver;
use App\Module\DeliveryNotes\Application\Quotes\DeliverableQuotes;
use App\Module\DeliveryNotes\Application\Quotes\QuoteToDeliver;
use App\Module\DeliveryNotes\Domain\Model\DeliveryNote;
use App\Module\DeliveryNotes\Domain\Model\DeliveryNoteLine;
use App\Module\DeliveryNotes\Domain\Repository\DeliveryNoteRepository;
use App\Module\DeliveryNotes\Domain\ValueObject\DeliveryNoteStatus;
use App\Settings\Application\ReadSetting;
use App\Shared\Application\PdfRenderer;
use App\Shared\Application\PdfRenderingFailed;
use App\Shared\Application\Transactions;
use App\Tenancy\Domain\Model\Company;
use App\Tenancy\Domain\ValueObject\SellerSnapshot;
use Symfony\Component\Uid\Uuid;

/**
 * A delivery note's PDF. A validated or delivered note is served as it
 * was stored when it was issued, rendered and stored on its first download when that failed. A draft, and a cancelled
 * note, are rendered on request across a watermark and never stored. The customer's settings choose the language,
 * whether prices show and the printed notes.
 */
final readonly class PrintDeliveryNote
{
    public function __construct(
        private DeliveryNoteRepository $notes,
        private DeliveryNoteTotals $totals,
        private DeliveryNoteTemplate $template,
        private PdfRenderer $renderer,
        private Files $files,
        private ReadSetting $settings,
        private DocumentNames $names,
        private Transactions $transactions,
        private DeliverableQuotes $quotes,
        private DeliverableInvoices $invoices,
    ) {
    }

    /**
     * @throws DeliveryNoteNotFound
     * @throws PdfRenderingFailed
     * @throws StoredFileMissing
     * @throws StoredFileCorrupted
     */
    public function pdf(Company $company, Uuid $id): PrintedDeliveryNote
    {
        $note = $this->notes->ofIdInCompany($id, $company->getId()) ?? throw new DeliveryNoteNotFound();

        return new PrintedDeliveryNote(self::fileName($note), match ($note->getStatus()) {
            DeliveryNoteStatus::Draft => $this->render($note, DeliveryNotePage::DRAFT),
            DeliveryNoteStatus::Cancelled => $this->render($note, DeliveryNotePage::CANCELLED),
            DeliveryNoteStatus::Validated, DeliveryNoteStatus::Delivered, DeliveryNoteStatus::Invoiced => $this->issued($note),
        });
    }

    /**
     * A delivered note printed again as its delivery left it: who received the goods and when, under « Copie — livrée
     * le … ». Rendered on request and never stored: the original stays the one file validation wrote.
     *
     * @throws DeliveryNoteNotFound
     * @throws NotDeliveredYet
     * @throws PdfRenderingFailed
     */
    public function deliveredCopy(Company $company, Uuid $id): PrintedDeliveryNote
    {
        $note = $this->notes->ofIdInCompany($id, $company->getId()) ?? throw new DeliveryNoteNotFound();
        if (null === $note->getDeliveredAt()) {
            throw new NotDeliveredYet();
        }

        return new PrintedDeliveryNote(self::fileName($note, 'delivered'), $this->render($note, DeliveryNotePage::COPY));
    }

    /**
     * Stores a numbered note's PDF as it is issued, unless it already keeps one.
     *
     * @throws DeliveryNoteNotFound
     * @throws PdfRenderingFailed
     */
    public function storeIssued(Uuid $companyId, Uuid $id): void
    {
        $note = $this->notes->ofIdInCompany($id, $companyId) ?? throw new DeliveryNoteNotFound();
        if (null !== $note->getNumber() && null === $note->getPdfFile()) {
            $this->issued($note);
        }
    }

    private function issued(DeliveryNote $note): string
    {
        $stored = $note->getPdfFile();
        if (null !== $stored) {
            return $this->files->contents($stored);
        }

        // Rendered before the row is held, which is not kept waiting on the renderer: what a numbered note says no
        // longer changes. Then held, as delivering it may have saved it since it was read, and read again as it stands.
        $contents = $this->render($note, null);

        return $this->transactions->run(function () use ($note, $contents): string {
            $held = $this->notes->lockedOfIdsInCompany([$note->getId()], $note->getCompany()->getId())[0] ?? throw new DeliveryNoteNotFound();
            $stored = $held->getPdfFile();
            if (null !== $stored) {
                return $this->files->contents($stored);
            }
            $held->attachPdf($this->files->store($held->getCompany(), self::fileName($held), 'application/pdf', $contents, null));
            $this->notes->save($held);

            return $contents;
        });
    }

    /** @param DeliveryNotePage::DRAFT|DeliveryNotePage::CANCELLED|DeliveryNotePage::COPY|null $watermark */
    private function render(DeliveryNote $note, ?string $watermark): string
    {
        // A draft and a delivered copy say who is named now; what was issued says who was named then.
        $receiver = DeliveryNotePage::DRAFT === $watermark || DeliveryNotePage::COPY === $watermark ? $note->getHeader()->receivedBy?->name : $note->getPrintedReceiverName();
        $printing = $note->getPrintSettings() ?? DeliveryNotePrinting::today($this->settings, $note);
        // A note has no copy: what it prints once numbered is its original, which names things as it was validated with.
        $names = $note->getPrintedNames() ?? $this->names->now($note->getCompany(), $printing->language, array_map(static fn (DeliveryNoteLine $line): Unit => $line->getUnit(), $note->getLines()));
        $quote = $this->quoteOf($note);
        $invoice = $this->invoiceOf($note);

        return $this->renderer->render($this->template->html(new DeliveryNotePage(
            $note,
            $this->totals->of($note),
            $note->getCustomerSnapshot() ?? CustomerSnapshot::of($note->getCustomer()),
            $note->getSellerSnapshot() ?? SellerSnapshot::of($note->getCompany(), $note->getEstablishment()),
            $watermark,
            // A note carries no discount: priced, a note of a discounted quote or invoice would print more than the
            // document it names, which the customer signs.
            $printing->showPrices && !self::discounted($note, $quote, $invoice),
            $printing->language,
            $printing->print->printedNotes,
            $printing->receptionBlock,
            $printing->print->dateFormat,
            $printing->print->numberFormat,
            $printing->print->design,
            QuantityTotals::ofUnits(array_map(static fn (DeliveryNoteLine $line): array => [$line->getUnit(), $line->getQuantity()], $note->getLines()), $names),
            $printing->print->exemptionReference,
            $names,
            $quote?->number,
            $invoice?->number,
            $receiver,
            DeliveryNotePage::COPY === $watermark,
        )));
    }

    /** The issued invoice whose goods the note hands over; its number is printed as its reference: an issued invoice keeps its number. */
    private function invoiceOf(DeliveryNote $note): ?InvoiceToDeliver
    {
        $sources = array_values(array_filter(array_map(static fn (DeliveryNoteLine $line): ?Uuid => $line->getSourceInvoiceLineId(), $note->getLines())));

        return [] === $sources ? null : ($this->invoices->ofLines($note->getCompany(), $sources, false)[0] ?? null);
    }

    /** The accepted quote the note delivers; its number is printed as its reference: a sent quote keeps its number. */
    private function quoteOf(DeliveryNote $note): ?QuoteToDeliver
    {
        $sources = array_values(array_filter(array_map(static fn (DeliveryNoteLine $line): ?Uuid => $line->getSourceQuoteLineId(), $note->getLines())));

        return [] === $sources ? null : ($this->quotes->ofLines($note->getCompany(), $sources, false)[0] ?? null);
    }

    /** Whether a line the note delivers was discounted on its quote or invoice, on the line or on the whole. */
    private static function discounted(DeliveryNote $note, ?QuoteToDeliver $quote, ?InvoiceToDeliver $invoice): bool
    {
        $quoteLines = [];
        foreach ($quote->lines ?? [] as $line) {
            $quoteLines[$line->id->toRfc4122()] = self::lowers($line->discountRate) || self::lowers($line->discountAmount);
        }
        $invoiceLines = [];
        foreach ($invoice->lines ?? [] as $line) {
            $invoiceLines[$line->id->toRfc4122()] = $line->discounted;
        }
        foreach ($note->getLines() as $line) {
            $quoteLine = $line->getSourceQuoteLineId()?->toRfc4122();
            $invoiceLine = $line->getSourceInvoiceLineId()?->toRfc4122();
            if ((null !== $quoteLine && (true === $quote?->documentDiscount || ($quoteLines[$quoteLine] ?? false))) || (null !== $invoiceLine && ($invoiceLines[$invoiceLine] ?? false))) {
                return true;
            }
        }

        return false;
    }

    private static function lowers(?string $discount): bool
    {
        return null !== $discount && 0 !== Decimal::of($discount)->compare(Decimal::zero());
    }

    private static function fileName(DeliveryNote $note, ?string $copy = null): string
    {
        $number = $note->getNumber();
        $suffix = null === $copy ? '' : '-'.$copy;

        // A numbering format may carry slashes, which a file name may not.
        return null === $number ? \sprintf('delivery-note-%s%s.pdf', $note->getId()->toRfc4122(), $suffix) : str_replace('/', '-', $number).$suffix.'.pdf';
    }
}
