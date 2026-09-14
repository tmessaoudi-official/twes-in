<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Module\DeliveryNotes\Domain;

use App\Files\Domain\StoredFile;
use App\Fiscal\Application\Company\ProvisionCompany;
use App\Fiscal\Domain\CustomerTaxRegime;
use App\Fiscal\Domain\TaxComponent;
use App\Fiscal\Domain\Unit;
use App\Module\Customers\Domain\Customer;
use App\Module\Customers\Domain\CustomerKind;
use App\Module\Customers\Domain\CustomerProfile;
use App\Module\DeliveryNotes\Domain\DeliveredQuantity;
use App\Module\DeliveryNotes\Domain\DeliveryNote;
use App\Module\DeliveryNotes\Domain\DeliveryNoteCancelled;
use App\Module\DeliveryNotes\Domain\DeliveryNoteHeader;
use App\Module\DeliveryNotes\Domain\DeliveryNoteLineDetails;
use App\Module\DeliveryNotes\Domain\DeliveryNoteLineTax;
use App\Module\DeliveryNotes\Domain\DeliveryNoteNotDraft;
use App\Module\DeliveryNotes\Domain\DeliveryNoteStatus;
use App\Module\DeliveryNotes\Domain\DeliveryNoteTransitionRefused;
use App\Module\DeliveryNotes\Domain\DeliveryNoteValidated;
use App\Module\DeliveryNotes\Domain\InvalidDeliveryNote;
use App\Module\Products\Domain\Product;
use App\Module\Products\Domain\ProductDetails;
use App\Module\Products\Domain\ProductKind;
use App\Shared\Domain\PostalAddress;
use App\Tenancy\Domain\Company;
use App\Tenancy\Domain\Establishment;
use App\Tests\Support\InMemoryEstablishments;
use App\Tests\Support\InMemoryNumberingSeries;
use App\Tests\Support\InMemoryTaxComponents;
use App\Tests\Support\InMemoryUnits;
use App\Tests\Support\ShippedFiscalPresets;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

final class DeliveryNoteTest extends TestCase
{
    private \DateTimeImmutable $now;
    private InMemoryUnits $units;
    private InMemoryTaxComponents $taxes;
    private InMemoryEstablishments $establishments;
    private Company $company;
    private Company $globex;
    private Customer $customer;

    protected function setUp(): void
    {
        $clock = new MockClock('2026-09-15 09:00:00');
        $this->now = $clock->now();
        $this->units = new InMemoryUnits();
        $this->taxes = new InMemoryTaxComponents();
        $this->establishments = new InMemoryEstablishments();
        $provision = new ProvisionCompany(ShippedFiscalPresets::presets(), $this->taxes, $this->units, $this->establishments, new InMemoryNumberingSeries(), ShippedFiscalPresets::scales(), $clock);
        $this->company = new Company('Acme', 'TN', 'TND', 'fr', 'Africa/Tunis');
        $this->globex = new Company('Globex', 'TN', 'TND', 'fr', 'Africa/Tunis');
        $provision->handle($this->company);
        $provision->handle($this->globex);
        $this->customer = $this->customer($this->company);
    }

    public function testADraftHoldsItsLinesInOrderWithTheRatesItsTaxesHaveToday(): void
    {
        $product = $this->product($this->company);

        $note = DeliveryNote::create($this->company, $this->establishment(), $this->customer, new DeliveryNoteHeader(customerReference: 'PO-77'), [
            new DeliveryNoteLineDetails($product, 'Portable 14"', '3', $this->unit('C62'), '1250.5', [$this->tax('FODEC'), $this->tax('TVA19')]),
            new DeliveryNoteLineDetails(null, 'Installation', '1.5', $this->unit('HUR'), '80', []),
        ], $this->now);

        self::assertSame(DeliveryNoteStatus::Draft, $note->getStatus());
        self::assertNull($note->getNumber());
        self::assertSame('PO-77', $note->getHeader()->customerReference);
        [$first, $second] = $note->getLines();
        self::assertSame([1, $product, 'Portable 14"', '3.000', 'C62', '1250.5000'], [$first->getPosition(), $first->getProduct(), $first->getDescription(), $first->getQuantity(), $first->getUnit()->getCode(), $first->getUnitPriceNet()]);
        self::assertSame(
            [['FODEC', '1.000', true], ['TVA19', '19.000', false]],
            array_map(static fn (DeliveryNoteLineTax $tax): array => [$tax->getCode(), $tax->getRate(), $tax->entersVatBase()], $first->getTaxes()),
        );
        self::assertSame([2, null, '1.500', 'HUR', '80.0000', []], [$second->getPosition(), $second->getProduct(), $second->getQuantity(), $second->getUnit()->getCode(), $second->getUnitPriceNet(), $second->getTaxes()]);
    }

