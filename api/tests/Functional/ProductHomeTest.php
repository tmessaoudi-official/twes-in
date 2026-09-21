<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Fiscal\Application\Company\ProvisionCompany;
use App\Fiscal\Domain\UnitRepository;
use App\Settings\Domain\Setting;
use App\Settings\Domain\SettingAddress;
use App\Tenancy\Domain\Company;
use App\Tenancy\Domain\Establishment;
use App\Tenancy\Domain\EstablishmentRepository;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

/**
 * Where a product normally lives (docs/SPEC.md row 101): one home per establishment, proposed when receiving. A
 * home is a convenience and never a rule — nothing here refuses goods put anywhere else.
 */
final class ProductHomeTest extends ApiTestCase
{
    private Company $company;
    private string $establishmentId;
    private string $productId;
    private string $rackId;
    private string $bayId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = $this->createCompany('Quincaillerie');
        static::getContainer()->get(ProvisionCompany::class)->handle($this->company);
        $this->establishmentId = static::getContainer()->get(EstablishmentRepository::class)->ofCompany($this->company->getId())[0]->getId()->toRfc4122();
        // Off by default, and the stock picker offers only what is tracked: without this the product under test is
        // not among what a receipt can name at all, and the proposal has nothing to be carried on.
        $this->em()->persist(new Setting(SettingAddress::company($this->company), 'article.stock_tracking', true, new \DateTimeImmutable()));
        $this->em()->flush();
    }

    public function testAProductIsGivenOneHomePerEstablishmentAndMovedRatherThanDoubled(): void
    {
        $this->signedIn(['stock.read', 'stock.write', 'product.read', 'product.write']);
        $this->ground();

        $this->getJson($this->homes());
        self::assertResponseIsSuccessful();
        self::assertSame([], $this->jsonList(), 'most products have none');

        $this->sendJson('PUT', $this->homes(), ['locationId' => $this->rackId]);
        self::assertResponseIsSuccessful();
        self::assertSame([$this->establishmentId, $this->rackId, 'R1'], [
            $this->json()['establishmentId'], $this->json()['locationId'], $this->json()['locationCode'],
        ], 'the establishment is the location’s own, never sent');

        // One home per establishment: naming another shelf moves it rather than adding a second.
        $this->sendJson('PUT', $this->homes(), ['locationId' => $this->bayId]);
        self::assertResponseIsSuccessful();
        $this->getJson($this->homes());
        self::assertSame([$this->bayId], array_column($this->jsonList(), 'locationId'));

        $this->sendJson('DELETE', $this->homes().'/'.$this->establishmentId);
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
        $this->getJson($this->homes());
        self::assertSame([], $this->jsonList());

        // Clearing what is already clear is what pressing the same button twice means.
        $this->sendJson('DELETE', $this->homes().'/'.$this->establishmentId);
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
    }

    public function testAReceiptProposesTheHomeOfTheProductItIsFor(): void
    {
        $this->signedIn(['stock.read', 'stock.write', 'product.read', 'product.write']);
        $this->ground();

        // Read row by row, never with array_column: it drops a row whose value is null, which is exactly the
        // answer under test here, so a product proposing nothing would read as a product that is not offered.
        self::assertNull($this->pick()['homeLocationId'], 'a product with no home proposes none');

        $this->sendJson('PUT', $this->homes(), ['locationId' => $this->rackId]);
        self::assertResponseIsSuccessful();

        // Carried by the picker the receipt already reads, so the form proposes it without a second request.
        self::assertSame($this->rackId, $this->pick()['homeLocationId']);
    }

    /**
     * A picker knows which product was chosen, not which site the goods are arriving at, so a product at home in
     * two establishments is offered NO proposal rather than one that is right half the time — a wrong shelf is
     * accepted without being read, which is worse than an empty box.
     */
    public function testAProductAtHomeInTwoEstablishmentsProposesNeither(): void
    {
        $this->signedIn(['stock.read', 'stock.write', 'product.read', 'product.write']);
        $this->ground();
        $this->sendJson('PUT', $this->homes(), ['locationId' => $this->rackId]);
        self::assertResponseIsSuccessful();
        self::assertSame($this->rackId, $this->pick()['homeLocationId'], 'one home is proposed');

        $path = '/api/companies/'.$this->company->getId()->toRfc4122();
        // The kernel reboots between requests, so the company kept in setUp is detached by now: find it again.
        $company = $this->em()->find(Company::class, $this->company->getId());
        self::assertInstanceOf(Company::class, $company);
        $second = Establishment::create($company, 'LYON', 'Lyon', false, new \DateTimeImmutable());
        $this->em()->persist($second);
        $this->em()->flush();
        $this->postJson($path.'/stock-locations', [
            'establishmentId' => $second->getId()->toRfc4122(),
            'parentId' => null,
            'kind' => 'rack',
            'code' => 'R9',
            'name' => 'Rayonnage 9',
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        $this->sendJson('PUT', $this->homes(), ['locationId' => $this->stringAt($this->json(), 'id')]);

        self::assertResponseIsSuccessful();
        self::assertCount(2, $this->jsonHomes(), 'a home in each establishment, neither replacing the other');
        self::assertNull($this->pick()['homeLocationId'], 'two homes propose neither');
    }

    /** @return list<array<string, mixed>> the homes this test's product has */
    private function jsonHomes(): array
    {
        $this->getJson($this->homes());
        self::assertResponseIsSuccessful();

        return $this->jsonList();
    }

    /** @return array<string, mixed> this test's product, as the stock picker offers it */
    private function pick(): array
    {
        $this->getJson('/api/companies/'.$this->company->getId()->toRfc4122().'/stock-options/products');
        self::assertResponseIsSuccessful();
        foreach ($this->jsonList() as $pick) {
            if ($pick['id'] === $this->productId) {
                return $pick;
            }
        }
        self::fail('The stock picker does not offer the product under test.');
    }

    public function testWhatIsRefusedIsRefusedWithItsField(): void
    {
        $this->signedIn(['stock.read', 'stock.write', 'product.read', 'product.write']);
        $this->ground();

        $this->sendJson('PUT', $this->homes(), ['locationId' => Uuid::v7()->toRfc4122()]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertStringContainsString('locationId', (string) $this->client->getResponse()->getContent());

        // The product is what the path addresses, so one that is not this company's is a 404, not a 422.
        $path = '/api/companies/'.$this->company->getId()->toRfc4122().'/products/'.Uuid::v7()->toRfc4122().'/home-locations';
        $this->sendJson('PUT', $path, ['locationId' => $this->rackId]);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testAHomeIsSetWithTheProductPermissionsAndNotWithTheStockOnes(): void
    {
        // A home is an attribute of the product, not an arrangement of the warehouse: it authorizes nothing, and
        // the person who fills a product file in is not necessarily the person who moves stock.
        $this->signedIn(['stock.read', 'stock.write', 'product.read', 'product.write']);
        $this->ground();
        // The same company, seen by somebody who may read a product and not revise one.
        $this->signedIn(['stock.read', 'stock.write', 'product.read'], 'reader@twes.local', 'reader');

        $this->getJson($this->homes());
        self::assertResponseIsSuccessful();
        $this->sendJson('PUT', $this->homes(), ['locationId' => $this->rackId]);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND, 'reading a product is not revising one');
    }

    private function company(): Company
    {
        $company = $this->em()->find(Company::class, $this->company->getId());
        self::assertNotNull($company);

        return $company;
    }

    private function homes(): string
    {
        return '/api/companies/'.$this->company->getId()->toRfc4122().'/products/'.$this->productId.'/home-locations';
    }

    /** A product and two shelves to put it on. */
    private function ground(): void
    {
        $company = '/api/companies/'.$this->company->getId()->toRfc4122();
        $this->getJson($company.'/stock-locations');
        self::assertResponseIsSuccessful();
        $siteId = $this->stringAt($this->jsonList()[0], 'id');

        foreach ([['R1', 'Rayonnage 1'], ['B1', 'Baie 1']] as [$code, $name]) {
            $this->postJson($company.'/stock-locations', [
                'establishmentId' => $this->establishmentId,
                'parentId' => $siteId,
                'kind' => 'rack',
                'code' => $code,
                'name' => $name,
            ]);
            self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
            if ('R1' === $code) {
                $this->rackId = $this->stringAt($this->json(), 'id');
            } else {
                $this->bayId = $this->stringAt($this->json(), 'id');
            }
        }

        $piece = static::getContainer()->get(UnitRepository::class)->ofCodeInCompany('C62', $this->company->getId());
        self::assertNotNull($piece);
        $this->postJson($company.'/products', [
            'reference' => 'VIS-6X40',
            'name' => 'Vis 6x40',
            'description' => null,
            'kind' => 'goods',
            'unitId' => $piece->getId()->toRfc4122(),
            'unitPriceNet' => '0.45',
            'costPrice' => null,
            'categoryId' => null,
            'barcode' => null,
            'defaultTaxComponentIds' => [],
            'customFields' => [],
            'isActive' => true,
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $this->productId = $this->stringAt($this->json(), 'id');
    }

    /** @param list<string> $permissions */
    private function signedIn(array $permissions, string $email = 'home@twes.local', string $role = 'member'): void
    {
        // Re-found: the test client reboots the kernel between requests, so the company this test kept from the
        // first one is detached by the time a second sign-in needs it.
        $this->createUser($email, 'password-1234', $this->company(), $permissions, $role);
        $this->login($email, 'password-1234');
        self::assertResponseIsSuccessful();
    }
}
