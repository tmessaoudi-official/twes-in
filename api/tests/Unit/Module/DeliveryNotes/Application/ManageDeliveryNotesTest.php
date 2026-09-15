<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Module\DeliveryNotes\Application;

use App\Fiscal\Application\Company\ProvisionCompany;
use App\Fiscal\Domain\CustomerTaxRegime;
use App\Fiscal\Domain\TaxComponent;
use App\Fiscal\Domain\TaxFamily;
use App\Fiscal\Domain\Unit;
use App\Module\Customers\Domain\Customer;
use App\Module\Customers\Domain\CustomerKind;
use App\Module\Customers\Domain\CustomerProfile;
use App\Module\DeliveryNotes\Application\DeliveryNoteInput;
use App\Module\DeliveryNotes\Application\DeliveryNoteLineInput;
use App\Module\DeliveryNotes\Application\DeliveryNoteNotFound;
use App\Module\DeliveryNotes\Application\DeliveryNoteTotals;
use App\Module\DeliveryNotes\Application\ManageDeliveryNotes;
use App\Module\DeliveryNotes\Domain\DeliveryNoteHeader;
use App\Module\DeliveryNotes\Domain\DeliveryNoteLineTax;
use App\Module\DeliveryNotes\Domain\DeliveryNoteNotDraft;
use App\Module\DeliveryNotes\Domain\InvalidDeliveryNote;
use App\Module\Products\Domain\Product;
use App\Module\Products\Domain\ProductDetails;
use App\Module\Products\Domain\ProductKind;
use App\Tenancy\Domain\Company;
use App\Tests\Support\FakeTransactions;
use App\Tests\Support\InMemoryAuditTrail;
use App\Tests\Support\InMemoryCustomers;
use App\Tests\Support\InMemoryDeliveryNotes;
use App\Tests\Support\InMemoryEstablishments;
use App\Tests\Support\InMemoryNumberingSeries;
use App\Tests\Support\InMemoryProducts;
use App\Tests\Support\InMemoryTaxComponents;
use App\Tests\Support\InMemoryUnits;
use App\Tests\Support\ShippedFiscalPresets;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

final class ManageDeliveryNotesTest extends TestCase
{
    private MockClock $clock;
    private InMemoryUnits $units;
    private InMemoryTaxComponents $taxes;
    private InMemoryEstablishments $establishments;
    private InMemoryCustomers $customers;
    private InMemoryProducts $products;
    private InMemoryAuditTrail $audit;
    private ManageDeliveryNotes $manage;
    private InMemoryDeliveryNotes $notes;
    private DeliveryNoteTotals $totals;
    private Company $company;
    private Company $globex;

    protected function setUp(): void
    {
        $this->clock = new MockClock('2026-09-15 09:00:00');
        $this->units = new InMemoryUnits();
        $this->taxes = new InMemoryTaxComponents();
        $this->establishments = new InMemoryEstablishments();
        $provision = new ProvisionCompany(ShippedFiscalPresets::presets(), $this->taxes, $this->units, $this->establishments, new InMemoryNumberingSeries(), ShippedFiscalPresets::scales(), $this->clock);
        $this->customers = new InMemoryCustomers();
        $this->products = new InMemoryProducts();
        $this->audit = new InMemoryAuditTrail();
        $this->totals = new DeliveryNoteTotals(ShippedFiscalPresets::presets(), ShippedFiscalPresets::scales());
        $this->manage = new ManageDeliveryNotes($this->notes = new InMemoryDeliveryNotes(), $transactions = new FakeTransactions(), $this->customers, $this->products, $this->units, $this->taxes, $this->establishments, $this->totals, $this->audit, $this->clock);
        $this->notes->transactions = $transactions;
        $this->company = new Company('Acme', 'TN', 'TND', 'fr', 'Africa/Tunis');
        $this->globex = new Company('Globex', 'TN', 'TND', 'fr', 'Africa/Tunis');
        $provision->handle($this->company);
        $provision->handle($this->globex);
    }

    public function testALineNamingAProductStartsFromItAndDropsTheTaxesTheCustomersRegimeDoesNotCharge(): void
    {
        $customer = $this->customer('export', [TaxFamily::Vat]);
        $product = $this->product(taxes: ['FODEC', 'TVA19']);
        $actor = Uuid::v7();

        $note = $this->manage->create($this->company, $this->input($customer, [new DeliveryNoteLineInput($product->getId(), null, '2')]), $actor);

        self::assertSame([$note], $this->manage->list($this->company));
        self::assertSame($note, $this->manage->get($this->company, $note->getId()));
        self::assertTrue($note->getEstablishment()->isDefault(), 'a note without an establishment is the default one\'s');
        $line = $note->getLines()[0];
        self::assertSame(['Portable 14"', 'C62', '1250.0000'], [$line->getDescription(), $line->getUnit()->getCode(), $line->getUnitPriceNet()]);
        self::assertSame(['FODEC'], array_map(static fn (DeliveryNoteLineTax $tax): string => $tax->getCode(), $line->getTaxes()));
        $entry = $this->audit->entries[0];
        self::assertSame(['delivery_note', 'delivery_note.created', $actor, [], $this->company->getId()], [$entry->entityType, $entry->action, $entry->actorUserId, $entry->changes, $entry->companyId]);
    }

