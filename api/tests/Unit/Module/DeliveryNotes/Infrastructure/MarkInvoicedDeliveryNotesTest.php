<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Module\DeliveryNotes\Infrastructure;

use App\Fiscal\Application\Company\ProvisionCompany;
use App\Fiscal\Domain\CustomerTaxRegime;
use App\Module\Customers\Domain\Customer;
use App\Module\Customers\Domain\CustomerKind;
use App\Module\Customers\Domain\CustomerProfile;
use App\Module\DeliveryNotes\Application\InvoiceDeliveryNotes;
use App\Module\DeliveryNotes\Domain\DeliveryNote;
use App\Module\DeliveryNotes\Domain\DeliveryNoteHeader;
use App\Module\DeliveryNotes\Domain\DeliveryNoteLineDetails;
use App\Module\DeliveryNotes\Domain\DeliveryNoteStatus;
use App\Module\DeliveryNotes\Infrastructure\Invoicing\MarkInvoicedDeliveryNotes;
use App\Module\Invoices\Application\InvoiceTotals;
use App\Module\Invoices\Application\ManageInvoices;
use App\Module\Invoices\Domain\InvoiceIssued;
use App\Module\Invoices\Domain\InvoiceType;
use App\Tenancy\Domain\Company;
use App\Tests\Support\FakeTransactions;
use App\Tests\Support\InMemoryAuditTrail;
use App\Tests\Support\InMemoryCustomers;
use App\Tests\Support\InMemoryDeliveryNotes;
use App\Tests\Support\InMemoryEstablishments;
use App\Tests\Support\InMemoryInvoices;
use App\Tests\Support\InMemoryNumberingSeries;
use App\Tests\Support\InMemoryProducts;
use App\Tests\Support\InMemoryTaxComponents;
use App\Tests\Support\InMemoryUnits;
use App\Tests\Support\ShippedFiscalPresets;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

final class MarkInvoicedDeliveryNotesTest extends TestCase
{
    public function testTheNotesOfAnIssuedInvoiceTurnInvoicedAndOneThatCannotIsLoggedAndLeft(): void
    {
        $clock = new MockClock('2026-09-15 09:00:00', 'UTC');
        $units = new InMemoryUnits();
        $taxes = new InMemoryTaxComponents();
        $establishments = new InMemoryEstablishments();
        new ProvisionCompany(ShippedFiscalPresets::presets(), $taxes, $units, $establishments, new InMemoryNumberingSeries(), ShippedFiscalPresets::scales(), $clock)
            ->handle($company = new Company('Acme', 'TN', 'TND', 'fr', 'Africa/Tunis'));
        $notes = new InMemoryDeliveryNotes();
        $transactions = new FakeTransactions();
        $notes->transactions = $transactions;
        $invoices = new InMemoryInvoices();
        $audit = new InMemoryAuditTrail($transactions);
        $manage = new ManageInvoices($invoices, new FakeTransactions(), new InMemoryCustomers(), new InMemoryProducts(), $units, $taxes, $establishments, new InvoiceTotals(ShippedFiscalPresets::presets(), ShippedFiscalPresets::scales()), $audit, $clock);
        $now = $clock->now();
        $customer = Customer::create($company, 'CLI-0001', new CustomerProfile(CustomerKind::Company, 'Carthage Conseil'), null, new CustomerTaxRegime('TN', 'standard', 'fiscal.regime.standard', [], null, 0, $now), [], $now);
        $unit = $units->ofCodeInCompany('C62', $company->getId());
        self::assertNotNull($unit);
        $establishment = $establishments->ofCompany($company->getId())[0];
        $note = static function (string $number) use ($company, $establishment, $customer, $unit, $now, $notes): DeliveryNote {
            $note = DeliveryNote::create($company, $establishment, $customer, new DeliveryNoteHeader(), [new DeliveryNoteLineDetails(null, 'Pièce', '1', $unit, '10', [])], $now);
            $note->validate($number, new \DateTimeImmutable('2026-09-15'), $now);
            $notes->save($note);

            return $note;
        };
        $invoiced = $note('BL-2026-00001');
        $cancelled = $note('BL-2026-00002');
        $cancelled->cancel($now);
        $logger = new class extends AbstractLogger {
            /** @var list<array{mixed, string|\Stringable, array<mixed>}> */
            public array $records = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = [$level, $message, $context];
            }
        };
        $listener = new MarkInvoicedDeliveryNotes(new InvoiceDeliveryNotes($notes, $invoices, $manage, $transactions, $audit, $clock), $logger);

        $listener(self::issued($company, [$invoiced->getLines()[0]->getId(), $cancelled->getLines()[0]->getId()]));

        self::assertSame([DeliveryNoteStatus::Invoiced, DeliveryNoteStatus::Cancelled], [$invoiced->getStatus(), $cancelled->getStatus()]);
        self::assertCount(1, $logger->records);
        [$level, , $context] = $logger->records[0];
        self::assertSame(['warning', 'FAC-2026-00001'], [$level, $context['number'] ?? null]);
        self::assertIsString($context['reason'] ?? null);
        self::assertStringContainsString('BL-2026-00002', $context['reason']);

        $listener(self::issued($company, []));
        self::assertSame([1, 1], [\count($logger->records), $transactions->committed], 'an invoice drafted by hand marks nothing');
    }

    /** @param list<Uuid> $lines */
    private static function issued(Company $company, array $lines): InvoiceIssued
    {
        return new InvoiceIssued(Uuid::v7(), $company->getId(), Uuid::v7(), InvoiceType::Invoice, 'FAC-2026-00001', new \DateTimeImmutable('2026-09-15'), $lines);
    }
}
