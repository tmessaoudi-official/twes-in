<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Fiscal\Application\Company\ProvisionCompany;
use App\Fiscal\Application\Regime\SyncCustomerTaxRegimes;
use App\Fiscal\Domain\CustomerTaxRegimeRepository;
use App\Fiscal\Domain\UnitRepository;
use App\Module\Customers\Domain\Customer;
use App\Module\Customers\Domain\CustomerKind;
use App\Module\Customers\Domain\CustomerProfile;
use App\Module\Inventory\Domain\StockLocation;
use App\Module\Inventory\Domain\StockMovement;
use App\Module\Products\Domain\Product;
use App\Module\Products\Domain\ProductDetails;
use App\Module\Products\Domain\ProductKind;
use App\Module\Products\Domain\ProductTracking;
use App\ModuleRegistry\Domain\ModuleState;
use App\Settings\Domain\Setting;
use App\Settings\Domain\SettingAddress;
use App\Tenancy\Domain\Company;
use App\Tenancy\Domain\EstablishmentRepository;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

/**
 * « À surveiller » (docs/SPEC.md § 7, 2026-09-24 12:10): the conditions true NOW, each with its figure, recomputed on
 * every read and never stored, so a condition dealt with leaves the list by itself. Each is shown only to a member
 * whose role grants its subject's permission, and only while its module is on; every threshold is a company setting.
 */
final class WatchTest extends ApiTestCase
{
    private Company $company;
    private string $establishmentId;
    private string $today;
    private string $customerId;
    /** @var array<string, string> product ids by reference */
    private array $products = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = $this->createCompany('Quincaillerie');
        static::getContainer()->get(ProvisionCompany::class)->handle($this->company);
        $this->establishmentId = static::getContainer()->get(EstablishmentRepository::class)->ofCompany($this->company->getId())[0]->getId()->toRfc4122();
        $this->today = new \DateTimeImmutable('today', new \DateTimeZone($this->company->getTimezone()))->format('Y-m-d');

        $piece = static::getContainer()->get(UnitRepository::class)->ofCodeInCompany('C62', $this->company->getId());
        self::assertNotNull($piece);
        $now = new \DateTimeImmutable();
        foreach (['VIS' => 'Vis 6x40', 'COLLE' => 'Colle forte', 'GANT' => 'Gants', 'SCIE' => 'Scie égoïne'] as $reference => $name) {
            $product = Product::create($this->company, $reference, new ProductDetails($name, null, ProductKind::Goods, '10'), $piece, null, [], $now);
            $this->em()->persist($product);
            $this->products[$reference] = $product->getId()->toRfc4122();
        }
        $service = Product::create($this->company, 'POSE', new ProductDetails('Pose', null, ProductKind::Service, '50'), $piece, null, [], $now);
        $this->em()->persist($service);
        $this->products['POSE'] = $service->getId()->toRfc4122();
        $this->em()->persist(new Setting(SettingAddress::company($this->company), 'article.stock_tracking', true, $now));
        $this->em()->flush();

