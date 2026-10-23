<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Module\DeliveryNotes\Infrastructure;

use App\Fiscal\Application\Company\ProvisionCompany;
use App\Fiscal\Application\Regime\ExcludedTaxFamilies;
use App\Fiscal\Domain\Model\CustomerTaxRegime;
use App\Module\Customers\Domain\Model\Customer;
use App\Module\Customers\Domain\ValueObject\CustomerKind;
use App\Module\Customers\Domain\ValueObject\CustomerProfile;
use App\Module\DeliveryNotes\Application\InvoiceDeliveryNotes;
use App\Module\DeliveryNotes\Domain\Model\DeliveryNote;
use App\Module\DeliveryNotes\Domain\ValueObject\DeliveryNoteHeader;
use App\Module\DeliveryNotes\Domain\ValueObject\DeliveryNoteLineDetails;
use App\Module\DeliveryNotes\Domain\ValueObject\DeliveryNotePrint;
use App\Module\DeliveryNotes\Domain\ValueObject\DeliveryNoteStatus;
use App\Module\DeliveryNotes\Infrastructure\Invoicing\MarkInvoicedDeliveryNotes;
use App\Module\Invoices\Application\DeliveryNoteRoom;
use App\Module\Invoices\Application\InvoiceTotals;
use App\Module\Invoices\Application\ManageInvoices;
use App\Module\Invoices\Domain\Event\InvoiceIssued;
use App\Module\Invoices\Domain\Model\Invoice;
use App\Module\Invoices\Domain\ValueObject\InvoiceStatus;
use App\Module\Invoices\Domain\ValueObject\InvoiceType;
use App\Module\Invoices\Infrastructure\DeliveryNotes\ManagedInvoiceDrafts;
use App\Module\Invoices\Infrastructure\DeliveryNotes\RepositoryInvoicedLines;
use App\Shared\Application\ListLimits;
use App\Shared\Domain\ValueObject\PrintSettings;
use App\Shared\Infrastructure\Validation\ParameterListLimits;
use App\Tenancy\Domain\Model\Company;
use App\Tests\Support\FakeTransactions;
use App\Tests\Support\InMemoryAuditTrail;
use App\Tests\Support\InMemoryCustomers;
use App\Tests\Support\InMemoryDeliveryNotes;
use App\Tests\Support\InMemoryEstablishments;
use App\Tests\Support\InMemoryInvoices;
use App\Tests\Support\InMemoryNumberingSeries;
use App\Tests\Support\InMemoryProducts;
use App\Tests\Support\InMemoryRecordVersions;
use App\Tests\Support\InMemorySourceDeliveryNoteLines;
use App\Tests\Support\InMemoryTaxComponents;
use App\Tests\Support\InMemoryUnits;
use App\Tests\Support\KeptNames;
use App\Tests\Support\NoQuotesToDeliver;
use App\Tests\Support\ShelfLinePrices;
use App\Tests\Support\ShippedDepositDeductions;
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
        $manage = new ManageInvoices($invoices, new FakeTransactions(), new InMemoryCustomers(), new InMemoryProducts(), $units, $taxes, $establishments, new InvoiceTotals(ShippedFiscalPresets::presets(), ShippedFiscalPresets::scales()), $audit, $clock, new ShelfLinePrices(), new ExcludedTaxFamilies(ShippedFiscalPresets::presets()), new DeliveryNoteRoom(new InMemorySourceDeliveryNoteLines(), $invoices), ShippedDepositDeductions::of($invoices, new InvoiceTotals(ShippedFiscalPresets::presets(), ShippedFiscalPresets::scales())), ShippedFiscalPresets::presets(), new InMemoryRecordVersions(), new ParameterListLimits([ListLimits::DOCUMENT_LINES => 1000]));
        $now = $clock->now();
        $customer = Customer::create($company, 'CLI-0001', new CustomerProfile(CustomerKind::Company, 'Carthage Conseil'), null, new CustomerTaxRegime('TN', 'standard', 'fiscal.regime.standard', [], null, 0, $now), [], $now);
        $unit = $units->ofCodeInCompany('C62', $company->getId());
        self::assertNotNull($unit);
        $establishment = $establishments->ofCompany($company->getId())[0];
        $note = static function (string $number) use ($company, $establishment, $customer, $unit, $now, $notes): DeliveryNote {
            $note = DeliveryNote::create($company, $establishment, $customer, new DeliveryNoteHeader(), [new DeliveryNoteLineDetails(null, 'Pièce', '1', $unit, '10', [])], $now);
            $note->validate($number, new \DateTimeImmutable('2026-09-15'), new DeliveryNotePrint('fr', true, true, new PrintSettings('', 'auto', 'auto')), KeptNames::of($note), $now);
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
        $invoicing = new InvoiceDeliveryNotes($notes, new RepositoryInvoicedLines($invoices), new ManagedInvoiceDrafts($manage, $invoices), $transactions, $audit, $clock, NoQuotesToDeliver::discounts($notes, new RepositoryInvoicedLines($invoices)));
        // The invoice the event tells of, issued, as the repository holds it by then.
        new \ReflectionProperty(Invoice::class, 'status')->setValue($invoices->ofIdInCompany($invoicing->draftInvoice($company, [$invoiced->getId()], null), $company->getId()), InvoiceStatus::Issued);
        $transactions->committed = 0;
        $listener = new MarkInvoicedDeliveryNotes($invoicing, $logger);

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
