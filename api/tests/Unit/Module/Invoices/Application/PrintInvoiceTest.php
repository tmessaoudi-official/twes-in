<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Module\Invoices\Application;

use App\Files\Application\Files;
use App\Fiscal\Application\Company\ProvisionCompany;
use App\Fiscal\Application\DocumentNames;
use App\Fiscal\Application\Regime\ExcludedTaxFamilies;
use App\Fiscal\Domain\Calculation\QuantityTotal;
use App\Fiscal\Domain\Model\CustomerTaxRegime;
use App\Fiscal\Domain\Model\Unit;
use App\Fiscal\Domain\ValueObject\PrintedNames;
use App\Fiscal\Domain\ValueObject\TaxFamily;
use App\Module\Customers\Domain\Model\Customer;
use App\Module\Customers\Domain\ValueObject\CustomerKind;
use App\Module\Customers\Domain\ValueObject\CustomerProfile;
use App\Module\Invoices\Application\InvoiceNotFound;
use App\Module\Invoices\Application\InvoicePage;
use App\Module\Invoices\Application\InvoiceTotals;
use App\Module\Invoices\Application\Print\InvoiceCopy;
use App\Module\Invoices\Application\Print\InvoiceMentions;
use App\Module\Invoices\Application\Print\NoCopyOfADraft;
use App\Module\Invoices\Application\Print\PrintInvoice;
use App\Module\Invoices\Domain\Model\Invoice;
use App\Module\Invoices\Domain\Model\InvoiceLine;
use App\Module\Invoices\Domain\ValueObject\InvoiceHeader;
use App\Module\Invoices\Domain\ValueObject\InvoiceIssue;
use App\Module\Invoices\Domain\ValueObject\InvoiceLineDetails;
use App\Module\Invoices\Domain\ValueObject\InvoiceStatus;
use App\Module\Invoices\Domain\ValueObject\PaymentDetails;
use App\Settings\Application\BusinessDefaultSettings;
use App\Settings\Application\ChangeSettings;
use App\Settings\Application\PresentationSettings;
use App\Settings\Application\ReadSetting;
use App\Settings\Application\ResolveSettings;
use App\Settings\Application\SettingCatalog;
use App\Settings\Application\SettingContext;
use App\Settings\Domain\ValueObject\SettingLevel;
use App\Shared\Application\PdfRenderingFailed;
use App\Shared\Domain\ValueObject\PaymentMethod;
use App\Shared\Domain\ValueObject\PrintSettings;
use App\Tenancy\Domain\Model\Company;
use App\Tenancy\Domain\ValueObject\CompanyProfile;
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
use App\Tests\Support\RecordingDomainEvents;
use App\Tests\Support\RecordingInvoiceTemplate;
use App\Tests\Support\ShippedFiscalPresets;
use App\Tests\Support\ShippedMentionWording;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

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
        $catalog = new SettingCatalog([new BusinessDefaultSettings(\App\Tests\Support\ShippedLanguages::catalogue()), new PresentationSettings(\App\Tests\Support\ShippedLanguages::catalogue())]);
        $resolve = new ResolveSettings($catalog, $settings);
        $this->change = new ChangeSettings($catalog, $settings, $resolve, new InMemoryAuditTrail($settingTransactions = new FakeTransactions()), $this->clock, $settingTransactions, new RecordingDomainEvents(), new \App\Shared\Application\Phones\TypedPhones(new \App\Shared\Infrastructure\Intl\LibPhoneNumbers(new \Symfony\Component\Translation\IdentityTranslator()), new \App\Tests\Support\FixedPhonePolicy()));
        $this->totals = new InvoiceTotals(ShippedFiscalPresets::presets(), ShippedFiscalPresets::scales());
        $this->print = new PrintInvoice(
            $this->invoices,
            $this->totals,
            new InvoiceMentions(ShippedFiscalPresets::presets(), new ExcludedTaxFamilies(ShippedFiscalPresets::presets()), ShippedMentionWording::wording(), new ReadSetting($resolve)),
            $this->template,
            $this->renderer,
            new Files($this->storage, $this->records, $this->clock),
            new ReadSetting($resolve),
            $this->clock,
            ShippedFiscalPresets::presets(),
            new FakeTransactions(),
            new DocumentNames($this->taxes),
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

    public function testADocumentNamesItsTaxesAndUnitsInItsOwnLanguage(): void
    {
        $customer = $this->customer('standard', null);
        $this->change->change(new SettingContext($this->company, customerId: $customer->getId()), 'document.language', SettingLevel::Customer, 'ar', null);

        $this->print->pdf($this->company, $this->draft($customer, lineCount: 2)->getId());

        $page = $this->template->pages[0];
        self::assertSame('ar', $page->language);
        self::assertSame('معلوم الطابع الجبائي', $page->names->taxes['TIMBRE'] ?? null);
        self::assertSame('الخصم من المورد 1 %', $page->names->taxes['RS1'] ?? null);
        self::assertSame(['وحدة'], array_map(static fn (QuantityTotal $each): string => $each->unitName, $page->quantities));
        self::assertMatchesRegularExpression('/^\p{Arabic}[\p{Arabic} ]+$/u', $page->amountInWords ?? '', 'an Arabic invoice writes its total out in Arabic');
    }

    public function testACopyPrintsTheUnitAndTaxNamesTheInvoiceWasIssuedWith(): void
    {
        $invoice = $this->issued($this->customer('standard', null));
        $issuedWith = $this->namesNow('en');
        $this->renameUnitAndStamp();

        $this->print->copy($this->company, $invoice->getId(), InvoiceCopy::Duplicate);
        $this->print->copy($this->company, $invoice->getId(), InvoiceCopy::UpToDate);

        foreach ($this->template->pages as $page) {
            self::assertSame($issuedWith, [$page->names->units['C62'] ?? null, $page->names->taxes['TIMBRE'] ?? null]);
        }
    }

    public function testACompanyKeepingTheNamesLiveReprintsThemAsTheyAreNow(): void
    {
        $invoice = $this->issued($this->customer('standard', null));
        $this->renameUnitAndStamp();
        $this->change->change(new SettingContext($this->company), 'document.names_as_issued', SettingLevel::Company, false, null);

        $this->print->copy($this->company, $invoice->getId(), InvoiceCopy::Duplicate);
        $this->print->designPreview($this->company, $invoice->getPrintSettings()->design ?? throw new \LogicException('An issued invoice keeps its design.'));

        foreach ($this->template->pages as $page) {
            self::assertSame(['Renamed piece', 'Renamed stamp'], [$page->names->units['C62'] ?? null, $page->names->taxes['TIMBRE'] ?? null]);
        }
    }

    /** The original is the document as it was issued, whenever it is first rendered and whatever the switch says. */
    public function testAnOriginalFirstRenderedAfterARenamePrintsTheNamesItWasIssuedWith(): void
    {
        $this->renderer->failing = true;
        $invoice = $this->issued($this->customer('standard', null));
        $issuedWith = $this->namesNow('en');
        $this->renameUnitAndStamp();
        $this->change->change(new SettingContext($this->company), 'document.names_as_issued', SettingLevel::Company, false, null);
        $this->renderer->failing = false;

        $this->print->pdf($this->company, $invoice->getId());

        $page = $this->template->pages[0];
        self::assertNull($page->watermark);
        self::assertSame($issuedWith, [$page->names->units['C62'] ?? null, $page->names->taxes['TIMBRE'] ?? null]);
    }

    /** An invoice issued before names were kept has none to print, and prints them as they are, as it always did. */
    public function testAnInvoiceIssuedBeforeNamesWereKeptPrintsThemAsTheyAreNow(): void
    {
        $invoice = $this->issued($this->customer('standard', null));
        new \ReflectionProperty(Invoice::class, 'printedNames')->setValue($invoice, null);
        $this->renameUnitAndStamp();

        $this->print->copy($this->company, $invoice->getId(), InvoiceCopy::Duplicate);

        $page = $this->template->pages[0];
        self::assertSame(['Renamed piece', 'Renamed stamp'], [$page->names->units['C62'] ?? null, $page->names->taxes['TIMBRE'] ?? null]);
    }

    // docs/SPEC.md § 7, 2026-09-25 12:45, row 130: a printed document follows the company's formats, never the person printing it.
    public function testADocumentPrintsTheCompanysDateAndNumberFormatNotAPersonsOwn(): void
    {
        $draft = $this->draft($this->customer('standard', null));
        $this->print->pdf($this->company, $draft->getId());
        self::assertSame(['auto', 'auto'], [$this->template->pages[0]->dateFormat, $this->template->pages[0]->numberFormat]);

        $this->change->change(new SettingContext($this->company), 'presentation.date-format', SettingLevel::Company, 'ymd', null);
        $this->change->change(new SettingContext($this->company, userId: Uuid::v7()), 'presentation.number-format', SettingLevel::User, 'comma-dot', null);
        $this->print->pdf($this->company, $draft->getId());

        self::assertSame(['ymd', 'auto'], [$this->template->pages[1]->dateFormat, $this->template->pages[1]->numberFormat]);
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

    public function testAPdfFirstRenderedAfterTheCompanyMovedPrintsTheSellerAsItWasIssued(): void
    {
        $invoice = $this->issued($this->customer('standard', null));
        $this->company->reviseProfile(new CompanyProfile(legalName: 'Acme Holding', addressLine1: '99 avenue Nouvelle', city: 'Sousse'));
        $draft = $this->draft($this->customer('standard', null));

        $this->print->pdf($this->company, $invoice->getId());
        $this->print->pdf($this->company, $draft->getId());

        self::assertSame(['Acme', null], [$this->template->pages[0]->seller->name, $this->template->pages[0]->seller->address->line1]);
        self::assertSame(['Acme Holding', '99 avenue Nouvelle'], [$this->template->pages[1]->seller->name, $this->template->pages[1]->seller->address->line1], 'a draft prints the company as it is today');
    }

    public function testAPdfFirstRenderedAfterTheSettingsChangedPrintsTheNotesAndFormatsItWasIssuedWith(): void
    {
        $invoice = $this->issued($this->customer('standard', null), new PrintSettings('Virement à 30 jours.', 'ymd', 'dot-comma'));
        $company = new SettingContext($this->company);
        $this->change->change($company, 'document.printed_notes', SettingLevel::Company, 'Nouvelles notes', null);
        $this->change->change($company, 'presentation.date-format', SettingLevel::Company, 'dmy', null);
        $draft = $this->draft($this->customer('standard', null));

        $this->print->pdf($this->company, $invoice->getId());
        $this->print->pdf($this->company, $draft->getId());

        [$issued, $today] = $this->template->pages;
        self::assertSame(['Virement à 30 jours.', 'ymd', 'dot-comma'], [$issued->printedNotes, $issued->dateFormat, $issued->numberFormat]);
        self::assertSame(['Nouvelles notes', 'dmy', 'auto'], [$today->printedNotes, $today->dateFormat, $today->numberFormat], 'a draft prints today\'s settings');
    }

    public function testTheTotalIsWrittenOutUnlessTheSettingTurnsItOff(): void
    {
        $draft = $this->draft($this->customer('standard', null));
        $this->print->pdf($this->company, $draft->getId());
        $words = $this->template->pages[0]->amountInWords;
        self::assertNotNull($words, 'on by default on invoices');
        self::assertStringContainsString('dinars', $words);

        $this->change->change(new SettingContext($this->company), 'document.amount_in_words', SettingLevel::Company, false, null);
        $this->print->pdf($this->company, $draft->getId());

        self::assertNull($this->template->pages[1]->amountInWords);
    }

    public function testAnIssuedInvoiceKeepsWhetherItWroteTheTotalOutWhateverTheSettingSaysByThen(): void
    {
        $invoice = $this->issued($this->customer('standard', null), new PrintSettings('', 'auto', 'auto', true));
        $this->change->change(new SettingContext($this->company), 'document.amount_in_words', SettingLevel::Company, false, null);

        $this->print->pdf($this->company, $invoice->getId());

        self::assertNotNull($this->template->pages[0]->amountInWords);
    }

    public function testWhatTheDiscountsSaveIsPrintedOnlyWhenTheCompanyAsksForIt(): void
    {
        $draft = $this->draft($this->customer('standard', null), '10');
        $this->print->pdf($this->company, $draft->getId());
        self::assertNull($this->template->pages[0]->savings, 'off by default');

        $this->change->change(new SettingContext($this->company), 'document.savings_line', SettingLevel::Company, true, null);
        $this->print->pdf($this->company, $draft->getId());

        self::assertSame('1.000', $this->template->pages[1]->savings);
    }

    public function testTheLinesAreCountedInEachUnitOnceThereAreSeveral(): void
    {
        $this->print->pdf($this->company, $this->draft($this->customer('standard', null), null, 3)->getId());
        $this->print->pdf($this->company, $this->draft($this->customer('standard', null))->getId());

        [$several, $one] = $this->template->pages;
        self::assertCount(1, $several->quantities);
        self::assertSame('3', $several->quantities[0]->quantity);
        self::assertSame([], $one->quantities, 'one line already says it');
    }

    public function testADocumentWithoutADiscountPrintsNoSavingsLineEvenWhenAsked(): void
    {
        $this->change->change(new SettingContext($this->company), 'document.savings_line', SettingLevel::Company, true, null);
        $draft = $this->draft($this->customer('standard', null));

        $this->print->pdf($this->company, $draft->getId());

        self::assertNull($this->template->pages[0]->savings);
    }

    public function testAnIssuedInvoiceKeepsItsSavingsLineWhateverTheSettingSaysByThen(): void
    {
        $invoice = $this->issued($this->customer('standard', null), new PrintSettings('', 'auto', 'auto', savingsLine: true), '10');

        $this->print->pdf($this->company, $invoice->getId());

        self::assertSame('1.000', $this->template->pages[0]->savings);
    }

    public function testACreditNotePrintsNoSavingsLine(): void
    {
        $invoice = $this->issued($this->customer('standard', null), new PrintSettings('', 'auto', 'auto', savingsLine: true), '10');
        $credit = Invoice::creditNoteFor($invoice, 'Retour', $this->clock->now());
        $credit->issue(new InvoiceIssue('AV-2026-00001', new \DateTimeImmutable('2026-09-15'), 0, 'fr', [], null, null, null, new PrintSettings('', 'auto', 'auto', savingsLine: true), $this->keptNames($credit, 'fr')), fn (Invoice $issuing) => $this->totals->issued($issuing), $this->clock->now());
        $this->invoices->save($credit);

        $this->print->pdf($this->company, $credit->getId());

        self::assertNull($this->template->pages[0]->savings);
    }

    public function testHowToPayIsPrintedOnInvoicesUnlessTheSettingTurnsItOff(): void
    {
        $draft = $this->draft($this->customer('standard', null));
        $this->print->pdf($this->company, $draft->getId());
        self::assertTrue($this->template->pages[0]->howToPay, 'on by default');

        $this->change->change(new SettingContext($this->company), 'document.how_to_pay', SettingLevel::Company, false, null);
        $this->print->pdf($this->company, $draft->getId());

        self::assertFalse($this->template->pages[1]->howToPay);
    }

    public function testAnIssuedInvoiceKeepsWhetherItPrintedHowToPayWhateverTheSettingSaysByThen(): void
    {
        $kept = $this->issued($this->customer('standard', null), new PrintSettings('', 'auto', 'auto', false, true));
        $this->change->change(new SettingContext($this->company), 'document.how_to_pay', SettingLevel::Company, false, null);

        $this->print->pdf($this->company, $kept->getId());

        self::assertTrue($this->template->pages[0]->howToPay);
    }

    public function testAnInvoiceIssuedBeforeTheSettingExistedPrintsNoPaymentBlock(): void
    {
        $older = $this->issued($this->customer('standard', null));

        $this->print->pdf($this->company, $older->getId());

        self::assertFalse($this->template->pages[0]->howToPay, 'the setting is on today, the document was issued without it');
    }

    public function testADuplicateIsASecondOutputAndLeavesTheStoredOriginalAlone(): void
    {
        $invoice = $this->issued($this->customer('standard', null));
        $original = $this->print->pdf($this->company, $invoice->getId());
        $stored = $invoice->getPdfFile();
        $this->clock->sleep(86400);

        $copy = $this->print->copy($this->company, $invoice->getId(), InvoiceCopy::Duplicate);

        $page = array_last($this->template->pages);
        self::assertNotNull($page);
        self::assertSame([InvoiceCopy::Duplicate, '2026-09-16', null], [$page->copy, $page->copiedOn?->format('Y-m-d'), $page->paidStamp]);
        self::assertNotSame($original->contents, $copy->contents, 'a second output, never the stored bytes');
        self::assertSame($stored, $invoice->getPdfFile(), 'the original stays what issuing stored');
        self::assertStringContainsString('duplicate', $copy->contents);
    }

    public function testThePaidStampIsOffUntilTheCompanyTurnsItOn(): void
    {
        $invoice = $this->issued($this->customer('standard', null));
        $invoice->recordPayment(new PaymentDetails(new \DateTimeImmutable('2026-09-15'), $this->totals->figures($invoice)->amountDue, PaymentMethod::Cash), new \DateTimeImmutable('2026-09-15'), 3, null, $this->clock->now());

        $this->print->copy($this->company, $invoice->getId(), InvoiceCopy::UpToDate);

        self::assertSame(InvoiceStatus::Paid, $invoice->getStatus());
        self::assertNull($this->template->pages[0]->paidStamp, 'a paid invoice, but the stamp is off by default');
    }

    public function testAnInvoiceACreditNoteClosedIsSettledNeverPaid(): void
    {
        $this->stampOn();
        $invoice = $this->issued($this->customer('standard', null));
        $this->creditInFull($invoice);

        $this->print->copy($this->company, $invoice->getId(), InvoiceCopy::UpToDate);

        self::assertSame([InvoiceStatus::Paid, '0.000'], [$invoice->getStatus(), $invoice->getIssuedFigures()?->amountDue], 'closed with no money paid');
        self::assertSame('settled', $this->template->pages[0]->paidStamp, 'not « Acquittée », which says money was paid');
    }

    public function testAnInvoicePartlyPaidAndClosedByACreditNoteIsSettled(): void
    {
        $this->stampOn();
        $invoice = $this->issued($this->customer('standard', null));
        $invoice->recordPayment(new PaymentDetails(new \DateTimeImmutable('2026-09-15'), '1', PaymentMethod::Cash), new \DateTimeImmutable('2026-09-15'), 3, null, $this->clock->now());
        $this->creditInFull($invoice);

        $this->print->copy($this->company, $invoice->getId(), InvoiceCopy::UpToDate);

        self::assertSame('settled', $this->template->pages[0]->paidStamp, 'what closed it was the credit note');
    }

    public function testAnUpToDateCopyStampsWhatTheInvoiceHasBecome(): void
    {
        $this->stampOn();
        $invoice = $this->issued($this->customer('standard', null));
        $this->print->copy($this->company, $invoice->getId(), InvoiceCopy::UpToDate);
        self::assertNull($this->template->pages[0]->paidStamp, 'nothing paid, no stamp');

        $invoice->recordPayment(new PaymentDetails(new \DateTimeImmutable('2026-09-15'), '1', PaymentMethod::Cash), new \DateTimeImmutable('2026-09-15'), 3, null, $this->clock->now());
        $this->print->copy($this->company, $invoice->getId(), InvoiceCopy::UpToDate);
        self::assertSame('partial', $this->template->pages[1]->paidStamp);

        $rest = $this->totals->figures($invoice)->amountDue;
        $invoice->recordPayment(new PaymentDetails(new \DateTimeImmutable('2026-09-15'), $rest, PaymentMethod::Cash), new \DateTimeImmutable('2026-09-15'), 3, null, $this->clock->now());
        $this->print->copy($this->company, $invoice->getId(), InvoiceCopy::UpToDate);
        self::assertSame('paid', $this->template->pages[2]->paidStamp);
        self::assertSame(InvoiceCopy::UpToDate, $this->template->pages[2]->copy);
    }

    public function testADuplicateNeverCarriesAPaidStampEvenOnAPaidInvoice(): void
    {
        $this->stampOn();
        $invoice = $this->issued($this->customer('standard', null));
        $invoice->recordPayment(new PaymentDetails(new \DateTimeImmutable('2026-09-15'), '1', PaymentMethod::Cash), new \DateTimeImmutable('2026-09-15'), 3, null, $this->clock->now());

        $this->print->copy($this->company, $invoice->getId(), InvoiceCopy::Duplicate);

        self::assertNull($this->template->pages[0]->paidStamp, 'a duplicate shows the document as issued');
    }

    public function testADraftHasNoCopy(): void
    {
        $draft = $this->draft($this->customer('standard', null));

        $this->expectException(NoCopyOfADraft::class);
        $this->print->copy($this->company, $draft->getId(), InvoiceCopy::Duplicate);
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

    private function draft(Customer $customer, ?string $discountRate = null, int $lineCount = 1): Invoice
    {
        $unit = $this->units->ofCodeInCompany('C62', $this->company->getId());
        $vat = $this->taxes->ofCodeInCompany('TVA19', $this->company->getId());
        $stamp = $this->taxes->ofCodeInCompany('TIMBRE', $this->company->getId());
        self::assertNotNull($unit);
        self::assertNotNull($vat);
        self::assertNotNull($stamp);
        $taxes = [] === $customer->getTaxRegime()->getExcludedFamilies() ? [$vat] : [];
        $invoice = Invoice::create($this->company, $this->establishments->ofCompany($this->company->getId())[0], $customer, new InvoiceHeader(), array_fill(
            0,
            $lineCount,
            new InvoiceLineDetails(null, 'Pièce', '1', $unit, '10', $discountRate, $taxes),
        ), [$stamp], $this->clock->now());
        $this->invoices->save($invoice);

        return $invoice;
    }

    private function issued(Customer $customer, PrintSettings $print = new PrintSettings('', 'auto', 'auto'), ?string $discountRate = null): Invoice
    {
        $invoice = $this->draft($customer, $discountRate);
        $invoice->issue(
            new InvoiceIssue('FAC-2026-00001', new \DateTimeImmutable('2026-09-15'), 30, 'en', $customer->getTaxRegime()->getMentionKey() ? [$customer->getTaxRegime()->getMentionKey()] : [], null, 'Merci', null, $print, $this->keptNames($invoice, 'en')),
            fn (Invoice $issuing) => $this->totals->issued($issuing),
            $this->clock->now(),
        );
        $invoice->releaseEvents();

        return $invoice;
    }

    /** @return array{string, string} the piece's and the stamp's names in that language, as they are now */
    private function namesNow(string $language): array
    {
        $unit = $this->units->ofCodeInCompany('C62', $this->company->getId());
        $stamp = $this->taxes->ofCodeInCompany('TIMBRE', $this->company->getId());
        self::assertNotNull($unit);
        self::assertNotNull($stamp);

        return [$unit->nameIn($language), $stamp->nameIn($language)];
    }

    private function renameUnitAndStamp(): void
    {
        $unit = $this->units->ofCodeInCompany('C62', $this->company->getId());
        $stamp = $this->taxes->ofCodeInCompany('TIMBRE', $this->company->getId());
        self::assertNotNull($unit);
        self::assertNotNull($stamp);
        self::assertTrue($unit->revise($unit->getName(), $unit->getDecimals(), true, $unit->getSortOrder(), $this->clock->now(), [...$unit->getNames(), 'en' => 'Renamed piece']));
        self::assertTrue($stamp->revise($stamp->getName(), $stamp->getRate(), $stamp->getAmount(), $stamp->getThreshold(), $stamp->entersVatBase(), $stamp->isDefault(), true, $stamp->getExemptionMention(), $stamp->getSortOrder(), 3, $this->clock->now(), [...$stamp->getNames(), 'en' => 'Renamed stamp']));
    }

    /** What issuing keeps of the names, in that language, as DocumentNames would give it. */
    private function keptNames(Invoice $invoice, string $language): PrintedNames
    {
        return PrintedNames::in($language, array_map(static fn (InvoiceLine $line): Unit => $line->getUnit(), $invoice->getLines()), $this->taxes->ofCompany($this->company->getId()));
    }

    private function stampOn(): void
    {
        $this->change->change(new SettingContext($this->company), 'document.paid_stamp', SettingLevel::Company, true, null);
    }

    private function creditInFull(Invoice $invoice): void
    {
        $credit = Invoice::creditNoteFor($invoice, 'Retour', $this->clock->now());
        $credit->issue(new InvoiceIssue('AV-2026-00001', new \DateTimeImmutable('2026-09-15'), 0, 'fr', [], null, null, null, new PrintSettings('', 'auto', 'auto'), $this->keptNames($credit, 'fr')), fn (Invoice $issuing) => $this->totals->issued($issuing), $this->clock->now());
        $invoice->credit($credit, $this->clock->now());
    }

    private function customer(string $regime, ?string $mentionKey): Customer
    {
        $now = $this->clock->now();

        return Customer::create($this->company, 'CLI-0001', new CustomerProfile(CustomerKind::Company, 'Carthage Conseil'), null, new CustomerTaxRegime('TN', $regime, 'fiscal.regime.'.$regime, null === $mentionKey ? [] : [TaxFamily::Vat], $mentionKey, 0, $now), [], $now);
    }
}
