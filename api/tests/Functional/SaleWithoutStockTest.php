<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Fiscal\Application\Company\ProvisionCompany;
use App\Fiscal\Application\Regime\SyncCustomerTaxRegimes;
use App\Fiscal\Domain\Repository\CustomerTaxRegimeRepository;
use App\Fiscal\Domain\Repository\UnitRepository;
use App\Identity\Domain\Model\User;
use App\Module\Customers\Domain\Model\Customer;
use App\Module\Products\Domain\Model\Product;
use App\Module\Products\Domain\ValueObject\ProductDetails;
use App\Module\Products\Domain\ValueObject\ProductKind;
use App\Settings\Domain\Model\Setting;
use App\Settings\Domain\ValueObject\SettingAddress;
use App\Tenancy\Domain\Model\Company;
use App\Tenancy\Domain\Model\Membership;
use App\Tenancy\Domain\Repository\EstablishmentRepository;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

/**
 * « Sortie sans stock enregistré »: a sale taking more than the stock recorded, read when a delivery note is validated and
 * when an invoice is issued with its own lines, never while either is a draft. Allowed by default, where the sale goes
 * below zero and « À surveiller » shows it; confirmed by whoever may; or refused, naming each short line.
 */
final class SaleWithoutStockTest extends ApiTestCase
{
    private const string KEY = 'stock.sale_without_stock';
    private const string SELL_SHORT = 'stock.sell_short';
    private const array SELLING = ['stock.read', 'stock.write', 'delivery_note.read', 'delivery_note.write', 'delivery_note.validate', 'invoice.read', 'invoice.write', 'invoice.issue'];

