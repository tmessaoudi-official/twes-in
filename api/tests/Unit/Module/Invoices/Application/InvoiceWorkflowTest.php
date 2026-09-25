<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Module\Invoices\Application;

use App\Audit\Application\AuditEntry;
use App\Fiscal\Application\Company\ProvisionCompany;
use App\Fiscal\Application\Regime\ExcludedTaxFamilies;
use App\Fiscal\Domain\CustomerTaxRegime;
use App\Fiscal\Domain\TaxComponent;
use App\Fiscal\Domain\TaxFamily;
use App\Module\Customers\Domain\Customer;
use App\Module\Customers\Domain\CustomerKind;
use App\Module\Customers\Domain\CustomerProfile;
use App\Module\Invoices\Application\InvoiceMentions;
use App\Module\Invoices\Application\InvoiceNotFound;
use App\Module\Invoices\Application\InvoiceTotals;
use App\Module\Invoices\Application\InvoiceWorkflow;
use App\Module\Invoices\Domain\InvalidInvoice;
use App\Module\Invoices\Domain\Invoice;
use App\Module\Invoices\Domain\InvoiceHeader;
use App\Module\Invoices\Domain\InvoiceIssued;
use App\Module\Invoices\Domain\InvoiceLineDetails;
use App\Module\Invoices\Domain\InvoiceLineTax;
use App\Module\Invoices\Domain\InvoiceNotDraft;
use App\Module\Invoices\Domain\InvoiceStatus;
use App\Module\Invoices\Domain\InvoiceTax;
use App\Module\Invoices\Domain\InvoiceType;
use App\Settings\Application\BusinessDefaultSettings;
use App\Settings\Application\ChangeSettings;
use App\Settings\Application\ReadSetting;
use App\Settings\Application\ResolveSettings;
use App\Settings\Application\SettingCatalog;
use App\Settings\Application\SettingContext;
use App\Settings\Domain\SettingLevel;
use App\Tenancy\Application\Numbering\AllocateNumber;
use App\Tenancy\Domain\Company;
use App\Tenancy\Domain\CompanyProfile;
use App\Tests\Support\FakeTransactions;
use App\Tests\Support\InMemoryAuditTrail;
use App\Tests\Support\InMemoryEstablishments;
use App\Tests\Support\InMemoryInvoices;
use App\Tests\Support\InMemoryNumberingSeries;
use App\Tests\Support\InMemorySettings;
use App\Tests\Support\InMemoryTaxComponents;
use App\Tests\Support\InMemoryUnits;
use App\Tests\Support\RecordingDomainEvents;
use App\Tests\Support\ShippedFiscalPresets;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

final class InvoiceWorkflowTest extends TestCase
{
    private MockClock $clock;
    private InMemoryUnits $units;
    private InMemoryTaxComponents $taxes;
    private InMemoryEstablishments $establishments;
    private InMemoryNumberingSeries $series;
    private InMemoryInvoices $invoices;
    private InMemoryAuditTrail $audit;
    private FakeTransactions $transactions;
    private RecordingDomainEvents $events;
    private ChangeSettings $change;
    private InvoiceWorkflow $workflow;
    private Company $company;
    private Company $globex;

    protected function setUp(): void
    {
        // Half past eleven at night in UTC is already the 15th in Tunis: the company's day is the one that counts.
        $this->clock = new MockClock('2026-09-14 23:30:00', 'UTC');
        $this->units = new InMemoryUnits();
        $this->taxes = new InMemoryTaxComponents();
        $this->establishments = new InMemoryEstablishments();
        $this->series = new InMemoryNumberingSeries();
        $provision = new ProvisionCompany(ShippedFiscalPresets::presets(), $this->taxes, $this->units, $this->establishments, $this->series, ShippedFiscalPresets::scales(), $this->clock);
        $provision->handle($this->company = new Company('Acme', 'TN', 'TND', 'fr', 'Africa/Tunis'));
        $provision->handle($this->globex = new Company('Globex', 'TN', 'TND', 'fr', 'Africa/Tunis'));
        $this->invoices = new InMemoryInvoices();
        $this->transactions = new FakeTransactions();
        $this->audit = new InMemoryAuditTrail($this->transactions);
        $this->events = new RecordingDomainEvents($this->transactions);
        $settings = new InMemorySettings();
        $catalog = new SettingCatalog([new BusinessDefaultSettings()]);
        $resolve = new ResolveSettings($catalog, $settings);
        $this->change = new ChangeSettings($catalog, $settings, $resolve, new InMemoryAuditTrail($settingTransactions = new FakeTransactions()), $this->clock, $settingTransactions);
        $this->workflow = new InvoiceWorkflow(
            $this->invoices,
            new AllocateNumber($this->series, $this->transactions, $this->clock),
            $this->transactions,
            new InvoiceTotals(ShippedFiscalPresets::presets(), ShippedFiscalPresets::scales()),
            new InvoiceMentions(ShippedFiscalPresets::presets(), new ExcludedTaxFamilies(ShippedFiscalPresets::presets())),
            new ReadSetting($resolve),
            $this->events,
            $this->audit,
            $this->clock,
        );
    }