    public function testWhatALineStatesItselfWinsOverItsProduct(): void
    {
        $product = $this->product(taxes: ['FODEC', 'TVA19']);

        $note = $this->manage->create($this->company, $this->input($this->customer(), [
            new DeliveryNoteLineInput($product->getId(), 'Portable reconditionné', '1', $this->unit('H87')->getId(), '990.5', [$this->tax('TVA7')->getId()]),
        ]), null);

        $line = $note->getLines()[0];
        self::assertSame(['Portable reconditionné', 'H87', '990.5000'], [$line->getDescription(), $line->getUnit()->getCode(), $line->getUnitPriceNet()]);
        self::assertSame(['TVA7'], array_map(static fn (DeliveryNoteLineTax $tax): string => $tax->getCode(), $line->getTaxes()));
    }

    public function testTheTotalsAreTheCalculatorsOnTheLinesNetOfTaxAtTheCurrencyScale(): void
    {
        $note = $this->manage->create($this->company, $this->input($this->customer(), [
            new DeliveryNoteLineInput(null, 'Pièce', '2', $this->unit('C62')->getId(), '100', [$this->tax('FODEC')->getId(), $this->tax('TVA19')->getId()]),
            new DeliveryNoteLineInput(null, 'Pose', '1.5', $this->unit('HUR')->getId(), '40', [$this->tax('TVA19')->getId()]),
        ]), null);

        $totals = $this->totals->of($note);

        self::assertSame(['200.000', '60.000'], [$totals->lines[0]->net, $totals->lines[1]->net]);
        self::assertSame('260.000', $totals->subtotalNet);
        // FODEC 1 % of 200 enters the VAT base of its line: VAT 19 % of (202 + 60).
        self::assertSame([['FODEC', '2.000'], ['TVA19', '49.780']], array_map(static fn ($tax): array => [$tax->code, $tax->amount], $totals->taxes));
        self::assertSame(['51.780', '311.780'], [$totals->totalTax, $totals->total]);
    }

    public function testWhatTheCompanyOrTheLineRefusesIsNamedByItsField(): void
    {
        $customer = $this->customer();
        $retired = $this->product(reference: 'ART-OLD');
        $retired->revise('ART-OLD', $retired->getDetails(), $retired->getUnit(), null, [], false, $this->clock->now());
        $absent = Uuid::v7();

        foreach ([
            'customerId' => $this->input($customer, [], customerId: $absent),
            'establishmentId' => $this->input($customer, [], establishmentId: $this->establishments->ofCompany($this->globex->getId())[0]->getId()),
            'lines[0].description' => $this->input($customer, [new DeliveryNoteLineInput(null, null, '1', $this->unit('C62')->getId(), '1')]),
            'lines[0].unitId' => $this->input($customer, [new DeliveryNoteLineInput(null, 'Pièce', '1', null, '1')]),
            'lines[0].unitPriceNet' => $this->input($customer, [new DeliveryNoteLineInput(null, 'Pièce', '1', $this->unit('C62')->getId(), null)]),
            'lines[0].productId' => $this->input($customer, [new DeliveryNoteLineInput($absent, null, '1')]),
            'lines[1].productId' => $this->input($customer, [new DeliveryNoteLineInput(null, 'Pièce', '1', $this->unit('C62')->getId(), '1'), new DeliveryNoteLineInput($retired->getId(), null, '1')]),
            'lines[0].quantity' => $this->input($customer, [new DeliveryNoteLineInput(null, 'Pièce', '1.5', $this->unit('C62')->getId(), '1')]),
            'lines[0].taxComponentIds' => $this->input($customer, [new DeliveryNoteLineInput(null, 'Pièce', '1', $this->unit('C62')->getId(), '1', [$absent])]),
        ] as $field => $input) {
            try {
                $this->manage->create($this->company, $input, null);
                self::fail("$field accepted");
            } catch (InvalidDeliveryNote $refused) {
                self::assertSame($field, $refused->field);
            }
        }

        $exempt = $this->customer('exempt', [TaxFamily::Vat], 'CLI-0002');
        try {
            $this->manage->create($this->company, $this->input($exempt, [new DeliveryNoteLineInput(null, 'Pièce', '1', $this->unit('C62')->getId(), '1', [$this->tax('TVA19')->getId()])]), null);
            self::fail('a VAT the regime does not charge was accepted');
        } catch (InvalidDeliveryNote $refused) {
            self::assertSame('lines[0].taxComponentIds', $refused->field);
            self::assertStringContainsString('TVA19', $refused->getMessage());
        }

        $customer->revise('CLI-0001', $customer->getProfile(), null, $customer->getTaxRegime(), [], false, $this->clock->now());
        try {
            $this->manage->create($this->company, $this->input($customer, []), null);
            self::fail('a deactivated customer was accepted');
        } catch (InvalidDeliveryNote $refused) {
            self::assertSame('customerId', $refused->field);
        }
        self::assertSame([], $this->manage->list($this->company));
        self::assertSame([], $this->audit->entries);
    }