        static::getContainer()->get(SyncCustomerTaxRegimes::class)->handle();
        $regime = static::getContainer()->get(CustomerTaxRegimeRepository::class)->ofPresetAndCode('TN', 'standard');
        self::assertNotNull($regime);
        $customer = Customer::create($this->company, 'CLI-0001', new CustomerProfile(CustomerKind::Company, 'Carthage Conseil'), null, $regime, [], $now);
        $this->em()->persist($customer);
        $this->em()->flush();
        $this->customerId = $customer->getId()->toRfc4122();
    }

    public function testWhatNeedsWatchingIsListedNowWithItsFigureAndLeavesWhenDealtWith(): void
    {
        $this->signedIn(['company.read', 'invoice.read', 'invoice.write', 'invoice.issue', 'payment.write', 'product.read', 'product.write', 'stock.read', 'stock.write']);

        $this->getJson($this->watch());
        self::assertResponseIsSuccessful();
        self::assertSame([0, []], [$this->json()['count'] ?? null, $this->items()], 'a quiet company: nothing to watch');

        // A customer 40 days late on one invoice, and VIS sold on it (so VIS is not "unsold").
        $invoiceId = $this->issuedInvoice('VIS');
        $amountDue = $this->stringAt($this->json(), 'amountDue');
        $this->em()->getConnection()->executeStatement('UPDATE invoice SET due_date = ?::date - 40 WHERE id = ?', [$this->today, $invoiceId]);

        // VIS at its reorder point: 5 on hand, a point of 5.
        $this->receive('VIS', '5');
        $this->sendJson('PUT', $this->company().'/products/'.$this->products['VIS'].'/reorder-points/'.$this->establishmentId, ['quantity' => '5']);
        self::assertResponseIsSuccessful();

        // COLLE by lot: one lot expiring in 10 days, one in 60 (outside the 30-day window).
        $this->track('COLLE');
        $this->receive('COLLE', '3', 'L-SOON', '+10 days');
        $this->receive('COLLE', '4', 'L-LATER', '+60 days');

        // GANT running out: 10 received, 9 delivered this month, so 1 left at 0.3 a day, about 3 days.
        $this->receive('GANT', '10');
        $this->deliver('GANT', '9');

        // SCIE and the service POSE were created long ago and never sold; only goods count as unsold.
        $this->em()->getConnection()->executeStatement("UPDATE product SET created_at = now() - interval '120 days' WHERE company_id = ?", [$this->company->getId()->toRfc4122()]);

        $this->getJson($this->watch());
        self::assertResponseIsSuccessful();
        self::assertSame([
            ['invoices.late_customer', $this->customerId, ['customer' => 'Carthage Conseil', 'invoices' => 1, 'amount' => $amountDue, 'currency' => 'TND', 'days' => 40]],
            ['invoices.unsold_products', null, ['products' => 3, 'days' => 90]],
            ['stock.reorder_point', $this->products['VIS'], ['product' => 'Vis 6x40', 'reference' => 'VIS', 'establishment' => 'Quincaillerie', 'onHand' => '5.000', 'point' => '5.000']],
            ['stock.running_out', $this->products['GANT'], ['product' => 'Gants', 'reference' => 'GANT', 'onHand' => '1.000', 'days' => 3]],
            ['stock.lot_expiring', $this->products['COLLE'], ['product' => 'Colle forte', 'reference' => 'COLLE', 'lot' => 'L-SOON', 'expiresOn' => new \DateTimeImmutable($this->today)->modify('+10 days')->format('Y-m-d'), 'quantity' => '3.000', 'days' => 10]],
        ], $this->items());
        self::assertSame(5, $this->json()['count'] ?? null);

        // Dealt with: the invoice paid, VIS restocked above its point. Both leave by themselves.
        $this->postJson('/api/companies/'.$this->company->getId()->toRfc4122().'/invoices/'.$invoiceId.'/payments', ['date' => $this->today, 'amount' => $amountDue, 'method' => 'cash', 'reference' => null, 'notes' => null]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $this->receive('VIS', '1');

        $this->getJson($this->watch());
        self::assertSame(['invoices.unsold_products', 'stock.running_out', 'stock.lot_expiring'], array_column($this->items(), 0));
    }

    public function testEachThresholdIsACompanySetting(): void
    {
        $this->signedIn(['company.read', 'company.settings', 'invoice.read', 'invoice.write', 'invoice.issue', 'product.read', 'stock.read', 'stock.write']);
        $invoiceId = $this->issuedInvoice('VIS');
        $this->em()->getConnection()->executeStatement('UPDATE invoice SET due_date = ?::date - 40 WHERE id = ?', [$this->today, $invoiceId]);
        $this->track('COLLE');
        $this->receive('COLLE', '3', 'L-LATER', '+60 days');
        $this->receive('GANT', '10');
        $this->deliver('GANT', '9');
        $this->em()->getConnection()->executeStatement("UPDATE product SET created_at = now() - interval '120 days' WHERE company_id = ?", [$this->company->getId()->toRfc4122()]);

        $now = new \DateTimeImmutable();
        foreach (['watch.late_after_days' => 60, 'watch.unsold_after_days' => 180, 'watch.lot_expiry_days' => 90, 'watch.lead_days' => 2] as $key => $value) {
            $this->em()->persist(new Setting(SettingAddress::company($this->managed()), $key, $value, $now));
        }
        $this->em()->flush();

        $this->getJson($this->watch());
        self::assertResponseIsSuccessful();
        self::assertSame(['stock.lot_expiring'], array_column($this->items(), 0), '40 days late is under 60; 120 days unsold is under 180; 3 days of gloves is over 2; a lot in 60 days is within 90');
    }

    public function testEachConditionIsShownToWhoMayReadItsSubjectWhileItsModuleIsOn(): void
    {
        $this->signedIn(['company.read', 'invoice.read', 'invoice.write', 'invoice.issue', 'stock.read', 'stock.write'], 'writer@twes.local');
        $invoiceId = $this->issuedInvoice('VIS');
        $this->em()->getConnection()->executeStatement('UPDATE invoice SET due_date = ?::date - 40 WHERE id = ?', [$this->today, $invoiceId]);
        $this->track('COLLE');
        $this->receive('COLLE', '3', 'L-SOON', '+10 days');
        $this->sendJson('POST', '/api/auth/logout');

        $this->signedIn(['company.read', 'stock.read'], 'keeper@twes.local', 'keeper');
        $this->getJson($this->watch());
        self::assertSame(['stock.lot_expiring'], array_column($this->items(), 0), 'no invoice.read: no late customer');
        $this->sendJson('POST', '/api/auth/logout');

        $this->signedIn(['company.read', 'invoice.read'], 'clerk@twes.local', 'clerk');
        $this->getJson($this->watch());
        self::assertSame(['invoices.late_customer'], array_column($this->items(), 0), 'no stock.read: no lot');
        $this->sendJson('POST', '/api/auth/logout');

        $this->signedIn(['invoice.read'], 'outsider@twes.local', 'outsider');
        $this->getJson($this->watch());
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND, 'the list is read with company.read');
        $this->sendJson('POST', '/api/auth/logout');

        $this->em()->persist(ModuleState::of($this->managed(), 'inventory', false, new \DateTimeImmutable()));
        $this->em()->flush();
        $this->login('writer@twes.local', 'password-1234');
        $this->getJson($this->watch());
        self::assertSame(['invoices.late_customer'], array_column($this->items(), 0), 'inventory switched off: no stock condition');
    }

    /** @return list<array{mixed, mixed, mixed}> each item as kind, subject and figures */
    private function items(): array
    {
        $items = $this->json()['items'] ?? null;
        self::assertIsArray($items);

        return array_values(array_map(static fn (mixed $item): array => \is_array($item) ? [$item['kind'] ?? null, $item['subjectId'] ?? null, $item['params'] ?? null] : [null, null, null], $items));
    }

    private function issuedInvoice(string $reference): string
    {
        $this->postJson($this->company().'/invoices', [
            'customerId' => $this->customerId, 'establishmentId' => null, 'supplyDate' => null, 'paymentTermsDays' => null,
            'customerReference' => null, 'notesPrinted' => null, 'notesInternal' => null, 'discountAmount' => null,
            'documentTaxComponentIds' => null, 'lines' => [['productId' => $this->products[$reference], 'quantity' => '1']],
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED, (string) $this->client->getResponse()->getContent());
        $id = $this->stringAt($this->json(), 'id');
        $this->postJson($this->company().'/invoices/'.$id.'/issue', null);
        self::assertResponseIsSuccessful();

        return $id;
    }

    private function receive(string $reference, string $quantity, ?string $lot = null, ?string $expiresIn = null): void
    {
        $body = ['operation' => 'receive', 'productId' => $this->products[$reference], 'locationId' => $this->location()->getId()->toRfc4122(), 'quantity' => $quantity];
        if (null !== $lot) {
            $body += ['lotCode' => $lot, 'lotExpiresOn' => new \DateTimeImmutable($this->today)->modify($expiresIn ?? '+0 days')->format('Y-m-d')];
        }
        $this->postJson($this->company().'/stock-movements', $body);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED, (string) $this->client->getResponse()->getContent());
    }

    /** Stock leaves only through a validated delivery note; the movement it writes is what is recorded here. */
    private function deliver(string $reference, string $quantity): void
    {
        $location = $this->location();
        $product = $this->em()->find(Product::class, $this->products[$reference]);
        self::assertNotNull($product);
        $this->em()->persist(StockMovement::delivery($product, $location, $quantity, Uuid::v7(), new \DateTimeImmutable()));
        $this->em()->flush();
        self::assertEquals(1, $this->em()->getConnection()->fetchOne("SELECT COUNT(*) FROM stock_movement WHERE product_id = ? AND kind = 'out'", [$this->products[$reference]]), 'the delivery was recorded');
    }

    private function track(string $reference): void
    {
        $product = $this->em()->find(Product::class, $this->products[$reference]);
        self::assertNotNull($product);
        $product->track(ProductTracking::Lot, new \DateTimeImmutable());
        $this->em()->flush();
    }

    /** The establishment's default location, which the first read of the locations lays out. */
    private function location(): StockLocation
    {
        $this->getJson($this->company().'/stock-locations');
        self::assertResponseIsSuccessful();
        $location = $this->em()->find(StockLocation::class, $this->stringAt($this->jsonList()[0], 'id'));
        self::assertInstanceOf(StockLocation::class, $location);

        return $location;
    }

    /** @param list<string> $permissions */
    private function signedIn(array $permissions, string $email = 'owner@twes.local', string $role = 'watcher'): void
    {
        $this->createUser($email, 'password-1234', $this->managed(), $permissions, $role);
        $this->login($email, 'password-1234');
    }

    /** The company as the entity manager knows it now: the test client reboots the kernel between requests. */
    private function managed(): Company
    {
        $company = $this->em()->find(Company::class, $this->company->getId());
        self::assertNotNull($company);

        return $company;
    }

    private function watch(): string
    {
        return $this->company().'/watch';
    }

    private function company(): string
    {
        return '/api/companies/'.$this->company->getId()->toRfc4122();
    }
}