    public function testIssuingNumbersTheInvoiceWithWhatItsCustomerAndCompanySayAndTellsOnceItIsStored(): void
    {
        $customer = $this->customer('export', 'fiscal.mention.tn.export');
        $atCustomer = new SettingContext($this->company, customerId: $customer->getId());
        $this->change->change($atCustomer, 'document.language', SettingLevel::Customer, 'en', null);
        $this->change->change($atCustomer, 'document.payment_terms_days', SettingLevel::Customer, 60, null);
        $this->company->reviseProfile(new CompanyProfile(invoiceFooterText: 'Merci de votre confiance', latePenaltyText: 'Pénalité de retard : 1 % par mois'));
        $invoice = $this->draft($customer);
        $actor = Uuid::v7();

        self::assertSame($invoice, $this->workflow->issue($this->company, $invoice->getId(), $actor));

        self::assertSame([InvoiceStatus::Issued, 'FAC-2026-00001', '2026-09-15', '2026-11-14', 60], [$invoice->getStatus(), $invoice->getNumber(), $invoice->getIssueDate()?->format('Y-m-d'), $invoice->getDueDate()?->format('Y-m-d'), $invoice->getHeader()->paymentTermsDays]);
        self::assertSame(['en', ['fiscal.mention.tn.export'], 'Pénalité de retard : 1 % par mois', 'Merci de votre confiance'], [$invoice->getLanguage(), $invoice->getMentionKeys(), $invoice->getLatePenaltyText(), $invoice->getFooter()]);
        self::assertSame(['10.000', '1.000', '11.000', '11.000'], [$invoice->getIssuedFigures()?->totalNet, $invoice->getIssuedFigures()?->fixedTaxes[0]['amount'], $invoice->getIssuedFigures()?->total, $invoice->getIssuedFigures()?->amountDue], 'an export customer pays no VAT, only the stamp');
        self::assertTrue($actor->equals($invoice->getIssuedBy()));
        self::assertSame(1, $this->transactions->committed);
        $entry = $this->audit->entries[0];
        self::assertSame(
            ['invoice', $invoice->getId(), 'invoice.issued', $actor, ['number' => 'FAC-2026-00001'], $this->company->getId()],
            [$entry->entityType, $entry->entityId, $entry->action, $entry->actorUserId, $entry->changes, $entry->companyId],
        );
        self::assertCount(1, $this->events->published);
        $event = $this->events->published[0];
        self::assertInstanceOf(InvoiceIssued::class, $event);
        self::assertSame([$invoice->getId(), InvoiceType::Invoice, 'FAC-2026-00001', '2026-09-15'], [$event->invoiceId, $event->type, $event->number, $event->issueDate->format('Y-m-d')]);
        self::assertSame([false], $this->events->whileInTransaction, 'published once the transaction is over');

        self::assertSame('FAC-2026-00002', $this->workflow->issue($this->company, $this->draft($customer)->getId(), null)->getNumber());
    }

