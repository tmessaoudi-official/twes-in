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
use App\Module\Customers\Domain\Model\Customer;
use App\Module\Products\Domain\Model\Product;
use App\Module\Products\Domain\ValueObject\ProductDetails;
use App\Module\Products\Domain\ValueObject\ProductKind;
use App\Module\Vendors\Domain\Model\Vendor;
use App\Module\Vendors\Domain\ValueObject\VendorProfile;
use App\ModuleRegistry\Domain\Model\ModuleState;
use App\Settings\Domain\Model\Setting;
use App\Settings\Domain\ValueObject\SettingAddress;
use App\Tenancy\Domain\Model\Company;
use Symfony\Component\HttpFoundation\Response;

/**
 * Two roles that hand a document to each other, each told on « À surveiller » that it waits for them: a clerk's draft
 * delivery note waits for whoever may validate it, and an order sent to a vendor, once its goods are due, waits for
 * whoever receives them. Each row leads to the document; each leaves the list by itself once it is dealt with.
 */
final class WaitingDocumentsWatchTest extends ApiTestCase
{
    private const string PASSWORD = 'password-1234';
    private const array CLERK = ['company.read', 'delivery_note.read', 'delivery_note.write', 'purchase.read'];
    private const array VALIDATOR = ['company.read', 'delivery_note.read', 'delivery_note.write', 'delivery_note.validate'];
    private const array BUYER = ['company.read', 'purchase.read', 'purchase.write'];
    private const array RECEIVER = ['company.read', 'purchase.read', 'purchase.receive'];

