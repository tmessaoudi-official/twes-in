<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Fiscal\Application\Company\ProvisionCompany;
use App\Fiscal\Domain\Repository\UnitRepository;
use App\Module\Products\Domain\Model\Product;
use App\Module\Products\Domain\ValueObject\ProductDetails;
use App\Module\Products\Domain\ValueObject\ProductKind;
use App\Module\Vendors\Domain\Model\Vendor;
use App\Module\Vendors\Domain\ValueObject\VendorProfile;
use App\Settings\Application\ChangeSettings;
use App\Settings\Application\SettingContext;
use App\Settings\Domain\Model\Setting;
use App\Settings\Domain\ValueObject\SettingAddress;
use App\Settings\Domain\ValueObject\SettingLevel;
use App\Tenancy\Domain\Model\Company;
use Symfony\Component\HttpFoundation\Response;

/**
 * « Reçu par » on a goods receipt: one of the company's members, offered with the person recording first, or a name
 * typed; copied onto the receipt; asked as the company's setting says; put right afterwards, journaled by the field and
 * never the name; shown on the order and found by the orders list.
 */
final class PurchaseReceiptReceiversTest extends ApiTestCase
{
    private const string PASSWORD = 'password-1234';
    private const array BUYER = ['company.read', 'purchase.read', 'purchase.write', 'purchase.receive'];

    private Company $company;
    private string $vendor;
    private string $screws;
    private string $site;
    private string $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = $this->createCompany('Acme', named: false);
        static::getContainer()->get(ProvisionCompany::class)->handle($this->company);
        $piece = static::getContainer()->get(UnitRepository::class)->ofCodeInCompany('C62', $this->company->getId());
        self::assertNotNull($piece);
        $now = new \DateTimeImmutable();
        $screws = Product::create($this->company, 'VIS-6', new ProductDetails('Vis 6 mm', null, ProductKind::Goods, '0.500'), $piece, null, [], $now);
        $vendor = Vendor::create($this->company, 'FRN-0001', new VendorProfile('Quincaillerie du Sud'), $now);
        $this->em()->persist($screws);
        $this->em()->persist($vendor);
        $this->em()->persist(new Setting(SettingAddress::company($this->company), 'article.stock_tracking', true, $now));
        $this->em()->flush();
        $this->screws = $screws->getId()->toRfc4122();
        $this->vendor = $vendor->getId()->toRfc4122();

