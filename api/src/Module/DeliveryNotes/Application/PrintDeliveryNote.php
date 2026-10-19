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
use App\Fiscal\Domain\Calculation\QuantityTotals;
use App\Module\Customers\Domain\ValueObject\CustomerSnapshot;
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
        private Transactions $transactions,
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

    /** @param DeliveryNotePage::DRAFT|DeliveryNotePage::CANCELLED|null $watermark */
    private function render(DeliveryNote $note, ?string $watermark): string
    {
        $printing = $note->getPrintSettings() ?? DeliveryNotePrinting::today($this->settings, $note);

        return $this->renderer->render($this->template->html(new DeliveryNotePage(
            $note,
            $this->totals->of($note),
            $note->getCustomerSnapshot() ?? CustomerSnapshot::of($note->getCustomer()),
            $note->getSellerSnapshot() ?? SellerSnapshot::of($note->getCompany(), $note->getEstablishment()),
            $watermark,
            $printing->showPrices,
            $printing->language,
            $printing->print->printedNotes,
            $printing->receptionBlock,
            $printing->print->dateFormat,
            $printing->print->numberFormat,
            $printing->print->design,
            QuantityTotals::ofUnits(array_map(static fn (DeliveryNoteLine $line): array => [$line->getUnit(), $line->getQuantity()], $note->getLines()), $printing->language),
            $printing->print->exemptionReference,
        )));
    }

    private static function fileName(DeliveryNote $note): string
    {
        $number = $note->getNumber();

        // A numbering format may carry slashes, which a file name may not.
        return null === $number ? \sprintf('delivery-note-%s.pdf', $note->getId()->toRfc4122()) : str_replace('/', '-', $number).'.pdf';
    }
}
