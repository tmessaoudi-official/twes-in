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
use App\Fiscal\Domain\TaxComponentRepository;
use App\Fiscal\Domain\UnitRepository;
use App\Module\Customers\Domain\Customer;
use App\Module\Customers\Domain\CustomerGroup;
use App\Module\Customers\Domain\CustomerKind;
use App\Module\Customers\Domain\CustomerProfile;
use App\Module\Products\Domain\Product;
use App\Module\Products\Domain\ProductDetails;
use App\Module\Products\Domain\ProductKind;
use App\Tenancy\Domain\Company;
use Symfony\Component\HttpFoundation\Response;

/**
 * What the customer screen may say of a promotion: a price list tied to no customer and no group, active and inside
 * its dates, priced under the shelf, with its final tax-included price, its minimum quantity and its dates.
 */
final class CustomerScreenPromotionsTest extends ApiTestCase
{
    private Company $company;
    private string $screw;
    private string $nut;
    private string $group;
    private string $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = $this->createCompany('Acme');
        static::getContainer()->get(ProvisionCompany::class)->handle($this->company);
        $now = new \DateTimeImmutable();
        $piece = static::getContainer()->get(UnitRepository::class)->ofCodeInCompany('C62', $this->company->getId());
        self::assertNotNull($piece);
        $tva = static::getContainer()->get(TaxComponentRepository::class)->ofCodeInCompany('TVA19', $this->company->getId());
        self::assertNotNull($tva);
        $screw = Product::create($this->company, 'ART-001', new ProductDetails('Vis', null, ProductKind::Goods, '20', '8'), $piece, null, [$tva->getId()], $now);
        $nut = Product::create($this->company, 'ART-002', new ProductDetails('Écrou', null, ProductKind::Goods, '5', '2'), $piece, null, [], $now);
        $group = CustomerGroup::create($this->company, 'Revendeurs', null, $now);
        static::getContainer()->get(SyncCustomerTaxRegimes::class)->handle();
        $regime = static::getContainer()->get(CustomerTaxRegimeRepository::class)->ofPresetAndCode('TN', 'standard');
        self::assertNotNull($regime);
        $customer = Customer::create($this->company, 'CLI-0001', new CustomerProfile(CustomerKind::Company, 'Maison Durand'), $group, $regime, [], $now);
        foreach ([$screw, $nut, $group, $customer] as $entity) {
            $this->em()->persist($entity);
        }
        $this->em()->flush();
        [$this->screw, $this->nut, $this->group, $this->customer] = array_map(
            static fn (object $entity): string => $entity->getId()->toRfc4122(),
            [$screw, $nut, $group, $customer],
        );
    }

    public function testAnOpenListPricesAProductUnderTheShelfWithItsFinalPriceItsConditionAndItsDates(): void
    {
        $this->signedIn(['product.read', 'product.write']);
        $this->aList('Soldes', ['validFrom' => $this->day('-1 day'), 'validTo' => $this->day('+5 days')], [[$this->screw, '10', '15'], [$this->screw, '1', '18']]);

        $this->getJson($this->path([$this->screw, $this->nut]));

        self::assertResponseIsSuccessful();
        self::assertSame(['items'], array_keys($this->json()));
        self::assertSame(
            [
                ['productId' => $this->screw, 'price' => '21.420', 'minQuantity' => '1.000', 'startsOn' => $this->day('-1 day'), 'endsOn' => $this->day('+5 days')],
                ['productId' => $this->screw, 'price' => '17.850', 'minQuantity' => '10.000', 'startsOn' => $this->day('-1 day'), 'endsOn' => $this->day('+5 days')],
            ],
            $this->arrayAt($this->json(), 'items'),
            '18 and 15 net with 19 % VAT, by product then by minimum quantity',
        );
    }

    public function testAListForACustomerOrAGroupAnInactiveOneAnOutOfDateOneAndARowNotUnderTheShelfNeverShow(): void
    {
        $this->signedIn(['product.read', 'product.write']);
        $this->aList('Revendeurs', ['customerGroupId' => $this->group], [[$this->screw, '1', '10']]);
        $this->aList('Durand', ['customerId' => $this->customer], [[$this->screw, '1', '10']]);
        $this->aList('Éteinte', ['isActive' => false], [[$this->screw, '1', '10']]);
        $this->aList('Demain', ['validFrom' => $this->day('+1 day')], [[$this->screw, '1', '10']]);
        $this->aList('Hier', ['validTo' => $this->day('-1 day')], [[$this->screw, '1', '10']]);
        $this->aList('Cher', [], [[$this->screw, '1', '20'], [$this->nut, '1', '9']]);

        $this->getJson($this->path([$this->screw, $this->nut]));

        self::assertSame([], $this->arrayAt($this->json(), 'items'));
    }

    public function testOnlyTheProductsAskedForAreAnswered(): void
    {
        $this->signedIn(['product.read', 'product.write']);
        $this->aList('Soldes', [], [[$this->screw, '1', '10'], [$this->nut, '1', '3']]);

        $this->getJson($this->path([$this->nut]));

        self::assertSame([$this->nut], array_column($this->arrayAt($this->json(), 'items'), 'productId'));
    }

    public function testSomebodyWhoCannotReadProductsAndAnotherCompanyAreAnsweredAsAStranger(): void
    {
        $this->signedIn(['company.read']);
        $this->getJson($this->path([$this->screw]));
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);

        $other = $this->createCompany('Globex');
        $this->getJson('/api/companies/'.$other->getId()->toRfc4122().'/customer-screen/promotions?ids[]='.$this->screw);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    /**
     * @param array<string, mixed>                         $fields
     * @param list<array{0: string, 1: string, 2: string}> $rows   product id, minimum quantity, net unit price
     */
    private function aList(string $name, array $fields, array $rows): void
    {
        $this->postJson(
            '/api/companies/'.$this->company->getId()->toRfc4122().'/price-lists',
            ['name' => $name, ...$fields, 'items' => array_map(static fn (array $row): array => ['productId' => $row[0], 'minQuantity' => $row[1], 'unitPriceNet' => $row[2]], $rows)],
        );
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
    }

    private function day(string $modifier): string
    {
        return new \DateTimeImmutable('now', new \DateTimeZone($this->company->getTimezone()))->modify($modifier)->format('Y-m-d');
    }

    /** @param list<string> $ids */
    private function path(array $ids): string
    {
        return '/api/companies/'.$this->company->getId()->toRfc4122().'/customer-screen/promotions?'.implode('&', array_map(static fn (string $id): string => 'ids[]='.$id, $ids));
    }

    /** @param list<string> $permissions */
    private function signedIn(array $permissions): void
    {
        $this->createUser('clerk@twes.local', 'password-1234', $this->company, $permissions, 'clerk');
        $this->login('clerk@twes.local', 'password-1234');
        self::assertResponseIsSuccessful();
    }
}
