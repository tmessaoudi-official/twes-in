<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Concurrency\Support;

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
use App\Settings\Domain\Model\Setting;
use App\Settings\Domain\ValueObject\SettingAddress;
use App\Tenancy\Domain\Model\Company;
use App\Tests\Functional\ApiTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * A shop of its own in the stress database for a race of sales: a company provisioned as at sign-up, one stocked product,
 * a seller signed in, and the drafts the race will issue, written over HTTP as the screens write them. Every request
 * commits for real there (no per-test transaction), so every case builds what it races and nothing else reads it.
 */
abstract class SaleRaceTestCase extends ApiTestCase
{
    private const array SELLING = ['stock.read', 'stock.write', 'delivery_note.read', 'delivery_note.write', 'delivery_note.validate', 'invoice.read', 'invoice.write', 'invoice.issue', 'invoice.credit', 'quote.read', 'quote.write', 'purchase.read', 'purchase.write', 'company.read'];

    protected Company $company;
    protected string $productId;
    protected string $sellerId;

    /** « Sortie sans stock enregistré » for the race: allow, confirm or refuse. */
    abstract protected function saleWithoutStock(): string;

    /** What the product's place holds when the race starts. */
    abstract protected function onHand(): string;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = $this->createCompany('Quincaillerie');
        static::getContainer()->get(ProvisionCompany::class)->handle($this->company);
        $piece = static::getContainer()->get(UnitRepository::class)->ofCodeInCompany('C62', $this->company->getId());
        self::assertNotNull($piece);
        $now = new \DateTimeImmutable();
        $product = Product::create($this->company, 'ART-001', new ProductDetails('Perceuse', null, ProductKind::Goods, '250'), $piece, null, [], $now);
        $this->em()->persist($product);
        $this->em()->persist(new Setting(SettingAddress::company($this->company), 'article.stock_tracking', true, $now));
        $this->em()->persist(new Setting(SettingAddress::company($this->company), 'stock.sale_without_stock', $this->saleWithoutStock(), $now));
        $this->em()->flush();
        $this->productId = $product->getId()->toRfc4122();

        // The stress database outlives a case: every case signs in a seller of its own.
        $email = 'seller-'.bin2hex(random_bytes(6)).'@twes.local';
        $this->sellerId = $this->createUser($email, 'password-1234', $this->company, self::SELLING, 'seller')->getId()->toRfc4122();
        $this->login($email, 'password-1234');
        self::assertResponseIsSuccessful();
        $this->postJson($this->path('stock-movements'), ['operation' => 'receive', 'productId' => $this->productId, 'locationId' => $this->defaultLocationId(), 'quantity' => $this->onHand()]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
    }

    /**
     * A draft of `$resource` (delivery notes, invoices, quotes, purchase orders) for one unit of the product, made out to
     * `$partyId`: the customer, or the vendor of a purchase order.
     */
    protected function draft(string $resource, string $partyId): string
    {
        $lines = [['productId' => $this->productId, 'quantity' => '1']];
        $body = match ($resource) {
            'invoices' => ['customerId' => $partyId, 'establishmentId' => null, 'customerReference' => null, 'notesInternal' => null, 'lines' => $lines, 'supplyDate' => null, 'paymentTermsDays' => null, 'notesPrinted' => null, 'discountAmount' => null, 'documentTaxComponentIds' => null],
            'delivery-notes' => ['customerId' => $partyId, 'establishmentId' => null, 'customerReference' => null, 'notesInternal' => null, 'lines' => $lines, 'deliveryDate' => null, 'deliveryAddressLine1' => null, 'deliveryAddressLine2' => null, 'deliveryPostalCode' => null, 'deliveryCity' => null, 'deliveryCountryCode' => null, 'remarksPrinted' => null],
            'quotes' => ['customerId' => $partyId, 'establishmentId' => null, 'customerReference' => null, 'notesPrinted' => null, 'notesInternal' => null, 'discountAmount' => null, 'lines' => $lines],
            'purchase-orders' => ['vendorId' => $partyId, 'lines' => $lines],
            default => throw new \LogicException(\sprintf('No draft of %s is written here.', $resource)),
        };
        $this->postJson($this->path($resource), $body);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        return $this->stringAt($this->json(), 'id');
    }

    protected function vendor(): string
    {
        $company = $this->em()->find(Company::class, $this->company->getId());
        self::assertNotNull($company);
        $vendor = Vendor::create($company, 'FRN-0001', new VendorProfile('Outillage du Sud'), new \DateTimeImmutable());
        $this->em()->persist($vendor);
        $this->em()->flush();

        return $vendor->getId()->toRfc4122();
    }

    protected function customer(): string
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
    protected function levels(): array
    {
        $this->getJson($this->path('stock-levels').'?productId='.$this->productId);
        self::assertResponseIsSuccessful();

        return $this->jsonList();
    }

    protected function path(string $resource): string
    {
        return '/api/companies/'.$this->company->getId()->toRfc4122().'/'.$resource;
    }

    private function defaultLocationId(): string
    {
        $this->getJson($this->path('stock-locations'));
        self::assertResponseIsSuccessful();

        return $this->stringAt($this->jsonList()[0], 'id');
    }
}
