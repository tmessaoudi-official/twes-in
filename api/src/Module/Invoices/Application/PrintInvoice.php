<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Application;

use App\Files\Application\Files;
use App\Files\Application\StoredFileCorrupted;
use App\Files\Application\StoredFileMissing;
use App\Module\Customers\Domain\CustomerSnapshot;
use App\Module\Invoices\Domain\Invoice;
use App\Module\Invoices\Domain\InvoiceRepository;
use App\Module\Invoices\Domain\InvoiceStatus;
use App\Settings\Application\DocumentFormats;
use App\Settings\Application\ReadSetting;
use App\Settings\Application\SettingContext;
use App\Shared\Application\PdfRenderer;
use App\Shared\Application\PdfRenderingFailed;
use App\Tenancy\Domain\Company;
use Symfony\Component\Uid\Uuid;

/**
 * An invoice's or a credit note's PDF (docs/SPEC.md § 7, 2026-09-14: stored at issue, as delivery notes are). An issued
 * document is served as it was stored when it was issued, rendered and stored on its first download when that failed,
 * and prints the language, mentions and texts issuing kept. A draft, and a cancelled draft, are rendered on request
 * across a watermark with what they would be issued with today, and never stored.
 */
final readonly class PrintInvoice
{
    public function __construct(
        private InvoiceRepository $invoices,
        private InvoiceTotals $totals,
        private InvoiceMentions $mentions,
        private InvoiceTemplate $template,
        private PdfRenderer $renderer,
        private Files $files,
        private ReadSetting $settings,
    ) {
    }

    /**
     * @throws InvoiceNotFound
     * @throws PdfRenderingFailed
     * @throws StoredFileMissing
     * @throws StoredFileCorrupted
     */
    public function pdf(Company $company, Uuid $id): PrintedInvoice
    {
        $invoice = $this->invoices->ofIdInCompany($id, $company->getId()) ?? throw new InvoiceNotFound();

        return new PrintedInvoice(self::fileName($invoice), match ($invoice->getStatus()) {
            InvoiceStatus::Draft => $this->render($invoice, InvoicePage::DRAFT),
            InvoiceStatus::Cancelled => $this->render($invoice, InvoicePage::CANCELLED),
            InvoiceStatus::Issued, InvoiceStatus::PartiallyPaid, InvoiceStatus::Paid => $this->issued($invoice),
        });
    }

    /**
     * Stores a numbered document's PDF as it is issued, unless it already keeps one.
     *
     * @throws InvoiceNotFound
     * @throws PdfRenderingFailed
     */
    public function storeIssued(Uuid $companyId, Uuid $id): void
    {
        $invoice = $this->invoices->ofIdInCompany($id, $companyId) ?? throw new InvoiceNotFound();
        if (null !== $invoice->getNumber() && null === $invoice->getPdfFile()) {
            $this->issued($invoice);
        }
    }

    private function issued(Invoice $invoice): string
    {
        $stored = $invoice->getPdfFile();
        if (null !== $stored) {
            return $this->files->contents($stored);
        }

        $contents = $this->render($invoice, null);
        $invoice->attachPdf($this->files->store($invoice->getCompany(), self::fileName($invoice), 'application/pdf', $contents, null));
        $this->invoices->save($invoice);

        return $contents;
    }

    /** @param InvoicePage::DRAFT|InvoicePage::CANCELLED|null $watermark */
    private function render(Invoice $invoice, ?string $watermark): string
    {
        $company = $invoice->getCompany();
        $customer = $invoice->getCustomer();
        $context = new SettingContext($company, customerGroupId: $customer->getGroup()?->getId(), customerId: $customer->getId());
        $printedNotes = $this->settings->value($context, 'document.printed_notes');
        $issuedLanguage = $invoice->getLanguage();
        if (null === $issuedLanguage) {
            $language = $this->settings->value($context, 'document.language');
            $profile = $company->getProfile();
            [$language, $mentionKeys, $latePenaltyText, $footer] = [\is_string($language) ? $language : 'fr', $this->mentions->keys($company, $customer), $profile->latePenaltyText, $profile->invoiceFooterText];
        } else {
            [$language, $mentionKeys, $latePenaltyText, $footer] = [$issuedLanguage, $invoice->getMentionKeys(), $invoice->getLatePenaltyText(), $invoice->getFooter()];
        }

        return $this->renderer->render($this->template->html(new InvoicePage(
            $invoice,
            $this->totals->figures($invoice),
            $invoice->getCustomerSnapshot() ?? CustomerSnapshot::of($customer),
            $watermark,
            $language,
            \is_string($printedNotes) ? $printedNotes : '',
            $mentionKeys,
            $latePenaltyText,
            $footer,
            ...DocumentFormats::of($this->settings, $company),
        )));
    }

    private static function fileName(Invoice $invoice): string
    {
        $number = $invoice->getNumber();

        // A numbering format may carry slashes, which a file name may not.
        return null === $number ? \sprintf('invoice-%s.pdf', $invoice->getId()->toRfc4122()) : str_replace('/', '-', $number).'.pdf';
    }
}