    public function testAQuantityIsPositiveAndNoFinerThanItsUnitCounts(): void
    {
        foreach (['0', '-1', '1.5', 'three', '1,0'] as $quantity) {
            $this->assertRefused('quantity', fn () => new DeliveryNoteLineDetails(null, 'Pièce', $quantity, $this->unit('C62'), '10', []), $quantity);
        }
        $this->assertRefused('quantity', fn () => new DeliveryNoteLineDetails(null, 'Pose', '1.255', $this->unit('HUR'), '10', []));

        self::assertSame('1.250', new DeliveryNoteLineDetails(null, 'Pose', '1.25', $this->unit('HUR'), '10', [])->quantity);
        self::assertSame('0.125', new DeliveryNoteLineDetails(null, 'Câble', '0.125', $this->unit('MTR'), '10', [])->quantity);
    }

    public function testAPriceIsNeverNegativeAndCarriesAtMostFourDecimals(): void
    {
        foreach (['-1', '1.23456', '', '12,5'] as $price) {
            $this->assertRefused('unitPriceNet', fn () => new DeliveryNoteLineDetails(null, 'Pièce', '1', $this->unit('C62'), $price, []), $price);
        }

        self::assertSame('0.0000', new DeliveryNoteLineDetails(null, 'Échantillon', '1', $this->unit('C62'), '0', [])->unitPriceNet);
    }

    public function testALineSaysWhatItIs(): void
    {
        $this->assertRefused('description', fn () => new DeliveryNoteLineDetails(null, '  ', '1', $this->unit('C62'), '1', []));
        $this->assertRefused('description', fn () => new DeliveryNoteLineDetails(null, str_repeat('a', DeliveryNoteLineDetails::DESCRIPTION_MAX + 1), '1', $this->unit('C62'), '1', []));
    }

    public function testALineCarriesEachOfItsLineTaxesOnce(): void
    {
        $this->assertRefused('taxComponentIds', fn () => new DeliveryNoteLineDetails(null, 'Pièce', '1', $this->unit('C62'), '1', [$this->tax('TIMBRE')]), 'a stamp is charged on the document');
        $this->assertRefused('taxComponentIds', fn () => new DeliveryNoteLineDetails(null, 'Pièce', '1', $this->unit('C62'), '1', [$this->tax('RS1')]), 'a withholding is charged on the document');
        $this->assertRefused('taxComponentIds', fn () => new DeliveryNoteLineDetails(null, 'Pièce', '1', $this->unit('C62'), '1', [$this->tax('TVA19'), $this->tax('TVA19')]));
    }

    public function testAHeaderKeepsItsTextsWithinBounds(): void
    {
        $this->assertRefused('customerReference', static fn () => new DeliveryNoteHeader(customerReference: str_repeat('a', DeliveryNoteHeader::REFERENCE_MAX + 1)));
        $this->assertRefused('remarksPrinted', static fn () => new DeliveryNoteHeader(remarksPrinted: str_repeat('a', DeliveryNoteHeader::TEXT_MAX + 1)));
        $this->assertRefused('notesInternal', static fn () => new DeliveryNoteHeader(notesInternal: str_repeat('a', DeliveryNoteHeader::TEXT_MAX + 1)));

        $header = new DeliveryNoteHeader(customerReference: '  ', remarksPrinted: '', notesInternal: ' Fragile ');
        self::assertSame([null, null, 'Fragile'], [$header->customerReference, $header->remarksPrinted, $header->notesInternal]);
    }