    public function testIssuingACreditNoteNumbersItInItsOwnSeriesAndTakesItOffItsInvoiceInTheSameTransaction(): void
    {
        $invoice = $this->workflow->issue($this->company, $this->draft($this->customer('standard', null))->getId(), null);
        self::assertSame('12.900', $invoice->getIssuedFigures()?->amountDue);
        $credit = Invoice::creditNoteFor($invoice, $this->clock->now());
        $this->invoices->save($credit);
        $actor = Uuid::v7();

        self::assertSame($credit, $this->workflow->issue($this->company, $credit->getId(), $actor));

        self::assertSame([InvoiceStatus::Issued, 'AV-2026-00001', '-12.900'], [$credit->getStatus(), $credit->getNumber(), $credit->getIssuedFigures()?->amountDue]);
        self::assertSame([InvoiceStatus::Paid, '12.900', '0.000'], [$invoice->getStatus(), $invoice->getIssuedFigures()->amountCredited, $invoice->getIssuedFigures()->amountDue]);
        self::assertSame(2, $this->transactions->committed);
        self::assertSame([
            ['invoice', $credit->getId(), 'invoice.issued', $actor, ['number' => 'AV-2026-00001'], $this->company->getId()],
            ['invoice', $invoice->getId(), 'invoice.credited', $actor, ['creditNoteId' => $credit->getId()->toRfc4122(), 'number' => 'AV-2026-00001', 'amount' => '12.900'], $this->company->getId()],
        ], array_map(static fn (AuditEntry $entry): array => [$entry->entityType, $entry->entityId, $entry->action, $entry->actorUserId, $entry->changes, $entry->companyId], \array_slice($this->audit->entries, 1)));
        $event = $this->events->published[1];
        self::assertInstanceOf(InvoiceIssued::class, $event);
        self::assertSame([$credit->getId(), InvoiceType::CreditNote, 'AV-2026-00001'], [$event->invoiceId, $event->type, $event->number]);

        $again = Invoice::creditNoteFor($invoice, $this->clock->now());
        $this->invoices->save($again);
        try {
            $this->workflow->issue($this->company, $again->getId(), $actor);
            self::fail('a credit note above what its invoice still has due was issued');
        } catch (InvalidInvoice $refused) {
            self::assertSame('amountDue', $refused->field);
        }
        self::assertSame([InvoiceStatus::Draft, null, '12.900', 3, 2], [$again->getStatus(), $again->getNumber(), $invoice->getIssuedFigures()->amountCredited, \count($this->audit->entries), \count($this->events->published)]);
    }

    public function testACreditNoteOfAnInvoiceThatWithheldWithholdsAtItsRateWhateverItsOwnTotal(): void
    {
        // 1000 net, VAT 190: 1190 reaches the RS1 threshold, so 11.900 is withheld; the stamp adds 1.000 outside it.
        $invoice = $this->workflow->issue($this->company, $this->withholdingDraft(['500', '500'])->getId(), null);
        self::assertSame(['1191.000', '11.900', '1179.100'], [$invoice->getIssuedFigures()?->total, $invoice->getIssuedFigures()?->withholdingAmount, $invoice->getIssuedFigures()?->amountDue]);

        // 595 of its own is under the threshold; the credit note withholds because its invoice did.
        $first = $this->workflow->issue($this->company, $this->creditKeeping($invoice, 0, true)->getId(), null);
        self::assertSame([[['RS1', '-5.950']], '-590.050'], [array_map(static fn (array $held): array => [$held['code'], $held['amount']], $first->getIssuedFigures()->withholdings ?? []), $first->getIssuedFigures()?->amountDue]);
        self::assertSame([InvoiceStatus::PartiallyPaid, '589.050'], [$invoice->getStatus(), $invoice->getIssuedFigures()?->amountDue]);

        // The second leaves the stamp out, which every partial credit note copies (docs/SPEC.md § 8, known issues).
        $second = $this->workflow->issue($this->company, $this->creditKeeping($invoice, 1, false)->getId(), null);
        self::assertSame('-589.050', $second->getIssuedFigures()?->amountDue);
        self::assertSame([InvoiceStatus::Paid, '0.000'], [$invoice->getStatus(), $invoice->getIssuedFigures()?->amountDue], 'a credit note of every line closes the invoice');
    }

    public function testTheLastCreditNoteAbsorbsWhatTheOthersRoundedSoTwoHalvesCloseTheInvoice(): void
    {
        // 1010 net, VAT 191.900: 1 % of 1201.900 is 12.019, while each half of 600.950 comes to 6.0095 on its own.
        $invoice = $this->workflow->issue($this->company, $this->withholdingDraft(['505', '505'])->getId(), null);
        self::assertSame('1190.881', $invoice->getIssuedFigures()?->amountDue);

        // The first leaves part of the invoice's base uncredited, so it rounds its own share away, as any does.
        $first = $this->workflow->issue($this->company, $this->creditKeeping($invoice, 0, true)->getId(), null);
        self::assertSame([[['RS1', '-6.010']], '-595.940'], [$this->held($first), $first->getIssuedFigures()?->amountDue]);

        // The second completes that base, so it withholds what is left of the invoice's 12.019, not 1 % of itself.
        $second = $this->workflow->issue($this->company, $this->creditKeeping($invoice, 1, false)->getId(), null);
        self::assertSame([[['RS1', '-6.009']], '-594.941'], [$this->held($second), $second->getIssuedFigures()?->amountDue]);

        self::assertSame([InvoiceStatus::Paid, '0.000', '1190.881'], [$invoice->getStatus(), $invoice->getIssuedFigures()->amountDue, $invoice->getIssuedFigures()->amountCredited], 'the credit notes of a withheld invoice add up to it exactly');
    }