    public function testARevisionIsAuditedByFieldAndKeepsWhatWasRetiredSinceTheNoteNamedIt(): void
    {
        $customer = $this->customer();
        $product = $this->product();
        $note = $this->manage->create($this->company, $this->input($customer, [new DeliveryNoteLineInput($product->getId(), null, '1')]), null);
        $product->revise('ART-001', $product->getDetails(), $product->getUnit(), null, [], false, $this->clock->now());
        $customer->revise('CLI-0001', $customer->getProfile(), null, $customer->getTaxRegime(), [], false, $this->clock->now());

        $this->manage->revise($this->company, $note->getId(), $this->input($customer, [new DeliveryNoteLineInput($product->getId(), null, '4')], header: new DeliveryNoteHeader(customerReference: 'PO-9')), null);

        self::assertSame('4.000', $note->getLines()[0]->getQuantity());
        $entry = $this->audit->entries[1];
        self::assertSame(['delivery_note.revised', ['fields' => ['customerReference', 'lines']]], [$entry->action, $entry->changes]);

        $this->expectException(DeliveryNoteNotFound::class);
        $this->manage->revise($this->globex, $note->getId(), $this->input($customer, []), null);
    }

    public function testARequestThatReadTheDraftBeforeAnotherCancelledItRevisesNothing(): void
    {
        $customer = $this->customer();
        $note = $this->manage->create($this->company, $this->input($customer, [new DeliveryNoteLineInput($this->product()->getId(), null, '1')]), null);
        $readAsDraft = clone $note;
        $note->cancel($this->clock->now());
        $this->notes->staleReads[$note->getId()->toRfc4122()] = $readAsDraft;

        try {
            $this->manage->revise($this->company, $note->getId(), $this->input($customer, [new DeliveryNoteLineInput($this->product()->getId(), null, '4')], header: new DeliveryNoteHeader(customerReference: 'PO-9')), null);
            self::fail('a note that is no longer a draft was revised from a copy read while it was one');
        } catch (DeliveryNoteNotDraft) {
        }

        self::assertSame(['delivery_note.created'], array_map(static fn ($entry): string => $entry->action, $this->audit->entries));
    }

    /** @param list<DeliveryNoteLineInput> $lines */
    private function input(Customer $customer, array $lines, ?Uuid $customerId = null, ?Uuid $establishmentId = null, DeliveryNoteHeader $header = new DeliveryNoteHeader()): DeliveryNoteInput
    {
        return new DeliveryNoteInput($customerId ?? $customer->getId(), $establishmentId, $header, $lines);
    }

    /** @param list<TaxFamily> $excluded */
    private function customer(string $regime = 'standard', array $excluded = [], string $number = 'CLI-0001'): Customer
    {
        $now = $this->clock->now();
        $customer = Customer::create($this->company, $number, new CustomerProfile(CustomerKind::Company, 'Carthage Conseil'), null, new CustomerTaxRegime('TN', $regime, 'fiscal.regime.'.$regime, $excluded, null, 0, $now), [], $now);
        $this->customers->save($customer);

        return $customer;
    }

    /** @param list<string> $taxes */
    private function product(string $reference = 'ART-001', array $taxes = []): Product
    {
        $product = Product::create($this->company, $reference, new ProductDetails('Portable 14"', null, ProductKind::Goods, '1250'), $this->unit('C62'), null, array_map(fn (string $code): Uuid => $this->tax($code)->getId(), $taxes), $this->clock->now());
        $this->products->save($product);

        return $product;
    }

    private function unit(string $code): Unit
    {
        $unit = $this->units->ofCodeInCompany($code, $this->company->getId());
        self::assertNotNull($unit);

        return $unit;
    }

    private function tax(string $code): TaxComponent
    {
        $tax = $this->taxes->ofCodeInCompany($code, $this->company->getId());
        self::assertNotNull($tax);

        return $tax;
    }
}
