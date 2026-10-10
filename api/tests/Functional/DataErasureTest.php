<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Erasure\Application\EndDueErasures;
use App\Erasure\Application\UndoErasure;
use App\Erasure\Domain\ErasureNoLongerPending;
use App\Files\Application\FileStorage;
use App\Files\Application\StoredFileMissing;
use App\Fiscal\Application\Company\ProvisionCompany;
use App\Fiscal\Application\Regime\SyncCustomerTaxRegimes;
use App\Fiscal\Domain\CustomerTaxRegimeRepository;
use App\Fiscal\Domain\UnitRepository;
use App\Identity\Domain\Email;
use App\Identity\Domain\User;
use App\Module\Customers\Domain\Customer;
use App\Module\Customers\Domain\CustomerKind;
use App\Module\Customers\Domain\CustomerProfile;
use App\Module\Products\Domain\Product;
use App\Module\Products\Domain\ProductDetails;
use App\Module\Products\Domain\ProductKind;
use App\Module\Recurring\Application\ManageRecurringInvoices;
use App\Module\Recurring\Domain\RecurringFrequency;
use App\Settings\Domain\Setting;
use App\Settings\Domain\SettingAddress;
use App\Tenancy\Domain\Company;
use App\Tenancy\Domain\EstablishmentRepository;
use App\Tests\Unit\Files\Application\AttachmentsTest;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

/**
 * « Effacer des données »: the owner, having just proved who they are, takes a part of the company's data away for every
 * member at once, and may put it all back for 24 hours; then the copy goes, and the files only it named with it.
 */
final class DataErasureTest extends ApiTestCase
{
    private const string PASSWORD = 'password-1234';
    /** Every table the two parts of this slice reach, compared row for row before an erasure and after its undo. */
    private const array TABLES = [
        'venue_area', 'venue_spot', 'venue_structure', 'stock_location',
        'invoice', 'invoice_line', 'invoice_line_tax', 'invoice_tax',
        'delivery_note', 'delivery_note_line', 'delivery_note_line_tax',
        'quote', 'quote_line', 'quote_line_tax', 'attachment', 'file',
    ];

