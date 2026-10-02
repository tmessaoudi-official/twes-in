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
use App\Module\Customers\Domain\CustomerGroup;
use App\Module\Customers\Domain\CustomerKind;
use App\Module\Customers\Domain\CustomerProfile;
use App\Module\Products\Domain\Product;
use App\Module\Products\Domain\ProductDetails;
use App\Module\Products\Domain\ProductKind;
use App\Tenancy\Domain\Company;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Component\HttpFoundation\Response;

final class PriceListsTest extends ApiTestCase
{
    private Company $company;
    private string $screw;
    private string $nut;
    private string $theirProduct;
    private string $group;
    private string $customer;
    private string $otherCustomer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = $this->createCompany('Acme');
        static::getContainer()->get(ProvisionCompany::class)->handle($this->company);
        $globex = $this->createCompany('Globex');
        static::getContainer()->get(ProvisionCompany::class)->handle($globex);
        $now = new \DateTimeImmutable();
        $units = static::getContainer()->get(UnitRepository::class);
        $piece = $units->ofCodeInCompany('C62', $this->company->getId());
        $theirPiece = $units->ofCodeInCompany('C62', $globex->getId());
        self::assertNotNull($piece);
        self::assertNotNull($theirPiece);
        $screw = Product::create($this->company, 'ART-001', new ProductDetails('Vis', null, ProductKind::Goods, '1250'), $piece, null, [], $now);
        $nut = Product::create($this->company, 'ART-002', new ProductDetails('Écrou', null, ProductKind::Goods, '300'), $piece, null, [], $now);
        $theirs = Product::create($globex, 'ART-009', new ProductDetails('Boulon', null, ProductKind::Goods, '5'), $theirPiece, null, [], $now);
        $group = CustomerGroup::create($this->company, 'Revendeurs', null, $now);
        static::getContainer()->get(SyncCustomerTaxRegimes::class)->handle();
        $regime = static::getContainer()->get(CustomerTaxRegimeRepository::class)->ofPresetAndCode('TN', 'standard');
        self::assertNotNull($regime);
        $customer = Customer::create($this->company, 'CLI-0001', new CustomerProfile(CustomerKind::Company, 'Maison Durand'), $group, $regime, [], $now);
        $other = Customer::create($this->company, 'CLI-0002', new CustomerProfile(CustomerKind::Individual, 'Amel'), null, $regime, [], $now);
        foreach ([$screw, $nut, $theirs, $group, $customer, $other] as $entity) {
            $this->em()->persist($entity);
        }
        $this->em()->flush();
        [$this->screw, $this->nut, $this->theirProduct, $this->group, $this->customer, $this->otherCustomer] = array_map(
            static fn (object $entity): string => $entity->getId()->toRfc4122(),
            [$screw, $nut, $theirs, $group, $customer, $other],
        );
    }

    public function testAWriterCreatesReadsRevisesAndDeletesAListWithItsPrices(): void
    {
        $this->signedIn(['product.read', 'product.write']);

        $this->postJson($this->lists(), ['name' => 'Revendeurs', 'customerGroupId' => $this->group, 'validFrom' => '2026-10-01', 'items' => [
            ['productId' => $this->screw, 'minQuantity' => '10', 'unitPriceNet' => '11.5'],
            ['productId' => $this->screw, 'minQuantity' => '1', 'unitPriceNet' => '12'],
        ]]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $created = $this->json();
        $id = $this->stringAt($created, 'id');
        self::assertSame([$this->group, '2026-10-01', true, 2], [$created['customerGroupId'], $created['validFrom'], $created['isActive'], $created['itemCount']]);

        $this->getJson($this->lists($id));
        $rows = [];
        foreach ($this->arrayAt($this->json(), 'items') as $row) {
            self::assertIsArray($row);
            $rows[] = [$row['productReference'], $row['productName'], $row['minQuantity'], $row['unitPriceNet']];
        }
        sort($rows);
        self::assertSame([['ART-001', 'Vis', '1.000', '12.0000'], ['ART-001', 'Vis', '10.000', '11.5000']], $rows);

        $this->getJson($this->lists());
        self::assertSame(['Revendeurs'], array_column($this->jsonList(), 'name'));
        self::assertSame(2, $this->jsonList()[0]['itemCount']);
        self::assertArrayNotHasKey('items', $this->jsonList()[0], 'the collection answers no rows');

        $this->sendJson('PUT', $this->lists($id), ['name' => 'Revendeurs 2026', 'customerGroupId' => $this->group, 'isActive' => false]);
        self::assertResponseIsSuccessful();
        self::assertSame(2, $this->json()['itemCount'], 'leaving items out keeps the prices');

        $this->sendJson('PUT', $this->lists($id), ['name' => 'Revendeurs 2026', 'items' => [['productId' => $this->nut, 'minQuantity' => '1', 'unitPriceNet' => '280']]]);
        self::assertResponseIsSuccessful();
        self::assertNull($this->json()['customerGroupId'], 'a save writes the whole scope');
        self::assertSame(['ART-002'], array_column($this->arrayAt($this->json(), 'items'), 'productReference'));

        $this->sendJson('DELETE', $this->lists($id));
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
        $this->getJson($this->lists());
        self::assertSame([], $this->jsonList());
        $connection = $this->em()->getConnection();
        self::assertEquals(0, $connection->fetchOne('SELECT COUNT(*) FROM price_list_item'), 'the rows go with their list');
        $actions = $connection->fetchFirstColumn("SELECT action FROM audit_log WHERE entity_type = 'price_list' ORDER BY at, action");
        self::assertEqualsCanonicalizing(['price_list.created', 'price_list.revised', 'price_list.revised', 'price_list.deleted'], $actions);
        self::assertEquals(0, $connection->fetchOne("SELECT COUNT(*) FROM audit_log WHERE entity_type = 'price_list' AND changes::text LIKE '%11.5%'"), 'the audit never keeps a price');
    }

    public function testARefusedListAnswersConflictOrUnprocessableNamingTheField(): void
    {
        $this->signedIn(['product.read', 'product.write']);
        $this->postJson($this->lists(), ['name' => 'Public']);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        $this->postJson($this->lists(), ['name' => 'Public']);
        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);

        foreach ([
            'customerId' => ['name' => 'A', 'customerGroupId' => $this->group, 'customerId' => $this->customer],
            'validTo' => ['name' => 'B', 'validFrom' => '2026-10-05', 'validTo' => '2026-10-04'],
            'items.0.minQuantity' => ['name' => 'C', 'items' => [['productId' => $this->screw, 'minQuantity' => '0', 'unitPriceNet' => '1']]],
            'items.0.unitPriceNet' => ['name' => 'D', 'items' => [['productId' => $this->screw, 'minQuantity' => '1', 'unitPriceNet' => '1.00001']]],
            'items.1.minQuantity' => ['name' => 'E', 'items' => [['productId' => $this->screw, 'minQuantity' => '5', 'unitPriceNet' => '1'], ['productId' => $this->screw, 'minQuantity' => '5.000', 'unitPriceNet' => '2']]],
            'items.0.productId' => ['name' => 'F', 'items' => [['productId' => $this->theirProduct, 'minQuantity' => '1', 'unitPriceNet' => '1']]],
            'customerGroupId' => ['name' => 'G', 'customerGroupId' => '0192b7a0-0000-7000-8000-000000000001'],
        ] as $field => $body) {
            $this->postJson($this->lists(), $body);
            self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, $field);
            self::assertStringContainsString($field, $this->stringAt($this->json(), 'detail'));
        }
        foreach ([['name' => ' '], ['name' => 'H', 'validFrom' => 'tomorrow'], ['name' => 'I', 'customerId' => 'not-a-uuid']] as $body) {
            $this->postJson($this->lists(), $body);
            self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $this->getJson($this->lists());
        self::assertSame(['Public'], array_column($this->jsonList(), 'name'), 'a refused list leaves nothing behind');
    }

    public function testAReaderReadsOnlyAndAnotherCompanysListIsNotFound(): void
    {
        $globex = $this->createCompany('Globex 2');
        $this->em()->getConnection()->insert('price_list', ['id' => '0192b7a0-0000-7000-8000-0000000000aa', 'name' => 'Public', 'is_active' => 'true', 'created_at' => '2026-10-02 09:00:00', 'updated_at' => '2026-10-02 09:00:00', 'company_id' => $globex->getId()->toRfc4122()]);
        $this->signedIn(['product.read']);

        $this->getJson($this->lists());
        self::assertResponseIsSuccessful();
        self::assertSame([], $this->jsonList(), "another company's list is not listed");
        $this->postJson($this->lists(), ['name' => 'Public']);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);

        foreach (['GET', 'DELETE'] as $method) {
            $this->sendJson($method, $this->lists('0192b7a0-0000-7000-8000-0000000000aa'));
            self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND, $method);
        }
        $this->sendJson('PUT', $this->lists('0192b7a0-0000-7000-8000-0000000000aa'), ['name' => 'Mien']);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testNobodySignedInReadsNothing(): void
    {
        $this->getJson($this->lists());
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        $this->getJson($this->price($this->screw));
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testTheDatabaseRefusesWhatTheDomainRefuses(): void
    {
        $connection = $this->em()->getConnection();
        $company = $this->company->getId()->toRfc4122();
        $base = ['is_active' => 'true', 'created_at' => '2026-10-02 09:00:00', 'updated_at' => '2026-10-02 09:00:00', 'company_id' => $company];
        $connection->insert('price_list', ['id' => '0192b7a0-0000-7000-8000-0000000000b1', 'name' => 'Une'] + $base);

        foreach ([
            [UniqueConstraintViolationException::class, ['id' => '0192b7a0-0000-7000-8000-0000000000b2', 'name' => 'Une'] + $base],
            [DriverException::class, ['id' => '0192b7a0-0000-7000-8000-0000000000b3', 'name' => 'Deux', 'customer_id' => $this->customer, 'customer_group_id' => $this->group] + $base],
            [DriverException::class, ['id' => '0192b7a0-0000-7000-8000-0000000000b4', 'name' => 'Trois', 'valid_from' => '2026-10-05', 'valid_to' => '2026-10-04'] + $base],
        ] as [$expected, $row]) {
            $connection->beginTransaction();
            try {
                $connection->insert('price_list', $row);
                self::fail('The database kept '.$row['name']);
            } catch (\Throwable $refused) {
                $connection->rollBack();
                self::assertInstanceOf($expected, $refused, $row['name']);
                self::assertSame(DriverException::class === $expected, str_contains($refused->getMessage(), 'violates check constraint'), $row['name'].': '.$refused->getMessage());
            }
        }
        $item = ['price_list_id' => '0192b7a0-0000-7000-8000-0000000000b1', 'product_id' => $this->screw, 'company_id' => $company];
        $connection->insert('price_list_item', ['id' => '0192b7a0-0000-7000-8000-0000000000c1', 'min_quantity' => '1', 'unit_price_net' => '1'] + $item);
        foreach ([
            [UniqueConstraintViolationException::class, ['id' => '0192b7a0-0000-7000-8000-0000000000c2', 'min_quantity' => '1', 'unit_price_net' => '2']],
            [DriverException::class, ['id' => '0192b7a0-0000-7000-8000-0000000000c3', 'min_quantity' => '0', 'unit_price_net' => '2']],
            [DriverException::class, ['id' => '0192b7a0-0000-7000-8000-0000000000c4', 'min_quantity' => '2', 'unit_price_net' => '-1']],
        ] as [$expected, $row]) {
            $connection->beginTransaction();
            try {
                $connection->insert('price_list_item', $row + $item);
                self::fail('The database kept a bad row.');
            } catch (\Throwable $refused) {
                $connection->rollBack();
                self::assertInstanceOf($expected, $refused);
                self::assertSame(DriverException::class === $expected, str_contains($refused->getMessage(), 'violates check constraint'));
            }
        }
    }

    public function testThePriceOfAProductFollowsTheCustomerTheQuantityAndTheDay(): void
    {
        $this->signedIn(['product.read', 'product.write']);
        $this->postJson($this->lists(), ['name' => 'Public', 'items' => [['productId' => $this->screw, 'minQuantity' => '1', 'unitPriceNet' => '1000']]]);
        $this->postJson($this->lists(), ['name' => 'Revendeurs', 'customerGroupId' => $this->group, 'items' => [
            ['productId' => $this->screw, 'minQuantity' => '1', 'unitPriceNet' => '900'],
            ['productId' => $this->screw, 'minQuantity' => '10', 'unitPriceNet' => '800'],
        ]]);
        $this->postJson($this->lists(), ['name' => 'Durand', 'customerId' => $this->customer, 'validFrom' => '2026-11-01', 'items' => [['productId' => $this->screw, 'minQuantity' => '1', 'unitPriceNet' => '850']]]);

        $expect = function (string $query, string $price, ?string $list, ?string $minimum): void {
            $this->getJson($this->price($this->screw, $query));
            self::assertResponseIsSuccessful($query);
            self::assertSame([$price, $list, $minimum], [$this->json()['unitPriceNet'], $this->json()['priceListName'], $this->json()['minQuantity']], $query);
        };
        $expect('', '1000.0000', 'Public', '1.000');
        $expect('?customerId='.$this->otherCustomer.'&quantity=50', '1000.0000', 'Public', '1.000');
        $expect('?customerId='.$this->customer.'&on=2026-10-15', '900.0000', 'Revendeurs', '1.000');
        $expect('?customerId='.$this->customer.'&quantity=10&on=2026-10-15', '800.0000', 'Revendeurs', '10.000');
        $expect('?customerId='.$this->customer.'&quantity=9.999&on=2026-10-15', '900.0000', 'Revendeurs', '1.000');
        $expect('?customerId='.$this->customer.'&on=2026-11-01', '850.0000', 'Durand', '1.000');

        $this->getJson($this->price($this->nut, '?customerId='.$this->customer));
        self::assertResponseIsSuccessful();
        self::assertSame(['300.0000', null, null], [$this->json()['unitPriceNet'], $this->json()['priceListName'], $this->json()['minQuantity']], 'a product no list prices keeps its shelf price');
    }

    public function testAnUnreadableQuestionAboutAPriceIsRefused(): void
    {
        $this->signedIn(['product.read']);

        foreach (['?quantity=0', '?quantity=-1', '?quantity=1.2345', '?quantity=abc', '?on=tomorrow', '?on=2026-13-45', '?customerId=not-a-uuid'] as $query) {
            $this->getJson($this->price($this->screw, $query));
            self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, $query);
        }
        $this->getJson($this->price($this->theirProduct));
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        $this->getJson($this->price('0192b7a0-0000-7000-8000-000000000001'));
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    /** @param list<string> $permissions */
    private function signedIn(array $permissions): void
    {
        $this->createUser('sales@twes.local', 'password-1234', $this->company, $permissions, 'member');
        $this->login('sales@twes.local', 'password-1234');
        self::assertResponseIsSuccessful();
    }

    private function lists(?string $id = null): string
    {
        return '/api/companies/'.$this->company->getId()->toRfc4122().'/price-lists'.(null === $id ? '' : '/'.$id);
    }

    private function price(string $productId, string $query = ''): string
    {
        return '/api/companies/'.$this->company->getId()->toRfc4122().'/products/'.$productId.'/price'.$query;
    }
}
