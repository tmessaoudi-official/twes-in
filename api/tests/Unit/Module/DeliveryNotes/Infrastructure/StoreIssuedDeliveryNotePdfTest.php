<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Module\DeliveryNotes\Infrastructure;

use App\Files\Application\Files;
use App\Fiscal\Application\Company\ProvisionCompany;
use App\Fiscal\Domain\CustomerTaxRegime;
use App\Module\Customers\Domain\Customer;
use App\Module\Customers\Domain\CustomerKind;
use App\Module\Customers\Domain\CustomerProfile;
use App\Module\DeliveryNotes\Application\DeliveryNoteSettings;
use App\Module\DeliveryNotes\Application\DeliveryNoteTotals;
use App\Module\DeliveryNotes\Application\PrintDeliveryNote;
use App\Module\DeliveryNotes\Domain\DeliveryNote;
use App\Module\DeliveryNotes\Domain\DeliveryNoteHeader;
use App\Module\DeliveryNotes\Domain\DeliveryNoteLineDetails;
use App\Module\DeliveryNotes\Domain\DeliveryNoteValidated;
use App\Module\DeliveryNotes\Infrastructure\Pdf\StoreIssuedDeliveryNotePdf;
use App\Settings\Application\BusinessDefaultSettings;
use App\Settings\Application\PresentationSettings;
use App\Settings\Application\ReadSetting;
use App\Settings\Application\ResolveSettings;
use App\Settings\Application\SettingCatalog;
use App\Tenancy\Domain\Company;
use App\Tests\Support\FakePdfRenderer;
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
use Psr\Log\AbstractLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

final class StoreIssuedDeliveryNotePdfTest extends TestCase
{
    public function testTheIssuedPdfIsStoredAndARenderingFailureIsLoggedWithoutUndoingTheValidation(): void
    {
        $clock = new MockClock('2026-09-15 09:00:00');
        $units = new InMemoryUnits();
        $establishments = new InMemoryEstablishments();
        $company = new Company('Acme', 'TN', 'TND', 'fr', 'Africa/Tunis');
        new ProvisionCompany(ShippedFiscalPresets::presets(), new InMemoryTaxComponents(), $units, $establishments, new InMemoryNumberingSeries(), ShippedFiscalPresets::scales(), $clock)->handle($company);
        $notes = new InMemoryDeliveryNotes();
        $records = new InMemoryStoredFiles();
        $renderer = new FakePdfRenderer();
        $print = new PrintDeliveryNote(
            $notes,
            new DeliveryNoteTotals(ShippedFiscalPresets::presets(), ShippedFiscalPresets::scales()),
            new RecordingDeliveryNoteTemplate(),
            $renderer,
            new Files(new InMemoryFileStorage(), $records, $clock),
            new ReadSetting(new ResolveSettings(new SettingCatalog([new BusinessDefaultSettings(), new DeliveryNoteSettings(), new PresentationSettings()]), new InMemorySettings())),
        );
        $logger = new class extends AbstractLogger {
            /** @var list<array{string, string, array<array-key, mixed>}> */
            public array $records = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->records[] = [\is_string($level) ? $level : get_debug_type($level), (string) $message, $context];
            }
        };
        $now = $clock->now();
        $customer = Customer::create($company, 'CLI-0001', new CustomerProfile(CustomerKind::Company, 'Carthage Conseil'), null, new CustomerTaxRegime('TN', 'standard', 'fiscal.regime.standard', [], null, 0, $now), [], $now);
        $unit = $units->ofCodeInCompany('C62', $company->getId());
        self::assertNotNull($unit);
        $note = DeliveryNote::create($company, $establishments->ofCompany($company->getId())[0], $customer, new DeliveryNoteHeader(), [new DeliveryNoteLineDetails(null, 'Pièce', '1', $unit, '10', [])], $now);
        $note->validate('BL-2026-00001', new \DateTimeImmutable('2026-09-15'), $now);
        $notes->save($note);
        $event = $note->releaseEvents()[0];
        self::assertInstanceOf(DeliveryNoteValidated::class, $event);
        $listener = new StoreIssuedDeliveryNotePdf($print, $logger);

        $renderer->failing = true;
        $listener($event);

        self::assertCount(0, $records->files);
        self::assertCount(1, $logger->records);
        [$level, , $context] = $logger->records[0];
        self::assertSame(['warning', 'BL-2026-00001'], [$level, $context['number'] ?? null]);

        $renderer->failing = false;
        $listener($event);

        self::assertCount(1, $records->files);
        self::assertSame($records->files[0], $note->getPdfFile());
        self::assertCount(1, $logger->records);
    }

    public function testItListensToEveryValidation(): void
    {
        $listeners = new \ReflectionClass(StoreIssuedDeliveryNotePdf::class)->getAttributes(AsEventListener::class);

        self::assertCount(1, $listeners);
        self::assertSame(DeliveryNoteValidated::class, $listeners[0]->newInstance()->event);
    }
}