    public function testEverythingANoteNamesBelongsToItsCompany(): void
    {
        $theirs = $this->establishments->ofCompany($this->globex->getId())[0];
        $this->assertRefused('establishmentId', fn () => DeliveryNote::create($this->company, $theirs, $this->customer, new DeliveryNoteHeader(), [], $this->now));
        $this->assertRefused('customerId', fn () => DeliveryNote::create($this->company, $this->establishment(), $this->customer($this->globex), new DeliveryNoteHeader(), [], $this->now));
        $this->assertRefused('lines[0].productId', fn () => DeliveryNote::create($this->company, $this->establishment(), $this->customer, new DeliveryNoteHeader(), [
            new DeliveryNoteLineDetails($this->product($this->globex), 'Portable', '1', $this->unit('C62'), '1', []),
        ], $this->now));
        $this->assertRefused('lines[1].unitId', fn () => DeliveryNote::create($this->company, $this->establishment(), $this->customer, new DeliveryNoteHeader(), [
            new DeliveryNoteLineDetails(null, 'Pièce', '1', $this->unit('C62'), '1', []),
            new DeliveryNoteLineDetails(null, 'Pièce', '1', $this->unit('C62', $this->globex), '1', []),
        ], $this->now));
        $this->assertRefused('lines[0].taxComponentIds', fn () => DeliveryNote::create($this->company, $this->establishment(), $this->customer, new DeliveryNoteHeader(), [
            new DeliveryNoteLineDetails(null, 'Pièce', '1', $this->unit('C62'), '1', [$this->tax('TVA19', $this->globex)]),
        ], $this->now));
    }

    public function testARevisionNamesTheFieldsItChangedAndLeavesAnIdenticalNoteAlone(): void
    {
        $lines = [new DeliveryNoteLineDetails(null, 'Pièce', '2', $this->unit('C62'), '10', [$this->tax('TVA19')])];
        $note = DeliveryNote::create($this->company, $this->establishment(), $this->customer, new DeliveryNoteHeader(), $lines, $this->now);
        $later = $this->now->modify('+1 hour');

        self::assertSame([], $note->revise($this->establishment(), $this->customer, new DeliveryNoteHeader(), [new DeliveryNoteLineDetails(null, 'Pièce', '2.000', $this->unit('C62'), '10.0000', [$this->tax('TVA19')])], $later));
        self::assertEquals($this->now, $note->getUpdatedAt());
        $firstLine = $note->getLines()[0];

        $other = $this->customer($this->company, 'CLI-0002');
        $changed = $note->revise($this->establishment(), $other, new DeliveryNoteHeader(new \DateTimeImmutable('2026-09-20'), new PostalAddress('Rue de Marseille', null, '1000', 'Tunis', 'TN'), 'PO-78'), [
            new DeliveryNoteLineDetails(null, 'Pièce', '3', $this->unit('C62'), '10', [$this->tax('TVA19')]),
        ], $later);

        self::assertSame(['customerId', 'deliveryDate', 'deliveryAddress', 'customerReference', 'lines'], $changed);
        self::assertEquals($later, $note->getUpdatedAt());
        self::assertSame('3.000', $note->getLines()[0]->getQuantity());
        self::assertNotSame($firstLine, $note->getLines()[0], 'changed lines are written again');
        self::assertSame('2026-09-20', $note->getHeader()->deliveryDate?->format('Y-m-d'));
    }

