<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Application\FacturX;

use App\Fiscal\Application\CurrencyScales;
use App\Fiscal\Application\Preset\FiscalPresets;
use App\Fiscal\Application\Preset\PresetRegime;
use App\Fiscal\Domain\Calculation\Decimal;
use App\Fiscal\Domain\TaxFamily;
use App\Module\Customers\Domain\CustomerSnapshot;
use App\Module\Invoices\Application\InvoiceNotFound;
use App\Module\Invoices\Application\InvoiceTotals;
use App\Module\Invoices\Domain\Invoice;
use App\Module\Invoices\Domain\InvoiceFigures;
use App\Module\Invoices\Domain\InvoiceLine;
use App\Module\Invoices\Domain\InvoiceLineTax;
use App\Module\Invoices\Domain\InvoiceRepository;
use App\Module\Invoices\Domain\InvoiceStatus;
use App\Module\Invoices\Domain\InvoiceType;
use App\Tenancy\Domain\Company;
use BcMath\Number;
use Symfony\Component\Uid\Uuid;

/**
 * An issued French invoice or credit note in the terms of EN 16931 (docs/SPEC.md § 8 row 145), for the Factur-X EN 16931
 * profile: read from what issuing fixed and never recomputed, a credit note's negative figures written positive under
 * type code 381. What the standard needs and the document or its company lacks is refused, every gap named at once, and
 * nothing is guessed: a line without VAT takes the category its regime declares in the fiscal preset, or none at all.
 *
 * The seller is the company as it reads now, at the address its invoice prints (the establishment's, else the
 * company's): nothing snapshots the company at issue yet.
 */