    private Company $company;
    private string $customerId;
    private string $productId;
    private string $vendorId;
    private string $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = $this->createCompany('Quincaillerie', named: false);
        static::getContainer()->get(ProvisionCompany::class)->handle($this->company);
        static::getContainer()->get(SyncCustomerTaxRegimes::class)->handle();
        $regime = static::getContainer()->get(CustomerTaxRegimeRepository::class)->ofPresetAndCode('TN', 'standard');
        $piece = static::getContainer()->get(UnitRepository::class)->ofCodeInCompany('C62', $this->company->getId());
        self::assertNotNull($regime);
        self::assertNotNull($piece);
        $now = new \DateTimeImmutable();
        $customer = Customer::create($this->company, 'CLI-0001', self::aTunisianBusiness('Carthage Conseil'), null, $regime, [], $now);
        $product = Product::create($this->company, 'VIS-6', new ProductDetails('Vis 6 mm', null, ProductKind::Goods, '0.500'), $piece, null, [], $now);
        $vendor = Vendor::create($this->company, 'FRN-0001', new VendorProfile('Quincaillerie du Sud'), $now);
        foreach ([$customer, $product, $vendor] as $entity) {
            $this->em()->persist($entity);
        }
        $this->em()->persist(new Setting(SettingAddress::company($this->company), 'article.stock_tracking', true, $now));
        $this->em()->flush();
        $this->customerId = $customer->getId()->toRfc4122();
        $this->productId = $product->getId()->toRfc4122();
        $this->vendorId = $vendor->getId()->toRfc4122();
        // The company's first place to keep stock is made when its places are first read, as the stock screens do.
        $this->signedIn('owner@twes.local', ['*']);
        $this->getJson($this->company().'/stock-locations');
        self::assertResponseIsSuccessful();
        $this->site = $this->stringAt($this->jsonList()[0], 'id');
    }

    public function testADraftWaitingLongerThanTheThresholdIsListedForWhoeverMayValidateItUntilItIsValidated(): void
    {
        $hours = $this->threshold();
        $this->signedIn('clerk@twes.local', self::CLERK);
        $waiting = $this->draftNote([['productId' => $this->productId, 'quantity' => '2'], ['productId' => $this->productId, 'quantity' => '1']]);
        $fresh = $this->draftNote([['productId' => $this->productId, 'quantity' => '1']]);
        $empty = $this->draftNote([]);
        $this->savedHoursAgo($waiting, $hours + 1);
        $this->savedHoursAgo($empty, $hours + 5);
        $this->savedHoursAgo($fresh, $hours - 1);

        $this->getJson($this->watch());
        self::assertSame([], $this->kinds(), 'the clerk may not validate: nothing of the kind is for them');
        $this->getJson($this->watch().'/delivery_note.to_validate');
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND, 'nor by name');

        $this->signedIn('manager@twes.local', self::VALIDATOR);
        $this->getJson($this->watch());
        self::assertSame([['delivery_note.to_validate', 1]], $this->subjects(), 'saved more than the threshold ago, with lines: one');
        self::assertSame([['delivery_note.to_validate', $waiting, ['customer' => 'Carthage Conseil', 'lines' => 2, 'hours' => $hours + 1]]], $this->rows('delivery_note.to_validate'));

        $this->postJson($this->company().'/delivery-notes/'.$waiting.'/validate', null);
        self::assertResponseIsSuccessful();
        $this->getJson($this->watch());
        self::assertSame([], $this->kinds(), 'validated, it waits for nobody');
    }

    public function testTheOldestDraftComesFirstAndAnotherCompanysDraftsAreNotCounted(): void
    {
        $this->signedIn('manager@twes.local', self::VALIDATOR);
        $newer = $this->draftNote([['productId' => $this->productId, 'quantity' => '1']]);
        $older = $this->draftNote([['productId' => $this->productId, 'quantity' => '1']]);
        $elsewhere = $this->draftNote([['productId' => $this->productId, 'quantity' => '1']]);
        $this->savedHoursAgo($newer, $this->threshold() + 2);
        $this->savedHoursAgo($older, $this->threshold() + 30);
        $this->savedHoursAgo($elsewhere, $this->threshold() + 10);
        $other = $this->createCompany('Ailleurs', named: false);
        $this->em()->getConnection()->executeStatement('UPDATE delivery_note SET company_id = ? WHERE id = ?', [$other->getId()->toRfc4122(), $elsewhere]);

        $this->getJson($this->watch());
        self::assertSame([['delivery_note.to_validate', 2]], $this->subjects());
        self::assertSame(
            [[$older, $this->threshold() + 30], [$newer, $this->threshold() + 2]],
            array_map(static fn (array $row): array => [$row[1], \is_array($row[2]) ? $row[2]['hours'] ?? null : null], $this->rows('delivery_note.to_validate')),
        );
    }

    public function testNothingWaitsToBeValidatedWhileDeliveryNotesAreSwitchedOff(): void
    {
        $this->signedIn('manager@twes.local', self::VALIDATOR);
        $this->savedHoursAgo($this->draftNote([['productId' => $this->productId, 'quantity' => '1']]), $this->threshold() + 1);
        $this->em()->persist(ModuleState::of($this->managed(), 'delivery_notes', false, new \DateTimeImmutable()));
        $this->em()->flush();

        $this->getJson($this->watch());
        self::assertSame([], $this->kinds());
        $this->getJson($this->watch().'/delivery_note.to_validate');
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testAnOrderWhoseGoodsAreDueIsListedForWhoeverReceivesThemUntilEverythingArrived(): void
    {
        $this->signedIn('buyer@twes.local', self::BUYER);
        $due = $this->sentOrder(self::dayOf($this->company, '-2 days'), '5');
        $today = $this->sentOrder(self::dayOf($this->company), '1');
        $this->sentOrder(self::dayOf($this->company, '+1 day'), '1');
        $this->sentOrder(null, '1');
        $this->draftOrder(self::dayOf($this->company, '-3 days'));
        $this->getJson($this->watch());
        self::assertSame([], $this->kinds(), 'the buyer does not receive: told nothing of it');

        $this->signedIn('clerk@twes.local', self::RECEIVER);
        $this->getJson($this->watch());
        self::assertSame([['purchase_order.to_receive', 2]], $this->subjects(), 'due two days ago and due today; not tomorrow, not undated, never a draft');
        $rows = $this->rows('purchase_order.to_receive');
        self::assertSame([$due, $today], array_column($rows, 1), 'the longest awaited first');
        $figures = $this->section(['row' => $rows[0][2]], 'row');
        self::assertSame(['vendor' => 'Quincaillerie du Sud', 'expectedOn' => self::dayOf($this->company, '-2 days'), 'days' => 2], array_diff_key($figures, ['number' => true]));
        self::assertMatchesRegularExpression('/\S/', $this->stringAt($figures, 'number'), 'a sent order is named by its number');

        $this->receive($due, '2');
        self::assertResponseIsSuccessful();
        self::assertSame([$due, $today], array_column($this->rows('purchase_order.to_receive'), 1), 'partly received, the rest is still awaited');
        $this->receive($due, '3');
        self::assertResponseIsSuccessful();
        $this->receive($today, '1');
        self::assertResponseIsSuccessful();
        $this->getJson($this->watch());
        self::assertSame([], $this->kinds(), 'everything arrived: nothing waits');
    }

    public function testAnotherCompanysOrdersAreNotCounted(): void
    {
        $this->signedIn('buyer@twes.local', [...self::BUYER, 'purchase.receive']);
        $order = $this->sentOrder(self::dayOf($this->company, '-1 day'), '1');
        $other = $this->createCompany('Ailleurs', named: false);
        $this->em()->getConnection()->executeStatement('UPDATE purchase_order SET company_id = ? WHERE id = ?', [$other->getId()->toRfc4122(), $order]);

        $this->getJson($this->watch());
        self::assertSame([], $this->kinds());
    }

    private function threshold(): int
    {
        $hours = static::getContainer()->getParameter('app.watch.drafts_to_validate_after_hours');
        self::assertGreaterThan(1, $hours, 'a threshold the fresh draft can sit under');

        return $hours;
    }

    /** @param list<array<string, string>> $lines */
    private function draftNote(array $lines): string
    {
        $this->postJson($this->company().'/delivery-notes', [
            'customerId' => $this->customerId, 'establishmentId' => null, 'deliveryDate' => null,
            'deliveryAddressLine1' => null, 'deliveryAddressLine2' => null, 'deliveryPostalCode' => null,
            'deliveryCity' => null, 'deliveryCountryCode' => null, 'customerReference' => null,
            'remarksPrinted' => null, 'notesInternal' => null, 'lines' => $lines,
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED, (string) $this->client->getResponse()->getContent());

        return $this->stringAt($this->json(), 'id');
    }

    /** The draft last saved this many hours (and a minute) before the API's clock, as its own save would have stamped it. */
    private function savedHoursAgo(string $note, int $hours): void
    {
        $this->em()->getConnection()->executeStatement(
            'UPDATE delivery_note SET updated_at = ?::timestamp - make_interval(hours => ?, mins => 1) WHERE id = ?',
            [self::apiNow(), $hours, $note],
        );
    }

    private function sentOrder(?string $expectedOn, string $quantity): string
    {
        $id = $this->draftOrder($expectedOn, $quantity);
        $this->postJson($this->company().'/purchase-orders/'.$id.'/send', null);
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());

        return $id;
    }

    private function draftOrder(?string $expectedOn, string $quantity = '1'): string
    {
        $this->postJson($this->company().'/purchase-orders', ['vendorId' => $this->vendorId, 'expectedOn' => $expectedOn, 'lines' => [['productId' => $this->productId, 'quantity' => $quantity]]]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED, (string) $this->client->getResponse()->getContent());

        return $this->stringAt($this->json(), 'id');
    }

    private function receive(string $order, string $quantity): void
    {
        $line = $this->em()->getConnection()->fetchOne('SELECT id::text FROM purchase_order_line WHERE purchase_order_id = ?', [$order]);
        self::assertIsString($line);
        $this->postJson($this->company().'/purchase-orders/'.$order.'/receipts', ['receivedOn' => self::dayOf($this->company), 'lines' => [['orderLineId' => $line, 'locationId' => $this->site, 'quantity' => $quantity]]]);
    }

    /** @param list<string> $permissions */
    private function signedIn(string $email, array $permissions): void
    {
        $this->client->getCookieJar()->clear();
        $this->createUser($email, self::PASSWORD, $this->managed(), $permissions, strtok($email, '@') ?: 'member');
        $this->login($email, self::PASSWORD);
    }

    private function managed(): Company
    {
        $company = $this->em()->find(Company::class, $this->company->getId());
        self::assertNotNull($company);

        return $company;
    }

    /** @return list<mixed> the kinds of the summary, but every « À vérifier », which the records here may lack */
    private function kinds(): array
    {
        return array_column($this->subjects(), 0);
    }

    /** @return list<array{mixed, mixed}> each subject of the summary as kind and count, but every « À vérifier » */
    private function subjects(): array
    {
        self::assertResponseIsSuccessful();
        $subjects = $this->json()['subjects'] ?? null;
        self::assertIsArray($subjects);
        $all = array_values(array_map(static fn (mixed $subject): array => \is_array($subject) ? [$subject['kind'] ?? null, $subject['count'] ?? null] : [null, null], $subjects));

        return array_values(array_filter($all, static fn (array $subject): bool => !\is_string($subject[0]) || !str_ends_with($subject[0], '.to_check')));
    }

    /** @return list<array{mixed, mixed, mixed}> the rows of one subject, as kind, subject and figures */
    private function rows(string $kind): array
    {
        $this->getJson($this->watch().'/'.$kind);
        self::assertResponseIsSuccessful();

        return array_map(static fn (array $row): array => [$row['kind'] ?? null, $row['subjectId'] ?? null, $row['params'] ?? null], $this->jsonList());
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