    private Company $company;
    private string $establishmentId;
    private string $laptopId;
    private string $supportId;
    private string $consumableId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = $this->createCompany('Acme');
        static::getContainer()->get(ProvisionCompany::class)->handle($this->company);
        $this->establishmentId = static::getContainer()->get(EstablishmentRepository::class)->ofCompany($this->company->getId())[0]->getId()->toRfc4122();
        $piece = static::getContainer()->get(UnitRepository::class)->ofCodeInCompany('C62', $this->company->getId());
        self::assertNotNull($piece);
        $now = new \DateTimeImmutable();
        $laptop = Product::create($this->company, 'ART-001', new ProductDetails('Portable 14"', null, ProductKind::Goods, '1250'), $piece, null, [], $now);
        $support = Product::create($this->company, 'SRV-001', new ProductDetails('Assistance', null, ProductKind::Service, '50'), $piece, null, [], $now);
        $consumable = Product::create($this->company, 'ART-009', new ProductDetails('Cartouche encre', null, ProductKind::Goods, '30'), $piece, null, [], $now);
        $this->em()->persist($laptop);
        $this->em()->persist($support);
        $this->em()->persist($consumable);
        $this->em()->persist(new Setting(SettingAddress::company($this->company), 'article.stock_tracking', true, $now));
        $this->em()->persist(new Setting(SettingAddress::product($this->company, $consumable->getId()), 'article.stock_tracking', false, $now));
        $this->em()->flush();
        $this->laptopId = $laptop->getId()->toRfc4122();
        $this->supportId = $support->getId()->toRfc4122();
        $this->consumableId = $consumable->getId()->toRfc4122();
    }

    public function testByDefaultASaleBeyondTheStockGoesThroughAtBothMoments(): void
    {
        $this->signedIn(self::SELLING);
        $this->received('2');
        $customerId = $this->customer();

        $this->postJson($this->path('delivery-notes', $this->draftNote($customerId, '3')).'/validate', null);
        self::assertResponseIsSuccessful();
        $this->postJson($this->path('invoices', $this->draftInvoice($customerId, '3')).'/issue', null);
        self::assertResponseIsSuccessful();

        self::assertSame(['-4.000'], array_column($this->levels(), 'quantity'));
    }

    public function testRefusedASaleNamesItsShortLinesTakesNoNumberAndMovesNothing(): void
    {
        $this->signedIn([...self::SELLING, self::SELL_SHORT]);
        $this->set(static fn (Company $c): SettingAddress => SettingAddress::company($c), 'refuse');
        $this->received('2');
        $customerId = $this->customer();
        $lines = [
            ['productId' => $this->supportId, 'quantity' => '1'],
            ['productId' => $this->laptopId, 'quantity' => '3'],
            ['productId' => $this->consumableId, 'quantity' => '5'],
        ];

        // A draft is never refused: its line stays red until it is sold.
        $noteId = $this->draftNote($customerId, '3', $lines);
        $invoiceId = $this->draftInvoice($customerId, '3', $lines);

        foreach (['delivery-notes' => $noteId, 'invoices' => $invoiceId] as $resource => $id) {
            $act = 'delivery-notes' === $resource ? ['/validate', '/validate-and-deliver'] : ['/issue'];
            foreach ($act as $path) {
                // Confirming changes nothing where the answer is to refuse.
                foreach (['', '?shortOfStock=confirmed'] as $query) {
                    // Validating and delivering at once takes what the delivery says, which may be nothing.
                    $this->postJson($this->path($resource, $id).$path.$query, '/validate-and-deliver' === $path ? [] : null);
                    self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, $resource.$path.$query);
                    $refusals = $this->refusals();
                    self::assertSame([[
                        'propertyPath' => 'lines[1].quantity',
                        'reason' => 'stock_short',
                        'params' => ['product' => 'ART-001', 'name' => 'Portable 14"', 'wanted' => '3.000', 'on_hand' => '2.000', 'unit' => $this->unitName(), 'product_id' => $this->laptopId, 'location_id' => $this->defaultLocationId()],
                    ]], $refusals, $resource.$path.$query);
                }
            }
            $this->getJson($this->path($resource, $id));
            self::assertSame('draft', $this->stringAt($this->json(), 'status'));
            self::assertNull($this->json()['number'] ?? null);
        }
        self::assertSame(['2.000'], array_column($this->levels(), 'quantity'));

        // What the stock covers is sold as ever.
        $this->postJson($this->path('delivery-notes', $this->draftNote($customerId, '2')).'/validate', null);
        self::assertResponseIsSuccessful();
    }

    public function testToConfirmASaleIsAskedThenTakenOnceConfirmed(): void
    {
        $this->signedIn([...self::SELLING, self::SELL_SHORT]);
        $this->set(static fn (Company $c): SettingAddress => SettingAddress::company($c), 'confirm');
        $this->received('2');
        $customerId = $this->customer();
        $noteId = $this->draftNote($customerId, '3');
        $invoiceId = $this->draftInvoice($customerId, '3');

        // The invoice is asked after the note's confirmed sale left the place below zero.
        foreach (['delivery-notes' => [$noteId, '/validate', '2.000'], 'invoices' => [$invoiceId, '/issue', '-1.000']] as $resource => [$id, $path, $onHand]) {
            $this->postJson($this->path($resource, $id).$path, null);
            self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, $resource);
            [$reason, $params] = $this->refusalOf('lines[0].quantity');
            self::assertSame('stock_short_to_confirm', $reason, $resource);
            self::assertIsArray($params);
            self::assertSame(['3.000', $onHand], [$params['wanted'] ?? null, $params['on_hand'] ?? null]);

            $this->postJson($this->path($resource, $id).$path.'?shortOfStock=confirmed', null);
            self::assertResponseIsSuccessful($resource);
        }
        self::assertSame(['-4.000'], array_column($this->levels(), 'quantity'));
    }

    public function testToConfirmWithoutTheRightToConfirmASaleIsRefused(): void
    {
        $this->signedIn(self::SELLING);
        $this->set(static fn (Company $c): SettingAddress => SettingAddress::company($c), 'confirm');
        $this->received('2');
        $customerId = $this->customer();

        $this->postJson($this->path('delivery-notes', $this->draftNote($customerId, '3')).'/validate?shortOfStock=confirmed', null);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame('stock_short', $this->refusalOf('lines[0].quantity')[0]);
        $this->postJson($this->path('invoices', $this->draftInvoice($customerId, '3')).'/issue?shortOfStock=confirmed', null);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame('stock_short', $this->refusalOf('lines[0].quantity')[0]);
    }

    public function testAnEstablishmentThenARoleSayOtherwiseThanTheCompany(): void
    {
        $user = $this->signedIn(self::SELLING);
        $this->set(static fn (Company $c): SettingAddress => SettingAddress::company($c), 'refuse');
        $this->set(fn (Company $c): SettingAddress => SettingAddress::establishment($c, Uuid::fromString($this->establishmentId)), 'allow');
        $this->received('2');
        $customerId = $this->customer();

        $this->postJson($this->path('delivery-notes', $this->draftNote($customerId, '3')).'/validate', null);
        self::assertResponseIsSuccessful('the establishment allows what the company refuses');

        $this->set(fn (Company $c): SettingAddress => SettingAddress::role($c, $this->roleOf($user)), 'refuse');
        $this->postJson($this->path('invoices', $this->draftInvoice($customerId, '1')).'/issue', null);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, 'the role refuses what its establishment allows');
        [$reason, $params] = $this->refusalOf('lines[0].quantity');
        self::assertIsArray($params);
        self::assertSame(['stock_short', '1.000', '-1.000'], [$reason, $params['wanted'] ?? null, $params['on_hand'] ?? null], 'on hand as recorded, below zero after the first sale');
    }

    /** @param \Closure(Company): SettingAddress $address */
    private function set(\Closure $address, string $answer): void
    {
        // Read afresh: the test client reboots the kernel between requests, which leaves the company set up detached.
        $company = $this->em()->find(Company::class, $this->company->getId());
        self::assertNotNull($company);
        $this->em()->persist(new Setting($address($company), self::KEY, $answer, new \DateTimeImmutable()));
        $this->em()->flush();
    }

    private function received(string $quantity): void
    {
        $this->postJson($this->path('stock-movements'), ['operation' => 'receive', 'productId' => $this->laptopId, 'locationId' => $this->defaultLocationId(), 'quantity' => $quantity]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
    }

    /** @param list<array<string, mixed>>|null $lines */
    private function draftNote(string $customerId, string $quantity, ?array $lines = null): string
    {
        $this->postJson($this->path('delivery-notes'), [
            'customerId' => $customerId,
            'establishmentId' => null,
            'deliveryDate' => null,
            'deliveryAddressLine1' => null,
            'deliveryAddressLine2' => null,
            'deliveryPostalCode' => null,
            'deliveryCity' => null,
            'deliveryCountryCode' => null,
            'customerReference' => null,
            'remarksPrinted' => null,
            'notesInternal' => null,
            'lines' => $lines ?? [['productId' => $this->laptopId, 'quantity' => $quantity]],
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        return $this->stringAt($this->json(), 'id');
    }

    /** @param list<array<string, mixed>>|null $lines */
    private function draftInvoice(string $customerId, string $quantity, ?array $lines = null): string
    {
        $this->postJson($this->path('invoices'), [
            'customerId' => $customerId,
            'establishmentId' => null,
            'supplyDate' => null,
            'paymentTermsDays' => null,
            'customerReference' => null,
            'notesPrinted' => null,
            'notesInternal' => null,
            'discountAmount' => null,
            'documentTaxComponentIds' => null,
            'lines' => $lines ?? [['productId' => $this->laptopId, 'quantity' => $quantity]],
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        return $this->stringAt($this->json(), 'id');
    }

    private function customer(): string
    {
        static::getContainer()->get(SyncCustomerTaxRegimes::class)->handle();
        $regime = static::getContainer()->get(CustomerTaxRegimeRepository::class)->ofPresetAndCode('TN', 'standard');
        self::assertNotNull($regime);
        $company = $this->em()->find(Company::class, $this->company->getId());
        self::assertNotNull($company);
        $customer = Customer::create($company, 'CLI-0001', self::aTunisianBusiness('Carthage Conseil'), null, $regime, [], new \DateTimeImmutable());
        $this->em()->persist($customer);
        $this->em()->flush();

        return $customer->getId()->toRfc4122();
    }

    /** @return list<array<string, mixed>> */
    private function levels(): array
    {
        $this->getJson($this->path('stock-levels').'?productId='.$this->laptopId);
        self::assertResponseIsSuccessful();

        return $this->jsonList();
    }

    private function defaultLocationId(): string
    {
        $this->getJson($this->path('stock-locations'));
        self::assertResponseIsSuccessful();

        return $this->stringAt($this->jsonList()[0], 'id');
    }

    private function unitName(): string
    {
        $unit = static::getContainer()->get(UnitRepository::class)->ofCodeInCompany('C62', $this->company->getId());
        self::assertNotNull($unit);

        return $unit->getName();
    }

    private function roleOf(User $user): Uuid
    {
        $membership = $this->em()->getRepository(Membership::class)->findOneBy(['user' => $user->getId()]);
        self::assertNotNull($membership);

        return $membership->getRole()->getId();
    }

    private function path(string $resource, ?string $id = null): string
    {
        return '/api/companies/'.$this->company->getId()->toRfc4122().'/'.$resource.(null === $id ? '' : '/'.$id);
    }

    /** @param list<string> $permissions */
    private function signedIn(array $permissions): User
    {
        $user = $this->createUser('seller@twes.local', 'password-1234', $this->company, $permissions, 'seller');
        $this->login('seller@twes.local', 'password-1234');
        self::assertResponseIsSuccessful();

        return $user;
    }
}