    /**
     * What a document withheld, as code and amount.
     *
     * @return list<array{0: string, 1: string}>
     */
    private function held(Invoice $document): array
    {
        return array_map(static fn (array $each): array => [$each['code'], $each['amount']], $document->getIssuedFigures()->withholdings ?? []);
    }

    public function testACreditNoteOfAnInvoiceThatWithheldNothingWithholdsNothing(): void
    {
        $invoice = $this->workflow->issue($this->company, $this->withholdingDraft(['100'])->getId(), null);
        self::assertSame([], $invoice->getIssuedFigures()?->withholdings, '119 is under the threshold');

        // A credit note takes lines of its own: above the threshold alone, it still withholds as its invoice did, not at all.
        $credit = Invoice::creditNoteFor($invoice, $this->clock->now());
        $line = $invoice->getLines()[0];
        $credit->revise($credit->getEstablishment(), $credit->getCustomer(), $credit->getHeader(), [
            new InvoiceLineDetails(null, 'Pièce', '1', $line->getUnit(), '2000', null, array_map(static fn (InvoiceLineTax $tax): TaxComponent => $tax->getTaxComponent(), $line->getTaxes())),
        ], array_map(static fn (InvoiceTax $tax): TaxComponent => $tax->getTaxComponent(), $credit->getDocumentTaxes()), $this->clock->now());

        self::assertSame([], new InvoiceTotals(ShippedFiscalPresets::presets(), ShippedFiscalPresets::scales())->of($credit)->withholdings);
    }

    public function testARequestThatReadTheDraftBeforeAnotherIssuedItIsRefusedOnceItHoldsTheInvoice(): void
    {
        $draft = $this->draft($this->customer('standard', null));
        $readAsDraft = clone $draft;
        $this->workflow->issue($this->company, $draft->getId(), null);
        $this->invoices->staleReads[$draft->getId()->toRfc4122()] = $readAsDraft;

        try {
            $this->workflow->issue($this->company, $draft->getId(), null);
            self::fail('an issued invoice was issued again from a copy read while it was a draft');
        } catch (InvoiceNotDraft) {
        }

        self::assertSame([InvoiceStatus::Issued, 'FAC-2026-00001'], [$draft->getStatus(), $draft->getNumber()]);
        self::assertSame([1, 1], [\count($this->audit->entries), \count($this->events->published)]);
        $next = $this->workflow->issue($this->company, $this->draft($this->customer('standard', null))->getId(), null);
        self::assertSame('FAC-2026-00002', $next->getNumber(), 'the refused attempt took no number');
    }

    public function testACreditNoteIssuedTwiceAtOnceCreditsItsInvoiceOnce(): void
    {
        $invoice = $this->workflow->issue($this->company, $this->draft($this->customer('standard', null))->getId(), null);
        $credit = Invoice::creditNoteFor($invoice, $this->clock->now());
        $this->invoices->save($credit);
        $readAsDraft = clone $credit;
        $this->workflow->issue($this->company, $credit->getId(), null);
        $this->invoices->staleReads[$credit->getId()->toRfc4122()] = $readAsDraft;

        try {
            $this->workflow->issue($this->company, $credit->getId(), null);
            self::fail('an issued credit note was issued again from a copy read while it was a draft');
        } catch (InvoiceNotDraft) {
        }

        self::assertSame(['AV-2026-00001', '12.900', '0.000'], [$credit->getNumber(), $invoice->getIssuedFigures()?->amountCredited, $invoice->getIssuedFigures()?->amountDue]);
    }

    public function testTermsTheDraftStatesWinOverTheCustomersAndAStandardCustomerPrintsNoMention(): void
    {
        $customer = $this->customer('standard', null);
        $this->change->change(new SettingContext($this->company, customerId: $customer->getId()), 'document.payment_terms_days', SettingLevel::Customer, 60, null);

        $invoice = $this->workflow->issue($this->company, $this->draft($customer, new InvoiceHeader(paymentTermsDays: 0))->getId(), null);

        self::assertSame([0, '2026-09-15', 'fr', [], null, null], [$invoice->getHeader()->paymentTermsDays, $invoice->getDueDate()?->format('Y-m-d'), $invoice->getLanguage(), $invoice->getMentionKeys(), $invoice->getLatePenaltyText(), $invoice->getFooter()]);
    }

