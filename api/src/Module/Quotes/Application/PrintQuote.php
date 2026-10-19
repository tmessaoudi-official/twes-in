<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Quotes\Application;

use App\Fiscal\Application\DocumentNames;
use App\Fiscal\Domain\Calculation\QuantityTotals;
use App\Fiscal\Domain\Model\Unit;
use App\Module\Customers\Domain\ValueObject\CustomerSnapshot;
use App\Module\Quotes\Domain\Model\Quote;
use App\Module\Quotes\Domain\Model\QuoteLine;
use App\Module\Quotes\Domain\Repository\QuoteRepository;
use App\Module\Quotes\Domain\ValueObject\QuoteStatus;
use App\Settings\Application\ReadSetting;
use App\Settings\Application\SettingContext;
use App\Shared\Application\PdfRenderer;
use App\Shared\Application\PdfRenderingFailed;
use App\Tenancy\Domain\Model\Company;
use App\Tenancy\Domain\ValueObject\SellerSnapshot;
use Symfony\Component\Uid\Uuid;

/**
 * A quote's PDF, rendered on request. A sent quote prints what sending kept: its customer, its seller, its tax rates
 * and how it prints, so it reads the same whatever changes after; and its unit and tax names too, unless the company
 * keeps them live (`document.names_as_issued`). A draft prints as its settings say today across
 * « BROUILLON », and a cancelled one across « ANNULÉ ». Nothing is stored: a quote is not a fiscal document.
 */
final readonly class PrintQuote
{
    public function __construct(
        private QuoteRepository $quotes,
        private QuoteTotals $totals,
        private QuoteTemplate $template,
        private PdfRenderer $renderer,
        private ReadSetting $settings,
        private DocumentNames $names,
    ) {
    }

    /**
     * @throws QuoteNotFound
     * @throws PdfRenderingFailed
     */
    public function pdf(Company $company, Uuid $id): PrintedQuote
    {
        $quote = $this->quotes->ofIdInCompany($id, $company->getId()) ?? throw new QuoteNotFound();
        $watermark = match ($quote->getStatus()) {
            QuoteStatus::Draft => QuotePage::DRAFT,
            QuoteStatus::Cancelled => QuotePage::CANCELLED,
            QuoteStatus::Sent, QuoteStatus::Accepted, QuoteStatus::Refused => null,
        };
        $printing = $quote->getPrintSettings() ?? QuotePrinting::today($this->settings, $quote);
        $totals = $this->totals->of($quote);
        // No print of a sent quote is kept, so each is a reprint: it names things as it was sent unless the company keeps
        // the names live.
        $kept = $quote->getPrintedNames();
        $names = null !== $kept && true === $this->settings->value(new SettingContext($quote->getCompany()), 'document.names_as_issued')
            ? $kept
            : $this->names->now($quote->getCompany(), $printing->language, array_map(static fn (QuoteLine $line): Unit => $line->getUnit(), $quote->getLines()));

        return new PrintedQuote(self::fileName($quote), $this->renderer->render($this->template->html(new QuotePage(
            $quote,
            $totals,
            $quote->getCustomerSnapshot() ?? CustomerSnapshot::of($quote->getCustomer()),
            $quote->getSellerSnapshot() ?? SellerSnapshot::of($quote->getCompany(), $quote->getEstablishment()),
            $watermark,
            $printing->language,
            $printing->print->printedNotes,
            $printing->signatureBlock,
            $printing->print->dateFormat,
            $printing->print->numberFormat,
            $printing->print->design,
            $printing->print->savingsPrinted($totals->savings()),
            QuantityTotals::ofUnits(array_map(static fn (QuoteLine $line): array => [$line->getUnit(), $line->getQuantity()], $quote->getLines()), $names),
            $printing->print->exemptionReference,
            $names,
        ))));
    }

    private static function fileName(Quote $quote): string
    {
        $number = $quote->getNumber();

        // A numbering format may carry slashes, which a file name may not.
        return null === $number ? \sprintf('quote-%s.pdf', $quote->getId()->toRfc4122()) : str_replace('/', '-', $number).'.pdf';
    }
}