final readonly class DescribeFacturX
{
    /** The presets whose invoices are written as Factur-X: France's identifiers (SIREN, VAT number) are what the seller and buyer blocks read. */
    private const array PRESETS = ['FR'];

    /** The line price's and quantity's decimals, as their columns hold them. */
    private const int PRICE_SCALE = 4;
    private const int QUANTITY_SCALE = 3;

    public function __construct(
        private InvoiceRepository $invoices,
        private InvoiceTotals $totals,
        private FiscalPresets $presets,
        private CurrencyScales $scales,
    ) {
    }

    /**
     * @throws InvoiceNotFound
     * @throws FacturXRefused
     */
    public function describe(Company $company, Uuid $id): CiiInvoice
    {
        $invoice = $this->invoices->ofIdInCompany($id, $company->getId()) ?? throw new InvoiceNotFound();
        $number = $invoice->getNumber();
        $issueDate = $invoice->getIssueDate();
        $snapshot = $invoice->getCustomerSnapshot();
        $issued = \in_array($invoice->getStatus(), [InvoiceStatus::Issued, InvoiceStatus::PartiallyPaid, InvoiceStatus::Paid], true);
        if (!$issued || null === $number || null === $issueDate || null === $snapshot || null === $invoice->getIssuedFigures()) {
            throw new FacturXRefused(\sprintf('The document is %s: only an issued invoice or credit note is written as Factur-X.', $invoice->getStatus()->value), FacturXRefused::NOT_ISSUED, ['status' => $invoice->getStatus()->value]);
        }
        $presetKey = $company->getFiscalPreset();
        if (!\in_array($presetKey, self::PRESETS, true)) {
            throw new FacturXRefused(\sprintf('No Factur-X is written for a company on the %s preset.', $presetKey), 'preset_not_supported', ['preset' => $presetKey]);
        }
        $preset = $this->presets->get($presetKey);
        $scale = $this->scales->of($company->getCurrency());
        $figures = $this->totals->figures($invoice);
        $credit = InvoiceType::CreditNote === $invoice->getType();
        $amount = static fn (string $stored): string => Decimal::format(Decimal::of($stored)->mul($credit ? -1 : 1), $scale);

        $companyRegime = self::regime($preset->companyVatRegimes, $company->getProfile()->vatRegime);
        $customerRegime = self::regime($preset->customerTaxRegimes, $snapshot->taxRegimeCode);
        $withoutVat = null !== $companyRegime?->vatCategory ? $companyRegime : (null !== $customerRegime?->vatCategory ? $customerRegime : null);

        $lineGaps = [];
        $lines = [];
        /** @var array<int, string|null> $vatCodeOfLine the VAT component each line carries, null for a line without VAT */
        $vatCodeOfLine = [];
        foreach ($invoice->getLines() as $i => $line) {
            [$vat, $vatCode, $gaps] = $this->lineVat($line, $i + 1, $withoutVat, $snapshot);
            $lineGaps = [...$lineGaps, ...$gaps];
            $vatCodeOfLine[$i] = $vatCode;
            $lines[] = $this->line($line, $figures, $i, $vat, $amount, $scale);
        }
        foreach ($invoice->getDocumentTaxes() as $tax) {
            $lineGaps[] = self::gap('document_tax_unsupported', ['code' => $tax->getCode()]);
        }

        [$allowances, $breakdown, $breakdownGaps] = $this->vatGroups($figures, $lines, $vatCodeOfLine, $amount, $scale);
        $categories = array_values(array_unique(array_map(static fn (CiiLine $line): string => $line->vat->category, $lines)));

        $seller = $this->seller($invoice, $company, $categories);
        $buyer = self::buyer($snapshot, $categories);
        $gaps = [...$seller[1], ...$buyer[1], ...$lineGaps, ...$breakdownGaps];
        if ([] !== $gaps) {
            throw new FacturXRefused(\sprintf('The %s %s lacks what EN 16931 asks for: %s.', $invoice->getType()->value, $number, implode(', ', array_map(static fn (array $gap): string => $gap['code'], $gaps))), 'incomplete_document', ['count' => \count($gaps)], $gaps);
        }

        $header = $invoice->getHeader();
        $corrected = $invoice->getCorrectedInvoice();
        $profile = $company->getProfile();
        $total = $amount($figures->total);

        return new CiiInvoice(
            $credit ? '381' : '380',
            $number,
            $issueDate,
            $company->getCurrency(),
            array_values(array_filter([$invoice->getCreditNoteReason(), $header->notesPrinted], static fn (?string $note): bool => null !== $note && '' !== $note)),
            $seller[0],
            $buyer[0],
            $header->customerReference,
            $header->supplyDate,
            $corrected?->getNumber(),
            $corrected?->getIssueDate(),
            $profile->iban,
            null === $profile->iban ? null : $profile->bic,
            $invoice->getDueDate(),
            $lines,
            $allowances,
            $breakdown,
            new CiiTotals($amount($figures->subtotalNet), $amount($figures->documentDiscount), $amount($figures->totalNet), $amount($figures->totalTax), $total, $total),
        );
    }

    /**
     * A line's VAT: `S` at the rate its one VAT component was charged at; without VAT, the category and exemption its
     * regime declares; anything else a gap.
     *
     * @return array{0: CiiVat, 1: string|null, 2: list<array{code: string, params: array<string, string|int|list<string>>}>}
     */
    private function lineVat(InvoiceLine $line, int $position, ?PresetRegime $withoutVat, CustomerSnapshot $snapshot): array
    {
        $gaps = [];
        $vat = [];
        foreach ($line->getTaxes() as $tax) {
            if (TaxFamily::Vat === $tax->getTaxComponent()->getFamily()) {
                $vat[] = $tax;
                continue;
            }
            $gaps[] = self::gap('line_tax_unsupported', ['line' => $position, 'code' => $tax->getCode()]);
        }
        if (\count($vat) > 1) {
            $gaps[] = self::gap('line_vat_ambiguous', ['line' => $position, 'codes' => array_map(static fn (InvoiceLineTax $tax): string => $tax->getCode(), $vat)]);

            return [new CiiVat('S', null, null), null, $gaps];
        }
        if (1 === \count($vat)) {
            $rate = Decimal::of($vat[0]->getRate());
            if (0 === $rate->compare(0)) {
                // Zero-rated (Z) or exempt (E)? A rate of nought does not say, and the component declares neither.
                $gaps[] = self::gap('vat_category_unknown', ['line' => $position, 'code' => $vat[0]->getCode()]);
            }

            return [new CiiVat('S', self::rate($rate), null), $vat[0]->getCode(), $gaps];
        }
        if (null === $withoutVat || null === $withoutVat->vatCategory) {
            $gaps[] = self::gap('vat_exemption_undeclared', ['line' => $position, 'regime' => $snapshot->taxRegimeCode]);

            return [new CiiVat('E', '0.00', null), null, $gaps];
        }

        return [new CiiVat($withoutVat->vatCategory, 'O' === $withoutVat->vatCategory ? null : '0.00', $withoutVat->vatExemptionCode), null, $gaps];
    }

    /** @param \Closure(string): string $amount */
    private function line(InvoiceLine $line, InvoiceFigures $figures, int $index, CiiVat $vat, \Closure $amount, int $scale): CiiLine
    {
        $net = $amount($figures->lines[$index]['net'] ?? throw new \LogicException('An issued line has no figures.'));
        $rate = $line->getDiscountRate();
        $percent = null;
        $basis = null;
        $discount = null;
        if (null !== $rate && 0 !== Decimal::of($rate)->compare(0)) {
            $percent = self::rate(Decimal::of($rate));
            // The amount the line's discount was taken from, rounded as the calculator rounded it: what is left is the net.
            $gross = Decimal::round(Decimal::of($line->getQuantity())->mul(Decimal::of($line->getUnitPriceNet()), Decimal::WORKING_SCALE), $scale);
            $basis = Decimal::format($gross, $scale);
            $discount = Decimal::format($gross->sub(Decimal::of($net)), $scale);
        }

        return new CiiLine(
            (string) $line->getPosition(),
            $line->getDescription(),
            $line->getProduct()?->getReference(),
            Decimal::format(Decimal::of($line->getUnitPriceNet()), self::PRICE_SCALE),
            Decimal::format(Decimal::of($line->getQuantity()), self::QUANTITY_SCALE),
            $line->getUnit()->getCode(),
            $percent,
            $basis,
            $discount,
            $vat,
            $net,
        );
    }

    /**
     * The document discount and the breakdown, one entry per VAT category and rate. A VAT group's share of the discount
     * is what its lines come to less the base issuing taxed; the lines without VAT, all under their regime's one
     * category, take what is left of the discount.
     *
     * @param list<CiiLine>            $lines
     * @param array<int, string|null>  $vatCodeOfLine
     * @param \Closure(string): string $amount
     *
     * @return array{0: list<CiiAllowance>, 1: list<CiiVatBreakdown>, 2: list<array{code: string, params: array<string, string|int|list<string>>}>}
     */
    private function vatGroups(InvoiceFigures $figures, array $lines, array $vatCodeOfLine, \Closure $amount, int $scale): array
    {
        $allowances = [];
        $breakdown = [];
        $gaps = [];
        $seen = [];
        $allowed = Decimal::zero();
        foreach ($figures->taxes as $tax) {
            $carriers = array_keys(array_filter($vatCodeOfLine, static fn (?string $code): bool => $code === $tax['code']));
            if ([] === $carriers) {
                continue;
            }
            $vat = $lines[$carriers[0]]->vat;
            $key = $vat->category.'|'.$vat->rate;
            if (isset($seen[$key])) {
                $gaps[] = self::gap('vat_rate_shared', ['rate' => (string) $vat->rate, 'codes' => [$seen[$key], $tax['code']]]);
            }
            $seen[$key] = $tax['code'];
            $basis = $amount($tax['base']);
            $lineSum = Decimal::sum(array_map(static fn (int $i): Number => Decimal::of($lines[$i]->lineTotal), $carriers));
            $share = $lineSum->sub(Decimal::of($basis));
            $allowed = $allowed->add($share);
            if (0 !== $share->compare(0)) {
                $allowances[] = new CiiAllowance(Decimal::format($share, $scale), $vat);
            }
            $taxAmount = $amount($tax['amount']);
            $expected = Decimal::format(Decimal::round(Decimal::of($basis)->mul(Decimal::of((string) $vat->rate))->div(100, Decimal::WORKING_SCALE), $scale), $scale);
            if ($expected !== $taxAmount) {
                // BR-CO-17: a category's VAT is its base times its rate, rounded once; a preset rounding per line differs.
                $gaps[] = self::gap('vat_rounding_differs', ['rate' => (string) $vat->rate]);
            }
            $breakdown[] = new CiiVatBreakdown($vat, $basis, $taxAmount);
        }

        $documentDiscount = Decimal::of($amount($figures->documentDiscount));
        $withoutVat = array_keys(array_filter($vatCodeOfLine, static fn (?string $code): bool => null === $code));
        $rest = $documentDiscount->sub($allowed);
        if ([] !== $withoutVat) {
            $vat = $lines[$withoutVat[0]]->vat;
            $lineSum = Decimal::sum(array_map(static fn (int $i): Number => Decimal::of($lines[$i]->lineTotal), $withoutVat));
            if (0 !== $rest->compare(0)) {
                $allowances[] = new CiiAllowance(Decimal::format($rest, $scale), $vat);
            }
            $breakdown[] = new CiiVatBreakdown($vat, Decimal::format($lineSum->sub($rest), $scale), Decimal::format(Decimal::zero(), $scale));
        } elseif (0 !== $rest->compare(0) && [] === $gaps) {
            throw new \LogicException(\sprintf('The document discount %s does not split over the VAT groups: %s is left.', $amount($figures->documentDiscount), Decimal::format($rest, $scale)));
        }

        return [$allowances, $breakdown, $gaps];
    }

    /**
     * @param list<string> $categories
     *
     * @return array{0: CiiParty, 1: list<array{code: string, params: array<string, string|int|list<string>>}>}
     */
    private function seller(Invoice $invoice, Company $company, array $categories): array
    {
        $profile = $company->getProfile();
        $establishment = $invoice->getEstablishment();
        // The address the invoice prints: the establishment's when it has one, else the company's (templates/pdf/invoice.html.twig).
        $address = null !== $establishment->getAddressLine1()
            ? [$establishment->getAddressLine1(), $establishment->getAddressLine2(), $establishment->getPostalCode(), $establishment->getCity()]
            : [$profile->addressLine1, $profile->addressLine2, $profile->postalCode, $profile->city];
        $siren = $profile->identifiers['siren'] ?? null;
        $vatNumber = $profile->identifiers['vat_number'] ?? null;

        $gaps = [];
        if (null === $siren) {
            $gaps[] = self::gap('seller_siren_missing');
        }
        // BR-S-02, BR-E-02, BR-IC-02, BR-G-02: every category but "not subject to VAT" names the seller's VAT number.
        if (null === $vatNumber && [] !== array_diff($categories, ['O'])) {
            $gaps[] = self::gap('seller_vat_number_missing');
        }
        $missing = self::missing(['line1' => $address[0], 'postalCode' => $address[2], 'city' => $address[3]]);
        if ([] !== $missing) {
            $gaps[] = self::gap('seller_address_incomplete', ['missing' => $missing]);
        }

        return [new CiiParty($profile->legalName ?? $company->getName(), $siren, $vatNumber, $address[0], $address[1], $address[2], $address[3], $company->getCountryCode()), $gaps];
    }

    /**
     * @param list<string> $categories
     *
     * @return array{0: CiiParty, 1: list<array{code: string, params: array<string, string|int|list<string>>}>}
     */
    private static function buyer(CustomerSnapshot $snapshot, array $categories): array
    {
        [$line1, $line2, $postalCode, $city, $country] = $snapshot->billingAddress->parts();
        $vatNumber = $snapshot->identifiers['vat_number'] ?? null;

        $gaps = [];
        $missing = self::missing(['line1' => $line1, 'postalCode' => $postalCode, 'city' => $city, 'countryCode' => $country]);
        if ([] !== $missing) {
            $gaps[] = self::gap('buyer_address_incomplete', ['missing' => $missing]);
        }
        // BR-IC-04: an intra-community supply names the buyer's VAT number.
        if (null === $vatNumber && \in_array('K', $categories, true)) {
            $gaps[] = self::gap('buyer_vat_number_missing');
        }

        return [new CiiParty($snapshot->legalName ?? $snapshot->name, $snapshot->identifiers['siren'] ?? null, $vatNumber, $line1, $line2, $postalCode, $city, $country ?? ''), $gaps];
    }

    /**
     * @param array<string, string|null> $fields
     *
     * @return list<string> the names of those without a value, in order
     */
    private static function missing(array $fields): array
    {
        return array_keys(array_filter($fields, static fn (?string $value): bool => null === $value || '' === $value));
    }

    /** @param list<PresetRegime> $regimes */
    private static function regime(array $regimes, string $code): ?PresetRegime
    {
        foreach ($regimes as $regime) {
            if ($regime->code === $code) {
                return $regime;
            }
        }

        return null;
    }

    /** A percentage as EN 16931 examples write it: two decimals, three when the rate has them. */
    private static function rate(Number $rate): string
    {
        $written = Decimal::format($rate, 3);

        return str_ends_with($written, '0') ? substr($written, 0, -1) : $written;
    }

    /**
     * @param array<string, string|int|list<string>> $params
     *
     * @return array{code: string, params: array<string, string|int|list<string>>}
     */
    private static function gap(string $code, array $params = []): array
    {
        if (!\in_array($code, FacturXRefused::GAPS, true)) {
            throw new \LogicException(\sprintf('The gap %s is not in the contract (FacturXRefused::GAPS).', $code));
        }

        return ['code' => $code, 'params' => $params];
    }
}