        $this->owner = $this->createUser('owner@twes.local', self::PASSWORD, $this->company, ['*'], 'owner')->getId()->toRfc4122();
        $this->login('owner@twes.local', self::PASSWORD);
        $this->getJson($this->companyPath().'/stock-locations');
        self::assertResponseIsSuccessful();
        $this->site = $this->stringAt($this->jsonList()[0], 'id');
    }

    public function testAReceiptNamesAMemberOrANameTypedCopiedOntoItAndTheOrdersListShowsAndFindsThem(): void
    {
        $id = $this->sentAs('buyer', self::BUYER);

        $this->getJson($this->companyPath().'/purchase-order-options/receivers');
        self::assertResponseIsSuccessful();
        $offered = $this->jsonList();
        self::assertSame(['Buyer', 'Owner'], array_column($offered, 'name'), 'the person recording first, then the others');
        foreach ($offered as $member) {
            self::assertSame(['memberId', 'name'], array_keys($member), 'names only, never an address');
        }

        $this->receive($id, '4', ['memberId' => $this->owner]);
        self::assertResponseIsSuccessful();
        $this->receive($id, '1', ['name' => '  Le chauffeur  ']);
        self::assertResponseIsSuccessful();
        $this->receive($id, '1', null);
        self::assertResponseIsSuccessful();

        $this->getJson($this->path($id));
        $order = $this->json();
        self::assertSame(
            [['name' => 'Owner', 'memberId' => $this->owner], ['name' => 'Le chauffeur', 'memberId' => null], null],
            array_column($this->receipts($order), 'receivedBy'),
        );
        self::assertSame(['Owner, Le chauffeur', 'optional'], [$order['receivers'], $order['receiverRule']]);

        $this->getJson($this->path().'?q=chauffeur');
        $rows = $this->jsonList();
        self::assertSame([[$id, 'Owner, Le chauffeur', null]], array_map(static fn (array $row): array => [$row['id'] ?? null, $row['receivers'] ?? null, \array_key_exists('receiverRule', $row) ? $row['receiverRule'] : 'absent'], $rows), 'a list reads no setting');
    }

    public function testTheCompanysSettingRefusesANameWhereItIsOffAndNobodyWhereItIsRequired(): void
    {
        $id = $this->sentAs('buyer', self::BUYER);

        $this->changeReceiverRule('off');
        $this->receive($id, '4', ['name' => 'Le chauffeur']);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame('receiver_off', $this->refusalOf('receivedBy')[0]);
        $this->getJson($this->path($id));
        self::assertSame(['sent', 'off'], [$this->json()['status'], $this->json()['receiverRule']], 'nothing arrived');

        $this->changeReceiverRule('required');
        $this->receive($id, '4', null);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame('receiver_required', $this->refusalOf('receivedBy')[0]);
        $this->receive($id, '4', ['name' => '   ']);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, 'blanks name nobody');
        self::assertSame('receiver_required', $this->refusalOf('receivedBy')[0]);
        self::assertEquals(0, $this->em()->getConnection()->fetchOne('SELECT count(*) FROM goods_receipt'), 'a refused receipt leaves nothing behind');

        $this->receive($id, '4', ['name' => 'Le chauffeur']);
        self::assertResponseIsSuccessful();
        self::assertSame('required', $this->json()['receiverRule']);
    }

    public function testNoOneButAMemberOfThisCompanyIsPickedAndNothingElseIsTaken(): void
    {
        $id = $this->sentAs('buyer', self::BUYER);
        $globex = $this->createCompany('Globex', named: false);
        $theirs = $this->createUser('globex@twes.local', self::PASSWORD, $globex, ['*'], 'owner')->getId()->toRfc4122();
        $this->login('buyer@twes.local', self::PASSWORD);

        $this->getJson($this->companyPath().'/purchase-order-options/receivers');
        self::assertNotContains('Globex', array_column($this->jsonList(), 'name'));

        foreach ([$theirs, '0192c3a4-0000-7000-8000-000000000000', 'not-an-id'] as $memberId) {
            $this->receive($id, '4', ['memberId' => $memberId]);
            self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, $memberId);
            self::assertSame('unknown_choice', $this->refusalOf('receivedBy')[0]);
        }
        $this->receive($id, '4', ['name' => str_repeat('a', 202)]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame('invalid_text', $this->refusalOf('receivedBy')[0]);
        $this->receive($id, '4', ['name' => 'Sami', 'addToContacts' => true]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, 'a receipt adds nobody to any list');
    }

    public function testWhoReceivedIsPutRightJournaledByTheFieldAloneAndARenamedMemberKeepsTheNameCopied(): void
    {
        $id = $this->sentAs('buyer', self::BUYER);
        $this->receive($id, '10', ['memberId' => $this->owner]);
        self::assertSame('received', $this->json()['status']);
        $receipt = $this->stringAt($this->receipts($this->json())[0], 'id');
        $this->em()->getConnection()->executeStatement('UPDATE "user" SET display_name = ? WHERE id = ?', ['Owner Renamed', $this->owner]);

        $this->postJson($this->receiptPath($id, $receipt), ['receivedBy' => ['memberId' => $this->owner]]);
        self::assertResponseIsSuccessful();
        self::assertSame(['name' => 'Owner', 'memberId' => $this->owner], $this->receipts($this->json())[0]['receivedBy'], 'sent back as read, the name copied stays');
        self::assertSame([], $this->corrections(), 'and nothing changed');

        $this->postJson($this->receiptPath($id, $receipt), ['receivedBy' => ['name' => 'Le chauffeur de Sotumag']]);
        self::assertResponseIsSuccessful();
        self::assertSame([['name' => 'Le chauffeur de Sotumag', 'memberId' => null], 'Le chauffeur de Sotumag'], [$this->receipts($this->json())[0]['receivedBy'], $this->json()['receivers']]);
        $this->postJson($this->receiptPath($id, $receipt), ['receivedBy' => ['name' => 'Le chauffeur de Sotumag']]);
        self::assertResponseIsSuccessful();
        $corrections = $this->corrections();
        self::assertSame([['fields' => ['receivedBy']]], $corrections, 'once, by the field, never the name');
        self::assertEquals(0, $this->em()->getConnection()->fetchOne("SELECT count(*) FROM audit_log WHERE changes::text LIKE '%Sotumag%' OR changes::text LIKE '%Owner%'"));

        $this->postJson($this->receiptPath($id, $receipt), ['receivedBy' => ['memberId' => $this->owner]]);
        self::assertSame(['name' => 'Owner Renamed', 'memberId' => $this->owner], $this->receipts($this->json())[0]['receivedBy'], 'a member picked again is copied as named now');

        $this->changeReceiverRule('required');
        $this->postJson($this->receiptPath($id, $receipt), ['receivedBy' => null]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame('receiver_required', $this->refusalOf('receivedBy')[0]);
        $this->changeReceiverRule('off');
        $this->postJson($this->receiptPath($id, $receipt), ['receivedBy' => ['name' => 'Sami']]);
        self::assertSame('receiver_off', $this->refusalOf('receivedBy')[0]);
        $this->postJson($this->receiptPath($id, $receipt), ['receivedBy' => null]);
        self::assertResponseIsSuccessful('switched off, a name recorded by mistake is taken away');
        self::assertSame([null, null], [$this->receipts($this->json())[0]['receivedBy'], $this->json()['receivers']]);
    }

    public function testAReceiptIsPutRightOnlyThroughItsOwnOrderInItsOwnCompanyByWhoeverReceives(): void
    {
        $id = $this->sentAs('buyer', self::BUYER);
        $other = $this->sent();
        $this->receive($id, '4', ['name' => 'Sami']);
        $receipt = $this->stringAt($this->receipts($this->json())[0], 'id');

        $this->postJson($this->receiptPath($other, $receipt), ['receivedBy' => ['name' => 'Karim']]);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND, 'a receipt of another order');
        $this->postJson($this->receiptPath($id, '0192c3a4-0000-7000-8000-000000000000'), ['receivedBy' => ['name' => 'Karim']]);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND, 'no such receipt');

        $this->signedIn('writer', ['company.read', 'purchase.read', 'purchase.write']);
        $this->postJson($this->receiptPath($id, $receipt), ['receivedBy' => ['name' => 'Karim']]);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND, 'putting it right needs purchase.receive');
        $this->getJson($this->companyPath().'/purchase-order-options/receivers');
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND, 'so does the members\' list');
        $this->getJson($this->path($id));
        self::assertSame(['name' => 'Sami', 'memberId' => null], $this->receipts($this->json())[0]['receivedBy'], 'a reader sees who received');

        $globex = $this->createCompany('Globex', named: false);
        static::getContainer()->get(ProvisionCompany::class)->handle($globex);
        $this->createUser('globex@twes.local', self::PASSWORD, $globex, ['*'], 'owner');
        $this->login('globex@twes.local', self::PASSWORD);
        $this->postJson('/api/companies/'.$globex->getId()->toRfc4122().'/purchase-orders/'.$id.'/receipts/'.$receipt.'/receiver', ['receivedBy' => ['name' => 'Karim']]);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND, 'another company\'s order is not there');
        $this->postJson($this->receiptPath($id, $receipt), ['receivedBy' => ['name' => 'Karim']]);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND, 'nor is a company one does not belong to');
        self::assertSame('Sami', $this->em()->getConnection()->fetchOne('SELECT received_by_name FROM goods_receipt WHERE id = ?', [$receipt]));
    }

    /** @param array<string, string|bool>|null $receivedBy */
    private function receive(string $orderId, string $quantity, ?array $receivedBy): void
    {
        $line = $this->em()->getConnection()->fetchOne('SELECT id::text FROM purchase_order_line WHERE purchase_order_id = ?', [$orderId]);
        self::assertIsString($line);
        $body = ['receivedOn' => self::dayOf($this->company), 'lines' => [['orderLineId' => $line, 'locationId' => $this->site, 'quantity' => $quantity]]];
        $this->postJson($this->path($orderId).'/receipts', null === $receivedBy ? $body : $body + ['receivedBy' => $receivedBy]);
    }

    /** @param list<string> $permissions */
    private function sentAs(string $name, array $permissions): string
    {
        $this->signedIn($name, $permissions);

        return $this->sent();
    }

    private function sent(): string
    {
        $this->postJson($this->path(), ['vendorId' => $this->vendor, 'lines' => [['productId' => $this->screws, 'quantity' => '10']]]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $id = $this->stringAt($this->json(), 'id');
        $this->postJson($this->path($id).'/send', null);
        self::assertResponseIsSuccessful();

        return $id;
    }

    /** @param list<string> $permissions */
    private function signedIn(string $name, array $permissions): void
    {
        $email = $name.'@twes.local';
        if (false === $this->em()->getConnection()->fetchOne('SELECT 1 FROM "user" WHERE email = ?', [$email])) {
            $company = $this->em()->find(Company::class, $this->company->getId());
            self::assertNotNull($company);
            $this->createUser($email, self::PASSWORD, $company, $permissions, $name);
        }
        $this->login($email, self::PASSWORD);
        self::assertResponseIsSuccessful();
    }

    private function changeReceiverRule(string $rule): void
    {
        $company = $this->em()->find(Company::class, $this->company->getId());
        self::assertNotNull($company);
        static::getContainer()->get(ChangeSettings::class)->change(new SettingContext($company), 'purchase_receipt.receiver', SettingLevel::Company, $rule, null);
    }

    /** @return list<mixed> each correction's changes, decoded */
    private function corrections(): array
    {
        return array_map(static fn (mixed $changes): mixed => \is_string($changes) ? json_decode($changes, true) : $changes, $this->em()->getConnection()->fetchFirstColumn("SELECT changes FROM audit_log WHERE action = 'purchase_order.receiver_corrected' ORDER BY at"));
    }

    /**
     * @param array<string, mixed> $order
     *
     * @return list<array<string, mixed>>
     */
    private function receipts(array $order): array
    {
        $receipts = [];
        foreach ($this->arrayAt($order, 'receipts') as $receipt) {
            self::assertIsArray($receipt);
            $receipts[] = $this->section(['receipt' => $receipt], 'receipt');
        }

        return $receipts;
    }

    private function companyPath(): string
    {
        return '/api/companies/'.$this->company->getId()->toRfc4122();
    }

    private function path(?string $id = null): string
    {
        return $this->companyPath().'/purchase-orders'.(null === $id ? '' : '/'.$id);
    }

    private function receiptPath(string $orderId, string $receiptId): string
    {
        return $this->path($orderId).'/receipts/'.$receiptId.'/receiver';
    }
}