    public function testValidationNumbersTheNoteAndFreezesWhatItsCustomerAndItsTaxesWereThatDay(): void
    {
        $billing = new PostalAddress('Rue de Rome', null, '1000', 'Tunis', 'TN');
        $customer = Customer::create($this->company, 'CLI-0009', new CustomerProfile(CustomerKind::Company, 'Carthage Conseil', 'Carthage Conseil SARL', ['matricule_fiscal' => '1234567APM000'], billingAddress: $billing), null, new CustomerTaxRegime('TN', 'standard', 'fiscal.regime.standard', [], 'fiscal.mention.standard', 0, $this->now), [], $this->now);
        $product = $this->product($this->company);
        $note = DeliveryNote::create($this->company, $this->establishment(), $customer, new DeliveryNoteHeader(), [
            new DeliveryNoteLineDetails($product, 'Portable', '2', $this->unit('C62'), '10', [$this->tax('TVA19')]),
            new DeliveryNoteLineDetails(null, 'Pose', '1.5', $this->unit('HUR'), '40', []),
        ], $this->now);
        $vat = $this->tax('TVA19');
        $vat->revise($vat->getName(), '18', null, null, false, $vat->isDefault(), true, $vat->getExemptionMention(), $vat->getSortOrder(), 3, $this->now);
        $later = $this->now->modify('+1 hour');

        $note->validate('BL-2026-00001', new \DateTimeImmutable('2026-09-15 00:00:00', new \DateTimeZone('UTC')), $later);

        self::assertSame([DeliveryNoteStatus::Validated, 'BL-2026-00001', '2026-09-15'], [$note->getStatus(), $note->getNumber(), $note->getIssueDate()?->format('Y-m-d')]);
        self::assertEquals($later, $note->getUpdatedAt());
        self::assertSame('18.000', $note->getLines()[0]->getTaxes()[0]->getRate(), 'a validated note carries the rates of its issue day');
        self::assertSame($billing->parts(), $note->getHeader()->deliveryAddress->parts(), 'goods go to the billing address when neither the note nor the customer names another');
        $customer->revise('CLI-0010', new CustomerProfile(CustomerKind::Individual, 'Renamed'), null, $customer->getTaxRegime(), [], true, $later);
        $snapshot = $note->getCustomerSnapshot();
        self::assertNotNull($snapshot);
        self::assertSame(
            ['CLI-0009', 'company', 'Carthage Conseil', 'Carthage Conseil SARL', ['matricule_fiscal' => '1234567APM000'], $billing->parts(), 'standard', 'fiscal.mention.standard'],
            [$snapshot->number, $snapshot->kind, $snapshot->name, $snapshot->legalName, $snapshot->identifiers, $snapshot->billingAddress->parts(), $snapshot->taxRegimeCode, $snapshot->taxMentionKey],
            'what the customer was called the day the note was validated',
        );

        $events = $note->releaseEvents();
        self::assertCount(1, $events);
        $validated = $events[0];
        self::assertInstanceOf(DeliveryNoteValidated::class, $validated);
        self::assertSame(
            [$note->getId(), $this->company->getId(), $this->establishment()->getId(), 'BL-2026-00001', '2026-09-15'],
            [$validated->deliveryNoteId, $validated->companyId, $validated->establishmentId, $validated->number, $validated->issueDate->format('Y-m-d')],
        );
        self::assertEquals([
            new DeliveredQuantity($product->getId(), '2.000', $this->unit('C62')->getId()),
            new DeliveredQuantity(null, '1.500', $this->unit('HUR')->getId()),
        ], $validated->lines);
        self::assertSame([], $note->releaseEvents(), 'an event is released once');

        $this->expectException(DeliveryNoteNotDraft::class);
        $note->validate('BL-2026-00002', new \DateTimeImmutable('2026-09-15'), $later);
    }

