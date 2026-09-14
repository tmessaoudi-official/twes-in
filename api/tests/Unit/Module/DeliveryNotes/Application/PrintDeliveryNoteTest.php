<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Module\DeliveryNotes\Application;

use App\Files\Application\Files;
use App\Fiscal\Application\Company\ProvisionCompany;
use App\Fiscal\Domain\CustomerTaxRegime;
use App\Module\Customers\Domain\Customer;
use App\Module\Customers\Domain\CustomerKind;
use App\Module\Customers\Domain\CustomerProfile;
use App\Module\DeliveryNotes\Application\DeliveryNoteNotFound;
use App\Module\DeliveryNotes\Application\DeliveryNotePage;
use App\Module\DeliveryNotes\Application\DeliveryNoteSettings;
use App\Module\DeliveryNotes\Application\DeliveryNoteTotals;
use App\Module\DeliveryNotes\Application\PrintDeliveryNote;
use App\Module\DeliveryNotes\Domain\DeliveryNote;
use App\Module\DeliveryNotes\Domain\DeliveryNoteHeader;
use App\Module\DeliveryNotes\Domain\DeliveryNoteLineDetails;
use App\Settings\Application\BusinessDefaultSettings;
use App\Settings\Application\ChangeSettings;
use App\Settings\Application\ReadSetting;
use App\Settings\Application\ResolveSettings;
use App\Settings\Application\SettingCatalog;
use App\Settings\Application\SettingContext;
use App\Settings\Domain\SettingLevel;
use App\Shared\Application\PdfRenderingFailed;
use App\Tenancy\Domain\Company;
use App\Tests\Support\FakePdfRenderer;
use App\Tests\Support\InMemoryAuditTrail;
use App\Tests\Support\InMemoryDeliveryNotes;
use App\Tests\Support\InMemoryEstablishments;
use App\Tests\Support\InMemoryFileStorage;
use App\Tests\Support\InMemoryNumberingSeries;
use App\Tests\Support\InMemorySettings;
use App\Tests\Support\InMemoryStoredFiles;
use App\Tests\Support\InMemoryTaxComponents;
use App\Tests\Support\InMemoryUnits;
use App\Tests\Support\RecordingDeliveryNoteTemplate;
use App\Tests\Support\ShippedFiscalPresets;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class PrintDeliveryNoteTest extends TestCase
{
    private MockClock $clock;
    private InMemoryUnits $units;
    private InMemoryEstablishments $establishments;
    private InMemoryDeliveryNotes $notes;
    private InMemoryFileStorage $storage;
    private InMemoryStoredFiles $records;
    private FakePdfRenderer $renderer;
    private RecordingDeliveryNoteTemplate $template;
    private ChangeSettings $change;
    private PrintDeliveryNote $print;
    private Company $company;
    private Customer $customer;

    protected function setUp(): void
    {
        $this->clock = new MockClock('2026-09-15 09:00:00');
        $this->units = new InMemoryUnits();
        $this->establishments = new InMemoryEstablishments();
        new ProvisionCompany(ShippedFiscalPresets::presets(), new InMemoryTaxComponents(), $this->units, $this->establishments, new InMemoryNumberingSeries(), ShippedFiscalPresets::scales(), $this->clock)
            ->handle($this->company = new Company('Acme', 'TN', 'TND', 'fr', 'Africa/Tunis'));
        $this->notes = new InMemoryDeliveryNotes();
        $this->storage = new InMemoryFileStorage();
        $this->records = new InMemoryStoredFiles();
        $this->renderer = new FakePdfRenderer();
        $this->template = new RecordingDeliveryNoteTemplate();
        $settings = new InMemorySettings();
        $catalog = new SettingCatalog([new BusinessDefaultSettings(), new DeliveryNoteSettings()]);
        $resolve = new ResolveSettings($catalog, $settings);
        $this->change = new ChangeSettings($catalog, $settings, $resolve, new InMemoryAuditTrail(), $this->clock);
        $this->print = new PrintDeliveryNote(
            $this->notes,
            new DeliveryNoteTotals(ShippedFiscalPresets::presets(), ShippedFiscalPresets::scales()),
            $this->template,
            $this->renderer,
            new Files($this->storage, $this->records, $this->clock),
            new ReadSetting($resolve),
        );
        $now = $this->clock->now();
        $this->customer = Customer::create($this->company, 'CLI-0001', new CustomerProfile(CustomerKind::Company, 'Carthage Conseil'), null, new CustomerTaxRegime('TN', 'standard', 'fiscal.regime.standard', [], null, 0, $now), [], $now);
    }

    public function testADraftIsRenderedOnRequestWatermarkedAndNeverStored(): void
    {
        $draft = $this->draft();

        $printed = $this->print->pdf($this->company, $draft->getId());

        self::assertSame('delivery-note-'.$draft->getId()->toRfc4122().'.pdf', $printed->fileName);
        self::assertStringStartsWith('%PDF-', $printed->contents);
        self::assertCount(1, $this->template->pages);
        $page = $this->template->pages[0];
        self::assertSame([$draft, DeliveryNotePage::DRAFT, true, 'fr', ''], [$page->note, $page->watermark, $page->showPrices, $page->language, $page->printedNotes]);
        self::assertSame('200.000', $page->totals->total);
        self::assertSame([[], [], null], [$this->records->files, $this->storage->contents, $draft->getPdfFile()]);
    }

    public function testAValidatedNoteIsStoredOnceThenServedAsStored(): void
    {
        $note = $this->validated();

        $this->print->storeIssued($this->company->getId(), $note->getId());
        $this->print->storeIssued($this->company->getId(), $note->getId());

        self::assertCount(1, $this->records->files, 'a note keeps the one PDF it was issued with');
        $file = $this->records->files[0];
        self::assertSame([$file, 'BL-2026-00001.pdf', 'application/pdf'], [$note->getPdfFile(), $file->getOriginalName(), $file->getMime()]);
        self::assertNull($this->template->pages[0]->watermark);

        $printed = $this->print->pdf($this->company, $note->getId());

        self::assertSame('BL-2026-00001.pdf', $printed->fileName);
        self::assertSame($this->storage->contents[$file->getStorageKey()], $printed->contents);
        self::assertCount(1, $this->renderer->rendered, 'a stored PDF is served, never rendered again');

        $this->expectException(DeliveryNoteNotFound::class);
        $this->print->pdf(new Company('Globex', 'TN', 'TND', 'fr', 'Africa/Tunis'), $note->getId());
    }

    public function testWhenTheRendererFailedAtIssueTheFirstDownloadStoresThePdf(): void
    {
        $note = $this->validated();
        $this->renderer->failing = true;
        try {
            $this->print->storeIssued($this->company->getId(), $note->getId());
            self::fail('a PDF was stored without being rendered');
        } catch (PdfRenderingFailed) {
        }
        self::assertSame([[], null], [$this->records->files, $note->getPdfFile()]);

        $this->renderer->failing = false;
        $printed = $this->print->pdf($this->company, $note->getId());

        self::assertCount(1, $this->records->files);
        self::assertSame($this->records->files[0], $note->getPdfFile());
        self::assertSame($this->storage->contents[$this->records->files[0]->getStorageKey()], $printed->contents);
    }

    public function testTheCustomersSettingsChooseWhetherPricesShowAndTheLanguage(): void
    {
        $atCustomer = new SettingContext($this->company, customerId: $this->customer->getId());
        $this->change->change($atCustomer, 'delivery_note.show_prices', SettingLevel::Customer, false, null);
        $this->change->change($atCustomer, 'document.language', SettingLevel::Customer, 'en', null);
        $this->change->change(new SettingContext($this->company), 'document.printed_notes', SettingLevel::Company, 'Goods travel at the customer\'s risk.', null);

        $this->print->pdf($this->company, $this->draft()->getId());

        $page = $this->template->pages[0];
        self::assertSame([false, 'en', 'Goods travel at the customer\'s risk.'], [$page->showPrices, $page->language, $page->printedNotes]);
    }

    public function testACancelledNoteIsRenderedStampedAndWhatWasStoredStays(): void
    {
        $note = $this->validated();
        $this->print->storeIssued($this->company->getId(), $note->getId());
        $stored = $this->storage->contents;
        $note->cancel($this->clock->now());

        $printed = $this->print->pdf($this->company, $note->getId());

        self::assertSame(DeliveryNotePage::CANCELLED, $this->template->pages[1]->watermark);
        self::assertStringContainsString('cancelled', $printed->contents);
        self::assertSame([$stored, 1], [$this->storage->contents, \count($this->records->files)]);
    }

    private function draft(): DeliveryNote
    {
        $unit = $this->units->ofCodeInCompany('C62', $this->company->getId());
        self::assertNotNull($unit);
        $note = DeliveryNote::create($this->company, $this->establishments->ofCompany($this->company->getId())[0], $this->customer, new DeliveryNoteHeader(), [
            new DeliveryNoteLineDetails(null, 'Pièce', '2', $unit, '100', []),
        ], $this->clock->now());
        $this->notes->save($note);

        return $note;
    }

    private function validated(): DeliveryNote
    {
        $note = $this->draft();
        $note->validate('BL-2026-00001', new \DateTimeImmutable('2026-09-15'), $this->clock->now());
        $note->releaseEvents();

        return $note;
    }
}