    private Company $company;
    private string $establishmentId;
    private string $customerId;
    private string $productId;
    private ?string $otherCompanyId = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = $this->createCompany('Quincaillerie');
        static::getContainer()->get(ProvisionCompany::class)->handle($this->company);
        static::getContainer()->get(SyncCustomerTaxRegimes::class)->handle();
        $this->establishmentId = static::getContainer()->get(EstablishmentRepository::class)->ofCompany($this->company->getId())[0]->getId()->toRfc4122();
        $this->em()->persist(new Setting(SettingAddress::company($this->company), 'article.stock_tracking', true, new \DateTimeImmutable()));
        $regime = static::getContainer()->get(CustomerTaxRegimeRepository::class)->ofPresetAndCode('TN', 'standard');
        self::assertNotNull($regime);
        $customer = Customer::create($this->company, 'CLI-0001', new CustomerProfile(CustomerKind::Company, 'Carthage Conseil'), null, $regime, [], new \DateTimeImmutable());
        $this->em()->persist($customer);
        $unit = static::getContainer()->get(UnitRepository::class)->ofCodeInCompany('C62', $this->company->getId());
        self::assertNotNull($unit);
        $product = Product::create($this->company, 'ART-001', new ProductDetails('Perceuse', null, ProductKind::Goods, '120'), $unit, null, [], new \DateTimeImmutable());
        $this->em()->persist($product);
        $this->em()->flush();
        $this->customerId = $customer->getId()->toRfc4122();
        $this->productId = $product->getId()->toRfc4122();
    }

    public function testThePageIsTheOwnersAndOpensOnlyOnAFreshProofOfWhoIsThere(): void
    {
        // A member holding every permission is still not the owner.
        $this->signedIn('admin@twes.local', 'admin');
        $this->getJson($this->path('data-erasure'));
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertSame('owner_only', $this->stringAt($this->json(), 'error'));
        $this->stepUp(self::PASSWORD);
        $this->postJson($this->path('data-erasures'), ['parts' => ['stock_map']]);
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertSame('owner_only', $this->stringAt($this->json(), 'error'));

        $this->signedIn('owner@twes.local', 'owner');
        $this->getJson($this->path('data-erasure'));
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertSame('step_up_required', $this->stringAt($this->json(), 'error'));
        $this->postJson($this->path('data-erasures'), ['parts' => ['stock_map']]);
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertSame('step_up_required', $this->stringAt($this->json(), 'error'));

        // The proof holds a few minutes: erasing past them asks again, though the page was opened in time.
        $this->stepUp(self::PASSWORD);
        $this->getJson($this->path('data-erasure'));
        self::assertResponseIsSuccessful();
        Clock::set(new MockClock(new \DateTimeImmutable('+6 minutes')));
        try {
            $this->postJson($this->path('data-erasures'), ['parts' => ['stock_map']]);
            self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
            self::assertSame('step_up_required', $this->stringAt($this->json(), 'error'));
        } finally {
            Clock::set(new NativeClock());
        }

        // Somebody of another company meets the same 404 as for any company of others.
        $other = $this->createCompany('Ailleurs');
        $this->createUser('stranger@twes.local', self::PASSWORD, $other);
        $this->login('stranger@twes.local', self::PASSWORD);
        $this->stepUp(self::PASSWORD);
        $this->getJson($this->path('data-erasure'));
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        self::assertSame(0, $this->rowsOf('SELECT COUNT(*) FROM data_erasure'));
    }

    public function testThePageCountsWhatEachPartWouldTakeAndWhatItKeeps(): void
    {
        $this->signedIn('owner@twes.local', 'owner');
        $this->stepUp(self::PASSWORD);
        $this->drawAFloor(0);
        $this->documents();

        $this->getJson($this->path('data-erasure'));

        self::assertResponseIsSuccessful();
        self::assertSame([
            ['part' => 'stock_map', 'counts' => ['floors' => 1, 'places' => 2, 'structures' => 1]],
            // Two of the four drafts stay: one is what a recurring invoice copies, the other an invoice already issued.
            ['part' => 'drafts', 'counts' => ['drafts' => 2, 'quotes' => 1]],
        ], $this->json()['parts']);
        self::assertNull($this->json()['pending']);

        $this->postJson($this->path('data-erasures'), ['parts' => ['stock_map', 'nothing_of_the_kind']]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame(['error' => 'unknown_part', 'part' => 'nothing_of_the_kind'], $this->json());
        $this->postJson($this->path('data-erasures'), ['parts' => []]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame('no_part', $this->stringAt($this->json(), 'error'));
    }

    public function testErasingTakesThePartsAwayAtOnceAndUndoPutsBackEveryRowAsItWas(): void
    {
        $this->signedIn('owner@twes.local', 'owner');
        $this->stepUp(self::PASSWORD);
        [$rack] = $this->drawAFloor(0);
        [, $quoteKept] = $this->documents();
        // Another company's plan and drafts are none of this erasure's business.
        $elsewhere = $this->otherCompanysRows();
        $before = $this->snapshot();
        // A line's section is one of its columns: it must come back with the line, title included.
        self::assertStringContainsString('"section": "Fournitures"', implode("\n", $before['invoice_line']));

        $this->getJson($this->path('data-erasure'));
        $this->postJson($this->path('data-erasures'), ['parts' => ['stock_map', 'drafts']]);

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $erasure = $this->json();
        self::assertSame(['stock_map', 'drafts'], $erasure['parts']);
        self::assertEqualsCanonicalizing(['venue_area', 'venue_spot', 'venue_structure', 'stock_location', 'invoice', 'payment', 'delivery_note', 'quote'], $erasure['kinds'], 'what this tab reads again itself');
        self::assertSame(['stock_map' => ['floors' => 1, 'places' => 2, 'structures' => 1], 'drafts' => ['drafts' => 2, 'quotes' => 1]], $erasure['counts']);
        $erasureId = $this->stringAt($erasure, 'id');
        self::assertEqualsWithDelta(new \DateTimeImmutable('+24 hours'), new \DateTimeImmutable($this->stringAt($erasure, 'effectiveAt')), 5);

        self::assertSame(0, $this->rowsOf("SELECT COUNT(*) FROM venue_area WHERE company_id = '{$this->companyId()}'"));
        self::assertSame(0, $this->rowsOf("SELECT COUNT(*) FROM venue_structure WHERE company_id = '{$this->companyId()}'"));
        self::assertSame(1, $this->rowsOf("SELECT COUNT(*) FROM stock_location WHERE id = '$rack' AND spot_id IS NULL"), 'the place itself stays, undrawn');
        self::assertSame(2, $this->rowsOf("SELECT COUNT(*) FROM invoice WHERE company_id = '{$this->companyId()}'"), 'the issued one and the recurring model');
        self::assertSame(0, $this->rowsOf("SELECT COUNT(*) FROM delivery_note WHERE company_id = '{$this->companyId()}'"));
        self::assertSame([$quoteKept], $this->texts("SELECT id::text FROM quote WHERE company_id = '{$this->companyId()}'"), 'the quote an issued invoice names stays');
        self::assertSame(0, $this->rowsOf("SELECT COUNT(*) FROM attachment WHERE company_id = '{$this->companyId()}'"));
        self::assertSame($elsewhere, $this->otherCompanysRows());

        // Written to the activity journal as what went, counted, never as the rows themselves.
        $changes = $this->text("SELECT changes::text FROM audit_log WHERE action = 'data.erased'");
        // A jsonb column orders an object's keys its own way: what matters is what each says.
        self::assertEquals(['parts' => ['stock_map', 'drafts'], 'counts' => ['stock_map' => ['floors' => 1, 'places' => 2, 'structures' => 1], 'drafts' => ['drafts' => 2, 'quotes' => 1]]], json_decode($changes, true));

        $this->getJson($this->path('data-erasures/pending'));
        self::assertResponseIsSuccessful();
        self::assertSame([$erasureId, ['stock_map', 'drafts']], [$this->json()['id'], $this->json()['parts']]);
        $this->getJson($this->path('data-erasure'));
        self::assertSame($erasureId, $this->stringAt($this->section($this->json(), 'pending'), 'id'));

        $this->postJson($this->path("data-erasures/$erasureId/undo"), null);

        self::assertResponseIsSuccessful();
        self::assertSame('undone', $this->stringAt($this->json(), 'state'));
        self::assertSame($before, $this->snapshot(), 'every row back as it was, the place drawn again');
        self::assertSame(0, $this->rowsOf('SELECT COUNT(*) FROM data_erasure_row'), 'and the copy gone');
        self::assertSame(1, $this->rowsOf("SELECT COUNT(*) FROM audit_log WHERE action = 'data.erasure_undone'"));
        $this->getJson($this->path('data-erasures/pending'));
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
        $this->postJson($this->path("data-erasures/$erasureId/undo"), null);
        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        self::assertSame('erasure_final', $this->stringAt($this->json(), 'error'));
    }

    public function testWhatIsMadeAfterThePageOpenedGoesWithTheRestAndComesBackWithIt(): void
    {
        $this->signedIn('owner@twes.local', 'owner');
        $this->stepUp(self::PASSWORD);
        $this->getJson($this->path('data-erasure'));
        $this->quote();
        $before = $this->snapshot();

        $this->postJson($this->path('data-erasures'), ['parts' => ['drafts']]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        self::assertSame(['drafts' => ['drafts' => 0, 'quotes' => 1]], $this->json()['counts']);
        self::assertSame(0, $this->rowsOf("SELECT COUNT(*) FROM quote_line WHERE company_id = '{$this->companyId()}'"));

        $this->postJson($this->path('data-erasures/'.$this->stringAt($this->json(), 'id').'/undo'), null);
        self::assertResponseIsSuccessful();
        self::assertSame($before, $this->snapshot());
    }

    public function testWhileOneErasureMayStillBeUndoneAnotherWaits(): void
    {
        $this->signedIn('owner@twes.local', 'owner');
        $this->stepUp(self::PASSWORD);
        $this->postJson($this->path('data-erasures'), ['parts' => ['stock_map']]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $first = $this->stringAt($this->json(), 'id');

        $this->postJson($this->path('data-erasures'), ['parts' => ['drafts']]);
        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        self::assertSame(['error' => 'erasure_pending', 'id' => $first], $this->json());

        $this->postJson($this->path("data-erasures/$first/undo"), null);
        self::assertResponseIsSuccessful();
        $this->postJson($this->path('data-erasures'), ['parts' => ['drafts']]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
    }

    public function testUndoRefusesNamingWhatWasMadeSinceInItsWay(): void
    {
        $this->signedIn('owner@twes.local', 'owner');
        $this->stepUp(self::PASSWORD);
        $this->drawAFloor(0);
        $this->postJson($this->path('data-erasures'), ['parts' => ['stock_map']]);
        $erasureId = $this->stringAt($this->json(), 'id');
        // A new ground floor takes the level the erased one stood on.
        $this->floor('Nouveau rez-de-chaussée', 0);

        $this->postJson($this->path("data-erasures/$erasureId/undo"), null);

        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        self::assertSame(['error' => 'erasure_conflict', 'table' => 'venue_area'], $this->json());
        self::assertSame(0, $this->rowsOf("SELECT COUNT(*) FROM venue_spot WHERE company_id = '{$this->companyId()}'"), 'nothing came back by halves');
        self::assertSame(['pending', 1], [$this->text("SELECT state FROM data_erasure WHERE id = '$erasureId'"), $this->rowsOf("SELECT COUNT(*) FROM data_erasure_row WHERE erasure_id = '$erasureId' AND table_name = 'venue_area'")]);
    }

    public function testAfterTwentyFourHoursNothingComesBackAndTheFilesOnlyTheCopyNamedGo(): void
    {
        $this->signedIn('owner@twes.local', 'owner');
        $this->stepUp(self::PASSWORD);
        $quote = $this->quote();
        $this->uploadFile($this->path("quotes/$quote/attachments"), 'plan.pdf', AttachmentsTest::PDF);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $key = $this->text("SELECT f.storage_key FROM file f JOIN attachment a ON a.file_id = f.id WHERE a.entity_id = '$quote'");
        $this->postJson($this->path('data-erasures'), ['parts' => ['drafts']]);
        $erasureId = $this->stringAt($this->json(), 'id');
        self::assertSame(1, $this->rowsOf("SELECT COUNT(*) FROM file WHERE storage_key = '$key'"), 'kept while the erasure may be undone');

        // A sign-in does not outlive twelve hours, so the use case is asked directly once the clock has moved.
        Clock::set(new MockClock(new \DateTimeImmutable('+24 hours')));
        try {
            $company = $this->em()->find(Company::class, $this->company->getId()) ?? self::fail('the company vanished');
            $owner = $this->em()->getRepository(User::class)->findOneBy(['email' => Email::fromString('owner@twes.local')]) ?? self::fail('the owner vanished');
            try {
                static::getContainer()->get(UndoErasure::class)->handle($company, $owner->getId(), Uuid::fromString($erasureId));
                self::fail('the end is its time, not the purge passing');
            } catch (ErasureNoLongerPending) {
            }

            static::getContainer()->get(EndDueErasures::class)->handle();
            static::getContainer()->get(EndDueErasures::class)->handle();
        } finally {
            Clock::set(new NativeClock());
        }

        self::assertSame('final', $this->text("SELECT state FROM data_erasure WHERE id = '$erasureId'"));
        self::assertSame(1, $this->rowsOf("SELECT COUNT(*) FROM audit_log WHERE action = 'data.erasure_final' AND entity_id = '$erasureId'"), 'ended once, on the record');
        self::assertSame(0, $this->rowsOf('SELECT COUNT(*) FROM data_erasure_row'));
        self::assertSame(0, $this->rowsOf("SELECT COUNT(*) FROM file WHERE storage_key = '$key'"));
        try {
            static::getContainer()->get(FileStorage::class)->read($key);
            self::fail('the stored bytes went with their record');
        } catch (StoredFileMissing) {
        }
        $this->getJson($this->path('data-erasures/pending'));
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
    }

    /**
     * A floor with a rack and a bin drawn on it, and a wall.
     *
     * @return array{string, string} the rack's and the bin's locations
     */
    private function drawAFloor(int $level): array
    {
        $floor = $this->floor('Rez-de-chaussée', $level);
        $this->getJson($this->path('stock-locations'));
        $site = $this->stringAt($this->jsonList()[0], 'id');
        $places = [];
        foreach (['R1', 'R2'] as $code) {
            $this->postJson($this->path('stock-locations'), ['establishmentId' => $this->establishmentId, 'parentId' => $site, 'kind' => 'rack', 'code' => $code, 'name' => $code]);
            self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
            $places[] = $location = $this->stringAt($this->json(), 'id');
            $this->postJson($this->path("stock-floors/$floor/drawings"), ['locationId' => $location, 'x' => '1', 'y' => '1', 'width' => '1.2', 'depth' => '4', 'rotation' => 0, 'height' => '2']);
            self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        }
        $this->postJson($this->path("stock-floors/$floor/structures"), ['kind' => 'wall', 'name' => 'Mur nord', 'x' => '0', 'y' => '0', 'width' => '24', 'depth' => '0.2', 'rotation' => 0, 'height' => '3']);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        return [$places[0], $places[1]];
    }

    private function floor(string $name, int $level): string
    {
        $this->postJson($this->path('stock-floors'), ['establishmentId' => $this->establishmentId, 'name' => $name, 'level' => $level, 'widthMetres' => '24', 'depthMetres' => '15']);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        return $this->stringAt($this->json(), 'id');
    }

    /**
     * Two drafts that go (an invoice and a delivery note), two that stay (a recurring invoice's model, and an invoice
     * marked issued here), a quote that goes and one an issued invoice names.
     *
     * @return array{string, string} the quote that goes and the one that stays
     */
    private function documents(): array
    {
        $this->invoice();
        $this->postJson($this->path('delivery-notes'), ['customerId' => $this->customerId, 'establishmentId' => null, 'deliveryDate' => null, 'deliveryAddressLine1' => null, 'deliveryAddressLine2' => null, 'deliveryPostalCode' => null, 'deliveryCity' => null, 'deliveryCountryCode' => null, 'customerReference' => null, 'remarksPrinted' => null, 'notesInternal' => null, 'lines' => [['productId' => $this->productId, 'quantity' => '1']]]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        $model = $this->invoice();
        $company = $this->em()->find(Company::class, $this->company->getId()) ?? self::fail('the company vanished');
        static::getContainer()->get(ManageRecurringInvoices::class)->create($company, Uuid::fromString($model), RecurringFrequency::Monthly, new \DateTimeImmutable('first day of next month'), null, null);

        $gone = $this->quote();
        $kept = $this->quote();
        // Issuing takes a whole fiscal set-up this test is not about: the state is what an issued invoice leaves.
        $issued = $this->invoice();
        $this->em()->getConnection()->executeStatement("UPDATE invoice SET status = 'issued', number = 'F-2026-0001', quote_id = ? WHERE id = ?", [$kept, $issued]);

        return [$gone, $kept];
    }

    private function invoice(): string
    {
        $this->postJson($this->path('invoices'), ['customerId' => $this->customerId, 'establishmentId' => null, 'supplyDate' => null, 'paymentTermsDays' => null, 'customerReference' => null, 'notesPrinted' => null, 'notesInternal' => null, 'discountAmount' => null, 'documentTaxComponentIds' => null, 'lines' => [['productId' => $this->productId, 'quantity' => '2', 'section' => 'Fournitures']]]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        return $this->stringAt($this->json(), 'id');
    }

    private function quote(): string
    {
        $this->postJson($this->path('quotes'), ['customerId' => $this->customerId, 'establishmentId' => null, 'customerReference' => null, 'notesPrinted' => null, 'notesInternal' => null, 'discountAmount' => null, 'lines' => [['productId' => $this->productId, 'quantity' => '3']]]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        return $this->stringAt($this->json(), 'id');
    }

    /** @return list<string> another company's floor and quote, as rows */
    private function otherCompanysRows(): array
    {
        if (null === $this->otherCompanyId) {
            $company = $this->createCompany('Voisin');
            static::getContainer()->get(ProvisionCompany::class)->handle($company);
            $this->otherCompanyId = $company->getId()->toRfc4122();
            $establishment = static::getContainer()->get(EstablishmentRepository::class)->ofCompany($company->getId())[0]->getId()->toRfc4122();
            $this->em()->getConnection()->executeStatement("INSERT INTO venue_area (id, company_id, establishment_id, name, level, image_opacity, created_at, updated_at, width_metres, depth_metres) VALUES (?, ?, ?, 'Dépôt voisin', 0, 100, now(), now(), 10, 10)", [Uuid::v7()->toRfc4122(), $this->otherCompanyId, $establishment]);
        }
        $other = $this->otherCompanyId;

        return $this->texts("SELECT to_jsonb(v)::text FROM venue_area v WHERE company_id = '$other' ORDER BY id");
    }

    /** @return array<string, list<string>> every row of the company in the tables the parts reach, by table */
    private function snapshot(): array
    {
        $rows = [];
        foreach (self::TABLES as $table) {
            $rows[$table] = $this->texts("SELECT to_jsonb(t)::text FROM $table t WHERE company_id = '{$this->companyId()}' ORDER BY id");
        }

        return $rows;
    }

    private function signedIn(string $email, string $role): void
    {
        $company = $this->em()->find(Company::class, $this->company->getId()) ?? self::fail('the company vanished');
        $this->createUser($email, self::PASSWORD, $company, ['*'], $role);
        $this->login($email, self::PASSWORD);
        self::assertResponseIsSuccessful();
    }

    private function companyId(): string
    {
        return $this->company->getId()->toRfc4122();
    }

    private function path(string $resource): string
    {
        return '/api/companies/'.$this->companyId().'/'.$resource;
    }

    private function rowsOf(string $sql): int
    {
        $value = $this->em()->getConnection()->fetchOne($sql);
        self::assertIsInt($value);

        return $value;
    }

    private function text(string $sql): string
    {
        $value = $this->em()->getConnection()->fetchOne($sql);
        self::assertIsString($value);

        return $value;
    }

    /** @return list<string> */
    private function texts(string $sql): array
    {
        return array_map(static fn (mixed $value): string => \is_string($value) ? $value : self::fail('a text was expected'), $this->em()->getConnection()->fetchFirstColumn($sql));
    }
}