    public function testWhatCannotBeIssuedStaysADraftAndTellsNobody(): void
    {
        $customer = $this->customer('standard', null);
        $empty = $this->draft($customer, lines: []);
        try {
            $this->workflow->issue($this->company, $empty->getId(), null);
            self::fail('an invoice without a line was issued');
        } catch (InvalidInvoice $refused) {
            self::assertSame('lines', $refused->field);
        }

        $invoice = $this->draft($customer);
        try {
            $this->workflow->issue($this->globex, $invoice->getId(), null);
            self::fail('another company issued the invoice');
        } catch (InvoiceNotFound) {
        }

        self::assertSame([[InvoiceStatus::Draft, null], [InvoiceStatus::Draft, null]], [[$empty->getStatus(), $empty->getNumber()], [$invoice->getStatus(), $invoice->getNumber()]]);
        self::assertSame([[], [], 0], [$this->audit->entries, $this->events->published, $this->transactions->committed]);
    }

    /** @param list<InvoiceLineDetails>|null $lines */
    private function draft(Customer $customer, ?InvoiceHeader $header = null, ?array $lines = null): Invoice
    {
        $unit = $this->units->ofCodeInCompany('C62', $this->company->getId());
        $vat = $this->taxes->ofCodeInCompany('TVA19', $this->company->getId());
        $stamp = $this->taxes->ofCodeInCompany('TIMBRE', $this->company->getId());
        self::assertNotNull($unit);
        self::assertNotNull($vat);
        self::assertNotNull($stamp);
        $establishment = $this->establishments->ofCompany($this->company->getId())[0];
        $taxes = [] === $customer->getTaxRegime()->getExcludedFamilies() ? [$vat] : [];
        $invoice = Invoice::create($this->company, $establishment, $customer, $header ?? new InvoiceHeader(), $lines ?? [new InvoiceLineDetails(null, 'Pièce', '1', $unit, '10', null, $taxes)], [$stamp], $this->clock->now());
        $this->invoices->save($invoice);

        return $invoice;
    }

    /**
     * A draft of one line at each price, VAT 19 %, carrying the stamp and the withholding.
     *
     * @param list<string> $prices
     */
    private function withholdingDraft(array $prices): Invoice
    {
        $unit = $this->units->ofCodeInCompany('C62', $this->company->getId());
        $vat = $this->taxes->ofCodeInCompany('TVA19', $this->company->getId());
        $stamp = $this->taxes->ofCodeInCompany('TIMBRE', $this->company->getId());
        $withholding = $this->taxes->ofCodeInCompany('RS1', $this->company->getId());
        self::assertNotNull($unit);
        self::assertNotNull($vat);
        self::assertNotNull($stamp);
        self::assertNotNull($withholding);
        $lines = array_map(static fn (string $price): InvoiceLineDetails => new InvoiceLineDetails(null, 'Pièce', '1', $unit, $price, null, [$vat]), $prices);
        $invoice = Invoice::create($this->company, $this->establishments->ofCompany($this->company->getId())[0], $this->customer('standard', null), new InvoiceHeader(), $lines, [$stamp, $withholding], $this->clock->now());
        $this->invoices->save($invoice);

        return $invoice;
    }

    /** A draft credit note of one of its invoice's lines, keeping the stamp or leaving it out. */
    private function creditKeeping(Invoice $invoice, int $index, bool $stamp): Invoice
    {
        $credit = Invoice::creditNoteFor($invoice, $this->clock->now());
        $line = $invoice->getLines()[$index];
        $taxes = array_values(array_filter(
            array_map(static fn (InvoiceTax $tax): TaxComponent => $tax->getTaxComponent(), $credit->getDocumentTaxes()),
            static fn (TaxComponent $tax): bool => $stamp || 'TIMBRE' !== $tax->getCode(),
        ));
        $credit->revise($credit->getEstablishment(), $credit->getCustomer(), $credit->getHeader(), [
            new InvoiceLineDetails($line->getProduct(), $line->getDescription(), $line->getQuantity(), $line->getUnit(), $line->getUnitPriceNet(), $line->getDiscountRate(), array_map(static fn (InvoiceLineTax $tax): TaxComponent => $tax->getTaxComponent(), $line->getTaxes())),
        ], $taxes, $this->clock->now());
        $this->invoices->save($credit);

        return $credit;
    }

    private function customer(string $regime, ?string $mentionKey): Customer
    {
        $now = $this->clock->now();
        $families = null === $mentionKey ? [] : [TaxFamily::Vat];

        return Customer::create($this->company, 'CLI-0001', new CustomerProfile(CustomerKind::Company, 'Carthage Conseil'), null, new CustomerTaxRegime('TN', $regime, 'fiscal.regime.'.$regime, $families, $mentionKey, 0, $now), [], $now);
    }
}