    public function testANoteIsValidatedWithALineAndGoesWhereItOrItsCustomerSays(): void
    {
        $empty = DeliveryNote::create($this->company, $this->establishment(), $this->customer, new DeliveryNoteHeader(), [], $this->now);
        $this->assertRefused('lines', fn () => $empty->validate('BL-2026-00001', new \DateTimeImmutable('2026-09-15'), $this->now));
        self::assertSame([DeliveryNoteStatus::Draft, null, null, []], [$empty->getStatus(), $empty->getNumber(), $empty->getCustomerSnapshot(), $empty->releaseEvents()]);

        $shipping = new PostalAddress('Zone industrielle', null, '3000', 'Sfax', 'TN');
        $customer = Customer::create($this->company, 'CLI-0002', new CustomerProfile(CustomerKind::Company, 'Sfax Négoce', billingAddress: new PostalAddress('Avenue Bourguiba', null, '1000', 'Tunis', 'TN'), shippingAddress: $shipping), null, new CustomerTaxRegime('TN', 'standard', 'fiscal.regime.standard', [], null, 0, $this->now), [], $this->now);
        $toTheWarehouse = $this->validated($customer, new DeliveryNoteHeader());
        self::assertSame($shipping->parts(), $toTheWarehouse->getHeader()->deliveryAddress->parts());

        $site = new PostalAddress('Chantier Lac 2', null, '1053', 'Tunis', 'TN');
        self::assertSame($site->parts(), $this->validated($customer, new DeliveryNoteHeader(deliveryAddress: $site))->getHeader()->deliveryAddress->parts());
    }

    public function testAValidatedNoteIsDeliveredOnADayFromItsIssueToTodayAndThenNeverCancelled(): void
    {
        $note = $this->validated($this->customer, new DeliveryNoteHeader(new \DateTimeImmutable('2026-09-30')));
        $today = new \DateTimeImmutable('2026-09-16 00:00:00', new \DateTimeZone('UTC'));
        $this->assertRefused('deliveredOn', fn () => $note->deliver(new \DateTimeImmutable('2026-09-14'), $today, $this->now), 'before the issue day');
        $this->assertRefused('deliveredOn', fn () => $note->deliver(new \DateTimeImmutable('2026-09-17'), $today, $this->now), 'after today');
        self::assertSame(DeliveryNoteStatus::Validated, $note->getStatus());
        $later = $this->now->modify('+1 day');

        $note->deliver(new \DateTimeImmutable('2026-09-15 18:30:00', new \DateTimeZone('UTC')), $today, $later);

        self::assertSame([DeliveryNoteStatus::Delivered, '2026-09-15 00:00:00'], [$note->getStatus(), $note->getHeader()->deliveryDate?->format('Y-m-d H:i:s')]);
        self::assertEquals($later, $note->getUpdatedAt());
        self::assertSame([], $note->releaseEvents(), 'the stock left with validation');
        foreach ([
            'deliver again' => static fn () => $note->deliver($today, $today, $later),
            'cancel' => static fn () => $note->cancel($later),
            'deliver a draft' => fn () => DeliveryNote::create($this->company, $this->establishment(), $this->customer, new DeliveryNoteHeader(), [], $this->now)->deliver($today, $today, $later),
        ] as $case => $attempt) {
            try {
                $attempt();
                self::fail("$case accepted");
            } catch (DeliveryNoteTransitionRefused) {
            }
        }
    }

    public function testCancellingKeepsTheNumberAndTellsOnlyWhenTheNoteWasValidated(): void
    {
        $draft = DeliveryNote::create($this->company, $this->establishment(), $this->customer, new DeliveryNoteHeader(), [], $this->now);
        $draft->cancel($this->now);
        self::assertSame([DeliveryNoteStatus::Cancelled, null, []], [$draft->getStatus(), $draft->getNumber(), $draft->releaseEvents()]);
        try {
            $draft->cancel($this->now);
            self::fail('a cancelled note was cancelled again');
        } catch (DeliveryNoteTransitionRefused) {
        }
        try {
            $draft->validate('BL-2026-00001', new \DateTimeImmutable('2026-09-15'), $this->now);
            self::fail('a cancelled note was validated');
        } catch (DeliveryNoteNotDraft) {
        }

        $note = $this->validated($this->customer, new DeliveryNoteHeader());
        $later = $this->now->modify('+1 hour');
        $note->cancel($later);

        self::assertSame([DeliveryNoteStatus::Cancelled, 'BL-2026-00001'], [$note->getStatus(), $note->getNumber()]);
        self::assertEquals($later, $note->getUpdatedAt());
        self::assertEquals([new DeliveryNoteCancelled($note->getId(), $this->company->getId(), $this->establishment()->getId(), 'BL-2026-00001')], $note->releaseEvents());
    }

