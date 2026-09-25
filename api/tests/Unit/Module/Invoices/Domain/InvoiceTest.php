<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Module\Invoices\Domain;

use App\Fiscal\Application\Company\ProvisionCompany;
use App\Fiscal\Domain\CustomerTaxRegime;
use App\Fiscal\Domain\TaxComponent;
use App\Fiscal\Domain\TaxKind;
use App\Fiscal\Domain\Unit;
use App\Module\Customers\Domain\Customer;
use App\Module\Customers\Domain\CustomerKind;
use App\Module\Customers\Domain\CustomerProfile;
use App\Module\Invoices\Domain\InvalidInvoice;
use App\Module\Invoices\Domain\Invoice;
use App\Module\Invoices\Domain\InvoiceHeader;
use App\Module\Invoices\Domain\InvoiceLineDetails;
use App\Module\Invoices\Domain\InvoiceNotDraft;
use App\Module\Invoices\Domain\InvoiceStatus;
use App\Module\Invoices\Domain\InvoiceTax;
use App\Module\Invoices\Domain\InvoiceTransitionRefused;
use App\Module\Invoices\Domain\InvoiceType;
use App\Module\Invoices\Domain\PaymentDetails;
use App\Module\Products\Domain\Product;
use App\Module\Products\Domain\ProductDetails;
use App\Module\Products\Domain\ProductKind;
use App\Shared\Domain\PaymentMethod;
use App\Tenancy\Domain\Company;
use App\Tenancy\Domain\Establishment;
use App\Tests\Support\InMemoryEstablishments;
use App\Tests\Support\InMemoryNumberingSeries;
use App\Tests\Support\InMemoryTaxComponents;
use App\Tests\Support\InMemoryUnits;
use App\Tests\Support\ShippedFiscalPresets;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class InvoiceTest extends TestCase
{
    private \DateTimeImmutable $now;
    private InMemoryUnits $units;
    private InMemoryTaxComponents $taxes;
    private InMemoryEstablishments $establishments;
    private Company $company;
    private Company $globex;

    protected function setUp(): void
    {
        $this->now = new \DateTimeImmutable('2026-09-15 09:00:00');
        $this->units = new InMemoryUnits();
        $this->taxes = new InMemoryTaxComponents();
        $this->establishments = new InMemoryEstablishments();
        $provision = new ProvisionCompany(ShippedFiscalPresets::presets(), $this->taxes, $this->units, $this->establishments, new InMemoryNumberingSeries(), ShippedFiscalPresets::scales(), new \Symfony\Component\Clock\MockClock($this->now));
        $this->company = new Company('Acme', 'TN', 'TND', 'fr', 'Africa/Tunis');
        $this->globex = new Company('Globex', 'TN', 'TND', 'fr', 'Africa/Tunis');
        $provision->handle($this->company);
        $provision->handle($this->globex);
    }

    public function testADraftInvoiceHoldsItsLinesDiscountsAndDocumentTaxesAsTheyStandToday(): void
    {
        $customer = $this->customer($this->company);
        $header = new InvoiceHeader(new \DateTimeImmutable('2026-09-10 17:00:00'), 45, ' PO-77 ', 'Merci', 'VIP', '12.5');

        $invoice = Invoice::create($this->company, $this->establishment(), $customer, $header, [
            new InvoiceLineDetails($this->product($this->company), 'Portable 14"', '2', $this->unit('C62'), '1250', '10', [$this->tax('FODEC'), $this->tax('TVA19')]),
            new InvoiceLineDetails(null, 'Pose', '1.5', $this->unit('HUR'), '40', null, []),
        ], [$this->tax('TIMBRE'), $this->tax('RS1')], $this->now);

        self::assertSame([InvoiceType::Invoice, InvoiceStatus::Draft, null, null], [$invoice->getType(), $invoice->getStatus(), $invoice->getNumber(), $invoice->getCorrectedInvoice()]);
        $kept = $invoice->getHeader();
        self::assertSame(['2026-09-10', 45, 'PO-77', 'Merci', 'VIP', '12.500'], [$kept->supplyDate?->format('Y-m-d'), $kept->paymentTermsDays, $kept->customerReference, $kept->notesPrinted, $kept->notesInternal, $kept->discountAmount]);
        $lines = $invoice->getLines();
        self::assertSame([1, 2], [$lines[0]->getPosition(), $lines[1]->getPosition()]);
        self::assertSame(['2.000', '1250.0000', '10.000'], [$lines[0]->getQuantity(), $lines[0]->getUnitPriceNet(), $lines[0]->getDiscountRate()]);
        self::assertNull($lines[1]->getDiscountRate(), 'no discount is no rate, not a zero rate');
        self::assertSame(['FODEC', 'TVA19'], array_map(static fn ($tax): string => $tax->getCode(), $lines[0]->getTaxes()));
        $documentTaxes = array_map(static fn (InvoiceTax $tax): array => [$tax->getPosition(), $tax->getCode(), $tax->getKind(), $tax->getRate(), $tax->getAmount(), $tax->getThreshold()], $invoice->getDocumentTaxes());
        self::assertSame([
            [1, 'TIMBRE', TaxKind::FixedDocument, null, '1.000', null],
            [2, 'RS1', TaxKind::WithholdingTotal, '1.000', null, '1000.000'],
        ], $documentTaxes);
    }

    public function testALineDiscountIsAPercentageFromZeroToAHundredWithThreeDecimals(): void
    {
        foreach (['-1', '100.001', '12.3456', 'ten'] as $rate) {
            $this->assertRefused('discountRate', fn () => new InvoiceLineDetails(null, 'Pièce', '1', $this->unit('C62'), '10', $rate, []), $rate);
        }
        self::assertSame('100.000', new InvoiceLineDetails(null, 'Pièce', '1', $this->unit('C62'), '10', '100', [])->discountRate);
        self::assertSame('0.000', new InvoiceLineDetails(null, 'Pièce', '1', $this->unit('C62'), '10', '0', [])->discountRate);
        self::assertNull(new InvoiceLineDetails(null, 'Pièce', '1', $this->unit('C62'), '10', ' ', [])->discountRate);
    }

    public function testALineCountsInItsUnitAtAPriceAndCarriesOnlyLineTaxesEachOnce(): void
    {
        $this->assertRefused('quantity', fn () => new InvoiceLineDetails(null, 'Pièce', '1.5', $this->unit('C62'), '10', null, []));
        $this->assertRefused('quantity', fn () => new InvoiceLineDetails(null, 'Pièce', '0', $this->unit('C62'), '10', null, []));
        $this->assertRefused('unitPriceNet', fn () => new InvoiceLineDetails(null, 'Pièce', '1', $this->unit('C62'), '-1', null, []));
        $this->assertRefused('description', fn () => new InvoiceLineDetails(null, ' ', '1', $this->unit('C62'), '10', null, []));
        $this->assertRefused('taxComponentIds', fn () => new InvoiceLineDetails(null, 'Pièce', '1', $this->unit('C62'), '10', null, [$this->tax('TIMBRE')]));
        $this->assertRefused('taxComponentIds', fn () => new InvoiceLineDetails(null, 'Pièce', '1', $this->unit('C62'), '10', null, [$this->tax('TVA19'), $this->tax('TVA19')]));
    }

    public function testAHeaderKeepsItsTermsDiscountAndTextsWithinBounds(): void
    {
        foreach ([-1, 366] as $days) {
            $this->assertRefused('paymentTermsDays', static fn () => new InvoiceHeader(paymentTermsDays: $days), (string) $days);
        }
        foreach (['-1', '1.0001', 'x', '100000000000'] as $amount) {
            $this->assertRefused('discountAmount', static fn () => new InvoiceHeader(discountAmount: $amount), $amount);
        }
        $this->assertRefused('customerReference', static fn () => new InvoiceHeader(customerReference: str_repeat('a', 65)));
        $this->assertRefused('notesPrinted', static fn () => new InvoiceHeader(notesPrinted: str_repeat('a', 5001)));
        $this->assertRefused('notesInternal', static fn () => new InvoiceHeader(notesInternal: str_repeat('a', 5001)));
        $empty = new InvoiceHeader(null, 0, '', ' ', null, ' ');
        self::assertSame([0, null, null, null, null], [$empty->paymentTermsDays, $empty->customerReference, $empty->notesPrinted, $empty->notesInternal, $empty->discountAmount]);
        self::assertSame('0.000', new InvoiceHeader(discountAmount: '0')->discountAmount);
    }

    public function testADocumentCarriesFixedChargesAndWithholdingsEachOnceAndNoLineTax(): void
    {
        $customer = $this->customer($this->company);
        $this->assertRefused('documentTaxComponentIds', fn () => Invoice::create($this->company, $this->establishment(), $customer, new InvoiceHeader(), [], [$this->tax('TVA19')], $this->now));
        $this->assertRefused('documentTaxComponentIds', fn () => Invoice::create($this->company, $this->establishment(), $customer, new InvoiceHeader(), [], [$this->tax('TIMBRE'), $this->tax('TIMBRE')], $this->now));
    }

    public function testEverythingAnInvoiceNamesBelongsToItsCompany(): void
    {
        $mine = $this->customer($this->company);
        $theirEstablishment = $this->establishments->ofCompany($this->globex->getId())[0];

        $this->assertRefused('establishmentId', fn () => Invoice::create($this->company, $theirEstablishment, $mine, new InvoiceHeader(), [], [], $this->now));
        $this->assertRefused('customerId', fn () => Invoice::create($this->company, $this->establishment(), $this->customer($this->globex), new InvoiceHeader(), [], [], $this->now));
        $this->assertRefused('lines[0].productId', fn () => Invoice::create($this->company, $this->establishment(), $mine, new InvoiceHeader(), [$this->pieceLine($this->product($this->globex))], [], $this->now));
        $this->assertRefused('lines[0].unitId', fn () => Invoice::create($this->company, $this->establishment(), $mine, new InvoiceHeader(), [$this->pieceLine(unit: $this->unit('C62', $this->globex))], [], $this->now));
        $this->assertRefused('lines[0].taxComponentIds', fn () => Invoice::create($this->company, $this->establishment(), $mine, new InvoiceHeader(), [$this->pieceLine(taxes: [$this->tax('TVA19', $this->globex)])], [], $this->now));
        $this->assertRefused('documentTaxComponentIds', fn () => Invoice::create($this->company, $this->establishment(), $mine, new InvoiceHeader(), [], [$this->tax('TIMBRE', $this->globex)], $this->now));
    }

    public function testARevisionNamesTheFieldsItChangedAndLeavesAnIdenticalInvoiceAlone(): void
    {
        $customer = $this->customer($this->company);
        $lines = [new InvoiceLineDetails(null, 'Pièce', '1', $this->unit('C62'), '10', null, [$this->tax('TVA19')])];
        $invoice = Invoice::create($this->company, $this->establishment(), $customer, new InvoiceHeader(customerReference: 'PO-1'), $lines, [$this->tax('TIMBRE')], $this->now);
        $later = $this->now->modify('+1 hour');

        self::assertSame([], $invoice->revise($this->establishment(), $customer, new InvoiceHeader(customerReference: 'PO-1'), $lines, [$this->tax('TIMBRE')], $later));
        self::assertEquals($this->now, $invoice->getUpdatedAt(), 'an identical revision changes nothing');

        $changed = $invoice->revise($this->establishment(), $this->customer($this->company, 'CLI-0002'), new InvoiceHeader(paymentTermsDays: 10, customerReference: 'PO-1', discountAmount: '1'), [
            new InvoiceLineDetails(null, 'Pièce', '1', $this->unit('C62'), '10', '5', [$this->tax('TVA19')]),
        ], [], $later);

        self::assertSame(['customerId', 'paymentTermsDays', 'discountAmount', 'documentTaxComponentIds', 'lines'], $changed);
        self::assertSame(['5.000', []], [$invoice->getLines()[0]->getDiscountRate(), $invoice->getDocumentTaxes()]);
        self::assertEquals($later, $invoice->getUpdatedAt());
    }

    public function testADraftIsCancelledOnceAndThenNeverRevised(): void
    {
        $customer = $this->customer($this->company);
        $invoice = Invoice::create($this->company, $this->establishment(), $customer, new InvoiceHeader(), [], [], $this->now);

        $invoice->cancel($this->now);

        self::assertSame(InvoiceStatus::Cancelled, $invoice->getStatus());
        $this->expectException(InvoiceTransitionRefused::class);
        try {
            $invoice->revise($this->establishment(), $customer, new InvoiceHeader(customerReference: 'late'), [], [], $this->now);
            self::fail('a cancelled invoice was revised');
        } catch (InvoiceNotDraft) {
        }
        $invoice->cancel($this->now);
    }

    public function testIssuingNumbersADraftFixesItsFiguresAndSnapshotsWhatItPrints(): void
    {
        $invoice = Invoice::create($this->company, $this->establishment(), $this->customer($this->company), new InvoiceHeader(), [$this->pieceLine(taxes: [$this->tax('TVA19')])], [$this->tax('TIMBRE')], $this->now);
        $issuedBy = Uuid::v7();
        $asked = 0;

        $invoice->issue(
            new \App\Module\Invoices\Domain\InvoiceIssue('FAC-2026-00001', new \DateTimeImmutable('2026-09-15 23:30:00'), 30, 'en', ['fiscal.mention.tn.export'], 'Pénalité de retard : 1 %', 'Merci', $issuedBy),
            function (Invoice $issuing) use (&$asked): \App\Module\Invoices\Domain\InvoiceFigures {
                ++$asked;

                return $this->figures();
            },
            $this->now,
        );

        self::assertSame(1, $asked, 'the figures are worked out once, as issuing fixes them');
        self::assertSame([InvoiceStatus::Issued, 'FAC-2026-00001', '2026-09-15', '2026-10-15', 30], [$invoice->getStatus(), $invoice->getNumber(), $invoice->getIssueDate()?->format('Y-m-d'), $invoice->getDueDate()?->format('Y-m-d'), $invoice->getHeader()->paymentTermsDays]);
        self::assertSame(['en', ['fiscal.mention.tn.export'], 'Pénalité de retard : 1 %', 'Merci'], [$invoice->getLanguage(), $invoice->getMentionKeys(), $invoice->getLatePenaltyText(), $invoice->getFooter()]);
        self::assertSame('CLI-0001', $invoice->getCustomerSnapshot()?->number);
        self::assertTrue($issuedBy->equals($invoice->getIssuedBy()));
        self::assertEquals($this->figures(), $invoice->getIssuedFigures());
        $events = $invoice->releaseEvents();
        self::assertCount(1, $events);
        self::assertInstanceOf(\App\Module\Invoices\Domain\InvoiceIssued::class, $events[0]);
        self::assertSame(['FAC-2026-00001', InvoiceType::Invoice, []], [$events[0]->number, $events[0]->type, $events[0]->sourceDeliveryNoteLineIds]);

        foreach ([
            'issued again' => fn () => $invoice->issue(new \App\Module\Invoices\Domain\InvoiceIssue('FAC-2026-00002', $this->now, 30, 'fr', [], null, null, null), fn (Invoice $i) => $this->figures(), $this->now),
            'revised' => fn () => $invoice->revise($this->establishment(), $this->customer($this->company), new InvoiceHeader(), [], [], $this->now),
        ] as $case => $attempt) {
            try {
                $attempt();
                self::fail("an issued invoice was $case");
            } catch (InvoiceNotDraft) {
            }
        }
        $this->expectException(InvoiceTransitionRefused::class);
        $invoice->cancel($this->now);
    }

    public function testAnInvoiceIsIssuedWithAtLeastOneLineAndADraftHasNoFixedFigures(): void
    {
        $invoice = Invoice::create($this->company, $this->establishment(), $this->customer($this->company), new InvoiceHeader(), [], [], $this->now);
        self::assertSame([null, null, null, null], [$invoice->getIssuedFigures(), $invoice->getDueDate(), $invoice->getCustomerSnapshot(), $invoice->getLanguage()]);

        $this->assertRefused('lines', fn () => $invoice->issue(new \App\Module\Invoices\Domain\InvoiceIssue('FAC-2026-00001', $this->now, 30, 'fr', [], null, null, null), static fn (Invoice $i) => throw new \LogicException('never asked'), $this->now));
        self::assertSame([InvoiceStatus::Draft, null], [$invoice->getStatus(), $invoice->getNumber()]);
    }

    public function testIssuedFiguresHoldOneRowPerLineAndTheirAmountsAtTheCurrencyScale(): void
    {
        $invoice = Invoice::create($this->company, $this->establishment(), $this->customer($this->company), new InvoiceHeader(), [$this->pieceLine(), $this->pieceLine()], [], $this->now);

        $this->expectException(\LogicException::class);
        $invoice->issue(new \App\Module\Invoices\Domain\InvoiceIssue('FAC-2026-00001', $this->now, 30, 'fr', [], null, null, null), fn (Invoice $i) => $this->figures(), $this->now);
    }

    public function testAPaymentLowersWhatIsDueAndTheStatusFollowsWhatIsPaidBothWays(): void
    {
        $invoice = $this->issued(total: '1190.000', withheld: '11.900', due: '1178.100');
        $today = new \DateTimeImmutable('2026-09-20');
        $recordedBy = Uuid::v7();

        $first = $invoice->recordPayment(new PaymentDetails(new \DateTimeImmutable('2026-09-15 22:00:00'), '178.1', PaymentMethod::Transfer, ' VIR-1 ', ''), $today, 3, $recordedBy, $this->now);

        self::assertSame(['2026-09-15', '178.100', PaymentMethod::Transfer, 'VIR-1', null], [$first->getDate()->format('Y-m-d'), $first->getAmount(), $first->getMethod(), $first->getReference(), $first->getNotes()]);
        self::assertTrue($recordedBy->equals($first->getRecordedBy()));
        self::assertSame([InvoiceStatus::PartiallyPaid, '178.100', '1000.000', '0.000'], $this->settlement($invoice), 'the issue day is a payment day');

        $second = $invoice->recordPayment(new PaymentDetails($today, '1000', PaymentMethod::Cash), $today, 3, null, $this->now);
        self::assertSame([InvoiceStatus::Paid, '1178.100', '0.000', '0.000'], $this->settlement($invoice), 'paying exactly what is due, today, pays the invoice');
        self::assertSame([$first, $second], $invoice->getPayments());
        self::assertSame('1190.000', $invoice->getIssuedFigures()?->total, 'a payment changes what is due, never what was invoiced');

        self::assertSame($first, $invoice->payment($first->getId()));
        $invoice->removePayment($first, $this->now);
        self::assertSame([InvoiceStatus::PartiallyPaid, '1000.000', '178.100', '0.000'], $this->settlement($invoice));
        $invoice->removePayment($second, $this->now);
        self::assertSame([InvoiceStatus::Issued, '0.000', '1178.100', '0.000'], $this->settlement($invoice), 'nothing paid is issued again');
        self::assertSame([[], null], [$invoice->getPayments(), $invoice->payment($first->getId())]);
    }

    public function testAPaymentIsAboveZeroAtMostWhatIsDueInTheCurrencyAndDatedFromTheIssueDayToToday(): void
    {
        $invoice = $this->issued(total: '1190.000', withheld: '11.900', due: '1178.100');
        $today = new \DateTimeImmutable('2026-09-20');
        $pay = fn (string $amount, string $day = '2026-09-18', int $scale = 3) => $invoice->recordPayment(new PaymentDetails(new \DateTimeImmutable($day), $amount, PaymentMethod::Check), $today, $scale, null, $this->now);

        foreach (['0', '0.000', '-5', '1.0001', '1,5', 'ten', ''] as $amount) {
            $this->assertRefused('amount', static fn () => $pay($amount), "amount \"$amount\"");
        }
        $this->assertRefused('amount', static fn () => $pay('1178.101'), 'a thousandth above what is due');
        $this->assertRefused('amount', static fn () => $pay('10.005', scale: 2), 'finer than a two-decimal currency');
        $this->assertRefused('date', static fn () => $pay('10', '2026-09-14'), 'the day before the issue day');
        $this->assertRefused('date', static fn () => $pay('10', '2026-09-21'), 'tomorrow');
        $this->assertRefused('reference', fn () => $invoice->recordPayment(new PaymentDetails($today, '1', PaymentMethod::Card, str_repeat('R', 65)), $today, 3, null, $this->now));
        $this->assertRefused('notes', fn () => $invoice->recordPayment(new PaymentDetails($today, '1', PaymentMethod::Other, null, str_repeat('n', 5001)), $today, 3, null, $this->now));
        self::assertSame([InvoiceStatus::Issued, '0.000', '1178.100', '0.000', []], [...$this->settlement($invoice), $invoice->getPayments()], 'a refused payment leaves no trace');

        $nothingDue = $this->issued(total: '0.000', withheld: '0.000', due: '0.000');
        self::assertSame(InvoiceStatus::Issued, $nothingDue->getStatus(), 'an invoice of nothing is issued, not paid: nothing was paid');
        $this->assertRefused('amount', fn () => $nothingDue->recordPayment(new PaymentDetails($today, '0.001', PaymentMethod::Cash), $today, 3, null, $this->now));
    }

    public function testOnlyAnIssuedInvoiceIsPaid(): void
    {
        $today = new \DateTimeImmutable('2026-09-20');
        $draft = Invoice::create($this->company, $this->establishment(), $this->customer($this->company), new InvoiceHeader(), [$this->pieceLine()], [], $this->now);
        $cancelled = Invoice::create($this->company, $this->establishment(), $this->customer($this->company), new InvoiceHeader(), [$this->pieceLine()], [], $this->now);
        $cancelled->cancel($this->now);

        foreach (['a draft' => $draft, 'a cancelled draft' => $cancelled] as $case => $invoice) {
            try {
                $invoice->recordPayment(new PaymentDetails($today, '1', PaymentMethod::Cash), $today, 3, null, $this->now);
                self::fail("$case was paid");
            } catch (InvoiceTransitionRefused) {
                self::assertSame([], $invoice->getPayments());
            }
        }
    }

    public function testACreditNoteCopiesAnIssuedInvoiceAndChargesTheTaxesItsInvoiceCharged(): void
    {
        [$vat, $vat13, $stamp] = [$this->tax('TVA19'), $this->tax('TVA13'), $this->tax('TIMBRE')];
        $invoice = Invoice::create($this->company, $this->establishment(), $this->customer($this->company), new InvoiceHeader(new \DateTimeImmutable('2026-09-10'), 30, 'PO-77', 'Merci', 'VIP', '1'), [
            new InvoiceLineDetails($this->product($this->company), 'Portable 14"', '2', $this->unit('C62'), '1250', '10', [$this->tax('FODEC'), $vat]),
        ], [$stamp, $this->tax('RS1')], $this->now);
        $invoice->issue(new \App\Module\Invoices\Domain\InvoiceIssue('FAC-2026-00001', $this->now, 30, 'fr', [], null, null, null), fn (Invoice $i) => $this->figures(), $this->now);
        $vat->revise($vat->getName(), '7', null, null, false, $vat->isDefault(), true, null, $vat->getSortOrder(), 3, $this->now);

        $credit = Invoice::creditNoteFor($invoice, 'Retour', $this->now);

        self::assertSame([InvoiceType::CreditNote, InvoiceStatus::Draft, null, $invoice, $invoice->getCustomer(), $invoice->getEstablishment()], [$credit->getType(), $credit->getStatus(), $credit->getNumber(), $credit->getCorrectedInvoice(), $credit->getCustomer(), $credit->getEstablishment()]);
        self::assertEquals($invoice->getHeader(), $credit->getHeader());
        self::assertSame(array_map(static fn ($line): array => $line->values(), $invoice->getLines()), array_map(static fn ($line): array => $line->values(), $credit->getLines()));
        self::assertSame([['FODEC', '1.000'], ['TVA19', '19.000']], $this->rates($credit)[0], 'the VAT its invoice charged, not the rate changed since');
        self::assertSame(['TIMBRE', 'RS1'], array_map(static fn (InvoiceTax $tax): string => $tax->getCode(), $credit->getDocumentTaxes()));
        self::assertNull($credit->getIssuedFigures());

        $credit->revise($credit->getEstablishment(), $credit->getCustomer(), $credit->getHeader(), [
            new InvoiceLineDetails(null, 'Portable 14"', '1', $this->unit('C62'), '1250', '10', [$this->tax('FODEC'), $vat]),
            new InvoiceLineDetails(null, 'Pose', '1', $this->unit('C62'), '40', null, [$vat13]),
        ], [$stamp], $this->now);
        $vat13->revise($vat13->getName(), '12', null, null, false, $vat13->isDefault(), true, null, $vat13->getSortOrder(), 3, $this->now);
        $stamp->revise($stamp->getName(), null, '2', null, false, $stamp->isDefault(), true, null, $stamp->getSortOrder(), 3, $this->now);
        $credit->issue(new \App\Module\Invoices\Domain\InvoiceIssue('AV-2026-00001', $this->now, 0, 'fr', [], null, null, null), fn (Invoice $i) => $this->figures('-12.900', '0.000', '-12.900', lines: 2), $this->now);

        self::assertSame([[['FODEC', '1.000'], ['TVA19', '19.000']], [['TVA13', '12.000']]], $this->rates($credit), 'issuing charges what the invoice charged, and a tax it did not carry as it stands that day');
        self::assertSame([['TIMBRE', '1.000']], array_map(static fn (InvoiceTax $tax): array => [$tax->getCode(), $tax->getAmount()], $credit->getDocumentTaxes()));
    }

    public function testACreditNoteStatesItsReasonWhenItIsCreatedAndAnInvoiceHasNone(): void
    {
        $invoice = $this->issued('12.900', '0.000', '12.900');

        $credit = Invoice::creditNoteFor($invoice, "  Retour d'un portable défectueux  ", $this->now);

        self::assertSame("Retour d'un portable défectueux", $credit->getCreditNoteReason());
        self::assertNull($invoice->getCreditNoteReason());
        $this->assertRefused('creditNoteReason', fn () => Invoice::creditNoteFor($invoice, "  \n ", $this->now), 'a blank reason');
        $this->assertRefused('creditNoteReason', fn () => Invoice::creditNoteFor($invoice, str_repeat('é', Invoice::CREDIT_NOTE_REASON_MAX + 1), $this->now), 'a reason too long');
        self::assertSame(Invoice::CREDIT_NOTE_REASON_MAX, mb_strlen(Invoice::creditNoteFor($invoice, str_repeat('é', Invoice::CREDIT_NOTE_REASON_MAX), $this->now)->getCreditNoteReason() ?? ''), 'the longest reason is kept whole');
    }

    public function testOnlyAnIssuedInvoiceIsCreditedAndACreditNoteStaysWithItsCustomerAndIsNeverPaid(): void
    {
        $today = new \DateTimeImmutable('2026-09-20');
        $draft = Invoice::create($this->company, $this->establishment(), $this->customer($this->company), new InvoiceHeader(), [$this->pieceLine()], [], $this->now);
        $invoice = $this->issued('12.900', '0.000', '12.900');
        $credit = Invoice::creditNoteFor($invoice, 'Retour', $this->now);

        $this->assertRefused('customerId', fn () => $credit->revise($this->establishment(), $this->customer($this->company, 'CLI-0002'), new InvoiceHeader(), [$this->pieceLine()], [], $this->now));
        $credit->issue(new \App\Module\Invoices\Domain\InvoiceIssue('AV-2026-00001', $this->now, 0, 'fr', [], null, null, null), fn (Invoice $i) => $this->figures('-12.900', '0.000', '-12.900'), $this->now);

        foreach ([
            'a draft credited' => fn () => Invoice::creditNoteFor($draft, 'Retour', $this->now),
            'a credit note credited' => fn () => Invoice::creditNoteFor($credit, 'Retour', $this->now),
            'a credit note paid' => fn () => $credit->recordPayment(new PaymentDetails($today, '1', PaymentMethod::Cash), $today, 3, null, $this->now),
        ] as $case => $attempt) {
            try {
                $attempt();
                self::fail("$case");
            } catch (InvoiceTransitionRefused) {
            }
        }
        self::assertSame([], $credit->getPayments());
    }

    public function testACreditTakesItsAmountOffWhatItsInvoiceStillHasDueAtMostAllOfIt(): void
    {
        $invoice = $this->issued(total: '1190.000', withheld: '11.900', due: '1178.100');
        $today = new \DateTimeImmutable('2026-09-20');

        $invoice->credit($this->issuedCreditNote($invoice, '-178.100'), $this->now);

        self::assertSame([InvoiceStatus::PartiallyPaid, '0.000', '1000.000', '178.100'], $this->settlement($invoice), 'credited is no longer only issued');
        foreach ([
            'a draft credit note' => Invoice::creditNoteFor($invoice, 'Retour', $this->now),
            'another invoice\'s credit note' => $this->issuedCreditNote($this->issued('1190.000', '0.000', '1190.000'), '-0.001'),
            'an invoice' => $this->issued('0.001', '0.000', '0.001'),
        ] as $case => $wrong) {
            try {
                $invoice->credit($wrong, $this->now);
                self::fail("$case was credited");
            } catch (\LogicException $refused) {
                self::assertNotInstanceOf(InvalidInvoice::class, $refused, "$case is refused for what it is, not for its amount");
            }
        }
        self::assertSame([InvoiceStatus::PartiallyPaid, '0.000', '1000.000', '178.100'], $this->settlement($invoice));
        $payment = $invoice->recordPayment(new PaymentDetails($today, '1000', PaymentMethod::Transfer), $today, 3, null, $this->now);
        self::assertSame([InvoiceStatus::Paid, '1000.000', '0.000', '178.100'], $this->settlement($invoice));
        $invoice->removePayment($payment, $this->now);
        self::assertSame([InvoiceStatus::PartiallyPaid, '0.000', '1000.000', '178.100'], $this->settlement($invoice), 'deleting a payment keeps what was credited');

        $this->assertRefused('amountDue', fn () => $invoice->credit($this->issuedCreditNote($invoice, '-1000.001'), $this->now));
        self::assertSame([InvoiceStatus::PartiallyPaid, '0.000', '1000.000', '178.100'], $this->settlement($invoice));
        $invoice->credit($this->issuedCreditNote($invoice, '-1000.000'), $this->now);
        self::assertSame([InvoiceStatus::Paid, '0.000', '0.000', '1178.100'], $this->settlement($invoice), 'a credit up to what is due settles the invoice');
    }

    public function testALineKeepsTheDeliveryNoteLineItCameFromAndIssuingNamesThemButACreditNoteCopiesNone(): void
    {
        [$first, $second] = [Uuid::v7(), Uuid::v7()];
        $line = fn (?Uuid $from): InvoiceLineDetails => new InvoiceLineDetails(null, 'Pièce', '1', $this->unit('C62'), '10', null, [], $from);
        $this->assertRefused('lines[1].sourceDeliveryNoteLineId', fn () => Invoice::create($this->company, $this->establishment(), $this->customer($this->company), new InvoiceHeader(), [$line($first), $line($first)], [], $this->now), 'a delivery note line invoiced twice');

        $invoice = Invoice::create($this->company, $this->establishment(), $this->customer($this->company), new InvoiceHeader(), [$line($first), $line(null), $line($second)], [], $this->now);

        self::assertEquals([$first, null, $second], array_map(static fn ($kept): ?Uuid => $kept->getSourceDeliveryNoteLineId(), $invoice->getLines()));
        self::assertSame(['lines'], $invoice->revise($invoice->getEstablishment(), $invoice->getCustomer(), new InvoiceHeader(), [$line($second), $line(null), $line($first)], [], $this->now), 'where a line came from is part of what it says');
        self::assertSame([], $invoice->revise($invoice->getEstablishment(), $invoice->getCustomer(), new InvoiceHeader(), [$line($second), $line(null), $line($first)], [], $this->now));
        $this->assertRefused('lines[2].sourceDeliveryNoteLineId', fn () => $invoice->revise($invoice->getEstablishment(), $invoice->getCustomer(), new InvoiceHeader(), [$line($second), $line(null), $line($second)], [], $this->now));

        $invoice->issue(new \App\Module\Invoices\Domain\InvoiceIssue('FAC-2026-00001', $this->now, 30, 'fr', [], null, null, null), fn (Invoice $i) => $this->figures(lines: 3), $this->now);

        $events = $invoice->releaseEvents();
        self::assertInstanceOf(\App\Module\Invoices\Domain\InvoiceIssued::class, $events[0]);
        self::assertEquals([$second, $first], $events[0]->sourceDeliveryNoteLineIds, 'the delivery note lines its lines came from, in order');
        $credit = Invoice::creditNoteFor($invoice, 'Retour', $this->now);
        self::assertSame([null, null, null], array_map(static fn ($copied): ?Uuid => $copied->getSourceDeliveryNoteLineId(), $credit->getLines()), 'a credit note invoices no delivery note');
    }

    private function issuedCreditNote(Invoice $invoice, string $due): Invoice
    {
        $credit = Invoice::creditNoteFor($invoice, 'Retour', $this->now);
        $credit->issue(new \App\Module\Invoices\Domain\InvoiceIssue('AV-2026-00001', $this->now, 0, 'fr', [], null, null, null), fn (Invoice $i) => $this->figures($due, '0.000', $due), $this->now);

        return $credit;
    }

    /** @return list<list<array{string, string}>> each line's taxes, code and rate */
    private function rates(Invoice $invoice): array
    {
        return array_map(static fn ($line): array => array_map(static fn ($tax): array => [$tax->getCode(), $tax->getRate()], $line->getTaxes()), $invoice->getLines());
    }

    private function issued(string $total, string $withheld, string $due): Invoice
    {
        $invoice = Invoice::create($this->company, $this->establishment(), $this->customer($this->company), new InvoiceHeader(), [$this->pieceLine()], [], $this->now);
        $invoice->issue(new \App\Module\Invoices\Domain\InvoiceIssue('FAC-2026-00001', new \DateTimeImmutable('2026-09-15 08:00:00'), 30, 'fr', [], null, null, null), fn (Invoice $i) => $this->figures($total, $withheld, $due), $this->now);

        return $invoice;
    }

    /** @return array{InvoiceStatus, string, string, string} status, amount paid, amount due, amount credited */
    private function settlement(Invoice $invoice): array
    {
        $figures = $invoice->getIssuedFigures();
        self::assertNotNull($figures);

        return [$invoice->getStatus(), $figures->amountPaid, $figures->amountDue, $figures->amountCredited];
    }

    private function figures(string $total = '12.900', string $withheld = '0.000', string $due = '12.900', int $lines = 1): \App\Module\Invoices\Domain\InvoiceFigures
    {
        return new \App\Module\Invoices\Domain\InvoiceFigures(
            subtotalNet: '10.000',
            documentDiscount: '0.000',
            totalNet: '10.000',
            taxes: [['code' => 'TVA19', 'rate' => '19.000', 'base' => '10.000', 'amount' => '1.900']],
            totalTax: '1.900',
            fixedTaxes: [['code' => 'TIMBRE', 'amount' => '1.000']],
            total: $total,
            withholdings: '0.000' === $withheld ? [] : [['code' => 'RS1', 'rate' => '1.000', 'base' => $total, 'amount' => $withheld]],
            withholdingAmount: $withheld,
            amountDue: $due,
            lines: array_fill(0, $lines, ['net' => '10.000', 'tax' => '1.900', 'gross' => '11.900']),
        );
    }

    /** @param list<TaxComponent> $taxes */
    private function pieceLine(?Product $product = null, ?Unit $unit = null, array $taxes = []): InvoiceLineDetails
    {
        return new InvoiceLineDetails($product, 'Pièce', '1', $unit ?? $this->unit('C62'), '10', null, $taxes);
    }

    /** @param \Closure(): mixed $attempt */
    private function assertRefused(string $field, \Closure $attempt, string $case = ''): void
    {
        try {
            $attempt();
            self::fail("$field accepted $case");
        } catch (InvalidInvoice $refused) {
            self::assertSame($field, $refused->field, $case);
        }
    }

    private function establishment(): Establishment
    {
        return $this->establishments->ofCompany($this->company->getId())[0];
    }

    private function customer(Company $company, string $number = 'CLI-0001'): Customer
    {
        $regime = new CustomerTaxRegime('TN', 'standard', 'fiscal.regime.standard', [], null, 0, $this->now);

        return Customer::create($company, $number, new CustomerProfile(CustomerKind::Company, 'Carthage Conseil'), null, $regime, [], $this->now);
    }

    private function product(Company $company): Product
    {
        return Product::create($company, 'ART-001', new ProductDetails('Portable 14"', null, ProductKind::Goods, '1250'), $this->unit('C62', $company), null, [], $this->now);
    }

    private function unit(string $code, ?Company $company = null): Unit
    {
        $unit = $this->units->ofCodeInCompany($code, ($company ?? $this->company)->getId());
        self::assertNotNull($unit);

        return $unit;
    }

    private function tax(string $code, ?Company $company = null): TaxComponent
    {
        $tax = $this->taxes->ofCodeInCompany($code, ($company ?? $this->company)->getId());
        self::assertNotNull($tax);

        return $tax;
    }
}
