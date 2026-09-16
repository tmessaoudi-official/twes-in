<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Module\Invoices\Application;

use App\Files\Application\Files;
use App\Fiscal\Application\Company\ProvisionCompany;
use App\Fiscal\Domain\CustomerTaxRegime;
use App\Fiscal\Domain\TaxFamily;
use App\Module\Customers\Domain\Customer;
use App\Module\Customers\Domain\CustomerKind;
use App\Module\Customers\Domain\CustomerProfile;
use App\Module\Invoices\Application\InvoiceMentions;
use App\Module\Invoices\Application\InvoiceNotFound;
use App\Module\Invoices\Application\InvoicePage;
use App\Module\Invoices\Application\InvoiceTotals;
use App\Module\Invoices\Application\PrintInvoice;
use App\Module\Invoices\Domain\Invoice;
use App\Module\Invoices\Domain\InvoiceHeader;
use App\Module\Invoices\Domain\InvoiceIssue;
use App\Module\Invoices\Domain\InvoiceLineDetails;
use App\Settings\Application\BusinessDefaultSettings;
use App\Settings\Application\ChangeSettings;
use App\Settings\Application\ReadSetting;
use App\Settings\Application\ResolveSettings;
use App\Settings\Application\SettingCatalog;
use App\Settings\Application\SettingContext;
use App\Settings\Domain\SettingLevel;
use App\Shared\Application\PdfRenderingFailed;
use App\Tenancy\Domain\Company;
use App\Tenancy\Domain\CompanyProfile;
use App\Tests\Support\FakePdfRenderer;
use App\Tests\Support\FakeTransactions;
use App\Tests\Support\InMemoryAuditTrail;
use App\Tests\Support\InMemoryEstablishments;
use App\Tests\Support\InMemoryFileStorage;
use App\Tests\Support\InMemoryInvoices;
use App\Tests\Support\InMemoryNumberingSeries;
use App\Tests\Support\InMemorySettings;
use App\Tests\Support\InMemoryStoredFiles;
use App\Tests\Support\InMemoryTaxComponents;
use App\Tests\Support\InMemoryUnits;
use App\Tests\Support\RecordingInvoiceTemplate;
use App\Tests\Support\ShippedFiscalPresets;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class PrintInvoiceTest extends TestCase
{
    private MockClock $clock;
    private InMemoryUnits $units;
    private InMemoryTaxComponents $taxes;
    private InMemoryEstablishments $establishments;
    private InMemoryInvoices $invoices;
    private InMemoryFileStorage $storage;
    private InMemoryStoredFiles $records;
    private FakePdfRenderer $renderer;
    private RecordingInvoiceTemplate $template;
    private ChangeSettings $change;
    private InvoiceTotals $totals;
    private PrintInvoice $print;
    private Company $company;

    protected function setUp(): void
    {
        $this->clock = new MockClock('2026-09-15 09:00:00');
        $this->units = new InMemoryUnits();
        $this->taxes = new InMemoryTaxComponents();
        $this->establishments = new InMemoryEstablishments();
        new ProvisionCompany(ShippedFiscalPresets::presets(), $this->taxes, $this->units, $this->establishments, new InMemoryNumberingSeries(), ShippedFiscalPresets::scales(), $this->clock)
            ->handle($this->company = new Company('Acme', 'TN', 'TND', 'fr', 'Africa/Tunis'));
        $this->invoices = new InMemoryInvoices();
        $this->storage = new InMemoryFileStorage();
        $this->records = new InMemoryStoredFiles();
        $this->renderer = new FakePdfRenderer();
        $this->template = new RecordingInvoiceTemplate();
        $settings = new InMemorySettings();
        $catalog = new SettingCatalog([new BusinessDefaultSettings()]);
        $resolve = new ResolveSettings($catalog, $settings);
        $this->change = new ChangeSettings($catalog, $settings, $resolve, new InMemoryAuditTrail($settingTransactions = new FakeTransactions()), $this->clock, $settingTransactions);
        $this->totals = new InvoiceTotals(ShippedFiscalPresets::presets(), ShippedFiscalPresets::scales());
        $this->print = new PrintInvoice(
            $this->invoices,
            $this->totals,
            new InvoiceMentions(ShippedFiscalPresets::presets()),
            $this->template,
            $this->renderer,
            new Files($this->storage, $this->records, $this->clock),
            new ReadSetting($resolve),
        );
    }

    public function testADraftIsRenderedOnRequestWatermarkedAndNeverStored(): void
    {
        $draft = $this->draft($this->customer('standard', null));

        $printed = $this->print->pdf($this->company, $draft->getId());

        self::assertSame('invoice-'.$draft->getId()->toRfc4122().'.pdf', $printed->fileName);
        self::assertStringStartsWith('%PDF-', $printed->contents);
        self::assertCount(1, $this->template->pages);
        $page = $this->template->pages[0];
        self::assertSame([$draft, InvoicePage::DRAFT, 'fr', '', [], null, null], [$page->invoice, $page->watermark, $page->language, $page->printedNotes, $page->mentionKeys, $page->latePenaltyText, $page->footer]);
        self::assertSame(['12.900', 'CLI-0001'], [$page->figures->total, $page->customer->number]);
        self::assertSame([[], [], null], [$this->records->files, $this->storage->contents, $draft->getPdfFile()]);
    }

    public function testADraftPreviewsTheMentionsTextsAndLanguageItWouldBeIssuedWith(): void
    {
        $customer = $this->customer('export', 'fiscal.mention.tn.export');
        $atCustomer = new SettingContext($this->company, customerId: $customer->getId());
        $this->change->change($atCustomer, 'document.language', SettingLevel::Customer, 'en', null);
        $this->change->change(new SettingContext($this->company), 'document.printed_notes', SettingLevel::Company, 'Payable by transfer.', null);
        $this->company->reviseProfile(new CompanyProfile(invoiceFooterText: 'Merci', latePenaltyText: 'Pénalité de retard : 1 % par mois'));

        $this->print->pdf($this->company, $this->draft($customer)->getId());

        $page = $this->template->pages[0];
        self::assertSame(['en', 'Payable by transfer.', ['fiscal.mention.tn.export'], 'Pénalité de retard : 1 % par mois', 'Merci'], [$page->language, $page->printedNotes, $page->mentionKeys, $page->latePenaltyText, $page->footer]);
    }

    public function testAnIssuedInvoiceIsStoredOnceThenServedAsItWasIssued(): void
    {
        $customer = $this->customer('export', 'fiscal.mention.tn.export');
        $invoice = $this->issued($customer);
        $this->change->change(new SettingContext($this->company, customerId: $customer->getId()), 'document.language', SettingLevel::Customer, 'fr', null);

        $this->print->storeIssued($this->company->getId(), $invoice->getId());
        $this->print->storeIssued($this->company->getId(), $invoice->getId());

        self::assertCount(1, $this->records->files, 'an invoice keeps the one PDF it was issued with');
        $file = $this->records->files[0];
        self::assertSame([$file, 'FAC-2026-00001.pdf', 'application/pdf'], [$invoice->getPdfFile(), $file->getOriginalName(), $file->getMime()]);
        $page = $this->template->pages[0];
        self::assertSame([null, 'en', ['fiscal.mention.tn.export'], 'Merci'], [$page->watermark, $page->language, $page->mentionKeys, $page->footer], 'an issued invoice prints what issuing kept, not today\'s settings');

        $printed = $this->print->pdf($this->company, $invoice->getId());

        self::assertSame('FAC-2026-00001.pdf', $printed->fileName);
        self::assertSame($this->storage->contents[$file->getStorageKey()], $printed->contents);
        self::assertCount(1, $this->renderer->rendered, 'a stored PDF is served, never rendered again');

        $this->expectException(InvoiceNotFound::class);
        $this->print->pdf(new Company('Globex', 'TN', 'TND', 'fr', 'Africa/Tunis'), $invoice->getId());
    }

    public function testWhenTheRendererFailedAtIssueTheFirstDownloadStoresThePdf(): void
    {
        $invoice = $this->issued($this->customer('standard', null));
        $this->renderer->failing = true;
        try {
            $this->print->storeIssued($this->company->getId(), $invoice->getId());
            self::fail('a PDF was stored without being rendered');
        } catch (PdfRenderingFailed) {
        }
        self::assertSame([[], null], [$this->records->files, $invoice->getPdfFile()]);

        $this->renderer->failing = false;
        $printed = $this->print->pdf($this->company, $invoice->getId());

        self::assertCount(1, $this->records->files);
        self::assertSame($this->records->files[0], $invoice->getPdfFile());
        self::assertSame($this->storage->contents[$this->records->files[0]->getStorageKey()], $printed->contents);
    }

    public function testACancelledDraftIsRenderedStampedAndNeverStored(): void
    {
        $draft = $this->draft($this->customer('standard', null));
        $draft->cancel($this->clock->now());

        $printed = $this->print->pdf($this->company, $draft->getId());
        $this->print->storeIssued($this->company->getId(), $draft->getId());

        self::assertSame(InvoicePage::CANCELLED, $this->template->pages[0]->watermark);
        self::assertStringContainsString('cancelled', $printed->contents);
        self::assertSame([[], null], [$this->records->files, $draft->getPdfFile()]);
    }

    private function draft(Customer $customer): Invoice
    {
        $unit = $this->units->ofCodeInCompany('C62', $this->company->getId());
        $vat = $this->taxes->ofCodeInCompany('TVA19', $this->company->getId());
        $stamp = $this->taxes->ofCodeInCompany('TIMBRE', $this->company->getId());
        self::assertNotNull($unit);
        self::assertNotNull($vat);
        self::assertNotNull($stamp);
        $taxes = [] === $customer->getTaxRegime()->getExcludedFamilies() ? [$vat] : [];
        $invoice = Invoice::create($this->company, $this->establishments->ofCompany($this->company->getId())[0], $customer, new InvoiceHeader(), [
            new InvoiceLineDetails(null, 'Pièce', '1', $unit, '10', null, $taxes),
        ], [$stamp], $this->clock->now());
        $this->invoices->save($invoice);

        return $invoice;
    }

    private function issued(Customer $customer): Invoice
    {
        $invoice = $this->draft($customer);
        $invoice->issue(
            new InvoiceIssue('FAC-2026-00001', new \DateTimeImmutable('2026-09-15'), 30, 'en', $customer->getTaxRegime()->getMentionKey() ? [$customer->getTaxRegime()->getMentionKey()] : [], null, 'Merci', null),
            fn (Invoice $issuing) => $this->totals->issued($issuing),
            $this->clock->now(),
        );
        $invoice->releaseEvents();

        return $invoice;
    }

    private function customer(string $regime, ?string $mentionKey): Customer
    {
        $now = $this->clock->now();

        return Customer::create($this->company, 'CLI-0001', new CustomerProfile(CustomerKind::Company, 'Carthage Conseil'), null, new CustomerTaxRegime('TN', $regime, 'fiscal.regime.'.$regime, null === $mentionKey ? [] : [TaxFamily::Vat], $mentionKey, 0, $now), [], $now);
    }
}
