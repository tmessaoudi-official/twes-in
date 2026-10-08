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
use App\Fiscal\Domain\Calculation\Decimal;
use App\Fiscal\Domain\Calculation\QuantityTotal;
use App\Fiscal\Domain\Calculation\QuantityTotals;
use App\Module\Customers\Domain\CustomerSnapshot;
use App\Module\Invoices\Domain\Invoice;
use App\Module\Invoices\Domain\InvoiceLine;
use App\Module\Invoices\Domain\InvoiceRepository;
use App\Module\Invoices\Domain\InvoiceStatus;
use App\Module\Invoices\Domain\InvoiceType;
use App\Settings\Application\DocumentFormats;
use App\Settings\Application\ReadSetting;
use App\Settings\Application\SettingContext;
use App\Shared\Application\PdfRenderer;
use App\Shared\Application\PdfRenderingFailed;
use App\Shared\Domain\AmountInWords;
use App\Shared\Domain\DocumentDesign;
use App\Tenancy\Domain\Company;
use App\Tenancy\Domain\SellerSnapshot;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * An invoice's or a credit note's PDF. An issued
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
        private ClockInterface $clock,
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
     * A second output of an issued document, rendered again from what issuing kept (its language, texts, seller and
     * print settings) and marked as a copy. Never stored: the original stays the one file issuing wrote.
     *
     * @throws InvoiceNotFound
     * @throws NoCopyOfADraft
     * @throws PdfRenderingFailed
     */
    public function copy(Company $company, Uuid $id, InvoiceCopy $kind): PrintedInvoice
    {
        $invoice = $this->invoices->ofIdInCompany($id, $company->getId()) ?? throw new InvoiceNotFound();
        if (!\in_array($invoice->getStatus(), [InvoiceStatus::Issued, InvoiceStatus::PartiallyPaid, InvoiceStatus::Paid], true)) {
            throw new NoCopyOfADraft();
        }
        $stamp = InvoiceCopy::UpToDate === $kind && true === $this->settings->value(new SettingContext($company), 'document.paid_stamp') ? self::stamp($invoice) : null;
        $watermark = InvoiceCopy::Duplicate === $kind ? InvoicePage::DUPLICATE : InvoicePage::COPY;
        $today = \DateTimeImmutable::createFromInterface($this->clock->now())->setTimezone(new \DateTimeZone($company->getTimezone()));

        return new PrintedInvoice(self::fileName($invoice, $kind), $this->render($invoice, $watermark, $kind, $today, $stamp));
    }

    /**
     * The company's latest invoice as a design would print it, its first page as a picture, for the screen where the
     * design is chosen (docs/SPEC.md § 7, 2026-10-06 10:19): whatever it is, draft or issued, it prints as it would today
     * or as it was issued, under the preview's watermark, and nothing is stored.
     *
     * @throws NothingToPreview   when the company has no invoice yet
     * @throws PdfRenderingFailed
     */
    public function designPreview(Company $company, DocumentDesign $design): string
    {
        $invoice = $this->invoices->latestOfCompany($company->getId()) ?? throw new NothingToPreview();

        return $this->renderer->firstPage($this->html($invoice, InvoicePage::PREVIEW, design: $design));
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

    /** @param InvoicePage::DRAFT|InvoicePage::CANCELLED|InvoicePage::DUPLICATE|InvoicePage::COPY|null $watermark */
    private function render(Invoice $invoice, ?string $watermark, ?InvoiceCopy $copy = null, ?\DateTimeImmutable $copiedOn = null, ?string $paidStamp = null): string
    {
        return $this->renderer->render($this->html($invoice, $watermark, $copy, $copiedOn, $paidStamp));
    }

    /**
     * @param InvoicePage::DRAFT|InvoicePage::CANCELLED|InvoicePage::DUPLICATE|InvoicePage::COPY|InvoicePage::PREVIEW|null $watermark
     * @param DocumentDesign|null                                                                                          $design    in place of the one it prints in, for a preview
     */
    private function html(Invoice $invoice, ?string $watermark, ?InvoiceCopy $copy = null, ?\DateTimeImmutable $copiedOn = null, ?string $paidStamp = null, ?DocumentDesign $design = null): string
    {
        $company = $invoice->getCompany();
        $customer = $invoice->getCustomer();
        $context = new SettingContext($company, customerGroupId: $customer->getGroup()?->getId(), customerId: $customer->getId());
        $print = $invoice->getPrintSettings() ?? DocumentFormats::print($this->settings, $context, $company);
        $issuedLanguage = $invoice->getLanguage();
        if (null === $issuedLanguage) {
            $language = $this->settings->value($context, 'document.language');
            $profile = $company->getProfile();
            $language = \is_string($language) ? $language : 'fr';
            [$mentions, $latePenaltyText, $footer] = [$this->mentions->asTheyStand($company, $customer, $invoice->getType(), $language), $profile->latePenaltyText, $profile->invoiceFooterText];
        } else {
            [$language, $mentions, $latePenaltyText, $footer] = [$issuedLanguage, new PrintedMentions($invoice->getMentionKeys(), $invoice->getMentionParameters()), $invoice->getLatePenaltyText(), $invoice->getFooter()];
        }

        $figures = $this->totals->figures($invoice);

        return $this->template->html(new InvoicePage(
            $invoice,
            $figures,
            $invoice->getCustomerSnapshot() ?? CustomerSnapshot::of($customer),
            $invoice->getSellerSnapshot() ?? SellerSnapshot::of($company, $invoice->getEstablishment()),
            $watermark,
            $language,
            $print->printedNotes,
            $mentions->keys,
            $latePenaltyText,
            $footer,
            $print->dateFormat,
            $print->numberFormat,
            $print->amountInWords ? AmountInWords::of($figures->total, $company->getCurrency(), $language) : null,
            $print->howToPay,
            $copy,
            $copiedOn,
            $paidStamp,
            $mentions->parameters,
            $design ?? $print->design,
            InvoiceType::CreditNote === $invoice->getType() ? null : $print->savingsPrinted($figures->savings),
            self::quantities($invoice),
        ));
    }

    /**
     * What the lines carry in each unit; a line giving a deposit back carries nothing.
     *
     * @return list<QuantityTotal>
     */
    private static function quantities(Invoice $invoice): array
    {
        $lines = array_values(array_filter($invoice->getLines(), static fn (InvoiceLine $line): bool => null === $line->getDeduction()));

        return QuantityTotals::ofUnits(array_map(static fn (InvoiceLine $line): array => [$line->getUnit(), $line->getQuantity()], $lines));
    }

    /**
     * What became of an issued invoice, as its up-to-date copy stamps it: « Acquittée » only when money paid it, « Soldée »
     * when a credit note closed it (alone or after part of it was paid), « Réglée partiellement » while some is still due.
     *
     * @return 'paid'|'settled'|'partial'|null
     */
    private static function stamp(Invoice $invoice): ?string
    {
        $credited = Decimal::of($invoice->getIssuedFigures()->amountCredited ?? '0');

        return match ($invoice->getStatus()) {
            InvoiceStatus::Paid => 0 === $credited->compare(0) ? 'paid' : 'settled',
            InvoiceStatus::PartiallyPaid => 'partial',
            default => null,
        };
    }

    private static function fileName(Invoice $invoice, ?InvoiceCopy $copy = null): string
    {
        $number = $invoice->getNumber();
        $suffix = null === $copy ? '' : '-'.$copy->value;

        // A numbering format may carry slashes, which a file name may not.
        return null === $number ? \sprintf('invoice-%s.pdf', $invoice->getId()->toRfc4122()) : str_replace('/', '-', $number).$suffix.'.pdf';
    }
}