    public function testANumberedNoteKeepsTheOnePdfItWasIssuedWith(): void
    {
        $pdf = fn (Company $company): StoredFile => new StoredFile($company, 'BL-2026-00001.pdf', 'application/pdf', '%PDF-1.7', null, $this->now);
        $draft = DeliveryNote::create($this->company, $this->establishment(), $this->customer, new DeliveryNoteHeader(), [], $this->now);
        $note = $this->validated($this->customer, new DeliveryNoteHeader());
        $issued = $pdf($this->company);

        foreach ([
            'a draft' => fn () => $draft->attachPdf($pdf($this->company)),
            'another company\'s file' => fn () => $note->attachPdf($pdf($this->globex)),
        ] as $case => $attempt) {
            try {
                $attempt();
                self::fail("$case was attached");
            } catch (\LogicException) {
            }
        }

        $note->attachPdf($issued);
        self::assertSame($issued, $note->getPdfFile());
        $this->expectException(\LogicException::class);
        $note->attachPdf($pdf($this->company));
    }

    public function testAValidatedOrDeliveredNoteIsInvoicedOnceAndIsThenNeitherDeliveredNorCancelled(): void
    {
        $invoiceId = Uuid::v7();
        $today = new \DateTimeImmutable('2026-09-15 00:00:00', new \DateTimeZone('UTC'));
        $later = $this->now->modify('+1 hour');
        $validated = $this->validated($this->customer, new DeliveryNoteHeader());
        $delivered = $this->validated($this->customer, new DeliveryNoteHeader());
        $delivered->deliver($today, $today, $this->now);
        $cancelled = $this->validated($this->customer, new DeliveryNoteHeader());
        $cancelled->cancel($this->now);
        $cancelled->releaseEvents();

        $validated->markInvoiced($invoiceId, $later);
        $delivered->markInvoiced($invoiceId, $later);

        foreach ([$validated, $delivered] as $note) {
            self::assertSame(DeliveryNoteStatus::Invoiced, $note->getStatus());
            self::assertTrue($invoiceId->equals($note->getInvoicedByInvoiceId()));
            self::assertEquals($later, $note->getUpdatedAt());
            self::assertSame([], $note->releaseEvents(), 'the invoice tells what it invoiced');
        }
        self::assertNull($cancelled->getInvoicedByInvoiceId());
        foreach ([
            'invoiced again' => static fn () => $validated->markInvoiced(Uuid::v7(), $later),
            'delivered once invoiced' => static fn () => $validated->deliver($today, $today, $later),
            'cancelled once invoiced' => static fn () => $delivered->cancel($later),
            'a draft invoiced' => fn () => DeliveryNote::create($this->company, $this->establishment(), $this->customer, new DeliveryNoteHeader(), [], $this->now)->markInvoiced($invoiceId, $later),
            'a cancelled note invoiced' => static fn () => $cancelled->markInvoiced($invoiceId, $later),
        ] as $case => $attempt) {
            try {
                $attempt();
                self::fail("$case accepted");
            } catch (DeliveryNoteTransitionRefused) {
            }
        }
        self::assertTrue($invoiceId->equals($validated->getInvoicedByInvoiceId()), 'a refused move changes nothing');
        self::assertSame(DeliveryNoteStatus::Cancelled, $cancelled->getStatus());
    }

    /** A note validated on 2026-09-15 with one line, its validation event already released. */
    private function validated(Customer $customer, DeliveryNoteHeader $header): DeliveryNote
    {
        $note = DeliveryNote::create($this->company, $this->establishment(), $customer, $header, [
            new DeliveryNoteLineDetails(null, 'Pièce', '1', $this->unit('C62'), '10', []),
        ], $this->now);
        $note->validate('BL-2026-00001', new \DateTimeImmutable('2026-09-15'), $this->now);
        $note->releaseEvents();

        return $note;
    }

    /** @param \Closure(): mixed $attempt */
    private function assertRefused(string $field, \Closure $attempt, string $case = ''): void
    {
        try {
            $attempt();
            self::fail("$field accepted $case");
        } catch (InvalidDeliveryNote $refused) {
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
