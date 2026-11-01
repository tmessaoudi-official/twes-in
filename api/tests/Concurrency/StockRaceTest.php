<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Concurrency;

use App\Fiscal\Application\Company\ProvisionCompany;
use App\Fiscal\Application\Regime\SyncCustomerTaxRegimes;
use App\Fiscal\Domain\Repository\CustomerTaxRegimeRepository;
use App\Fiscal\Domain\Repository\UnitRepository;
use App\Module\Customers\Domain\Model\Customer;
use App\Module\Products\Domain\Model\Product;
use App\Module\Products\Domain\ValueObject\ProductDetails;
use App\Module\Products\Domain\ValueObject\ProductKind;
use App\Settings\Domain\Model\Setting;
use App\Settings\Domain\ValueObject\SettingAddress;
use App\Tenancy\Domain\Model\Company;
use App\Tests\Concurrency\Support\Outcome;
use App\Tests\Concurrency\Support\Race;
use App\Tests\Concurrency\Support\Steps\SellDocument;
use App\Tests\Functional\ApiTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * « Sortie sans stock enregistré » set to refuse, two cashiers selling the last unit at the same moment: one sale goes
 * through, the other is refused naming its short line, and the stock never goes below zero. The check and the stock it
 * reads must be one, under the stock's lock in the issuing transaction: read beforehand, both sales pass. Each case
 * builds a company of its own in the stress database, where every request commits for real.
 */
final class StockRaceTest extends ApiTestCase
{
    private const array SELLING = ['stock.read', 'stock.write', 'delivery_note.read', 'delivery_note.write', 'delivery_note.validate', 'invoice.read', 'invoice.write', 'invoice.issue'];

    private Company $company;
    private string $productId;
    private string $sellerId;

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
        $this->em()->persist(new Setting(SettingAddress::company($this->company), 'stock.sale_without_stock', 'refuse', $now));
        $this->em()->flush();
        $this->productId = $product->getId()->toRfc4122();

        // The stress database outlives a case: every case signs in a seller of its own.
        $email = 'seller-'.bin2hex(random_bytes(6)).'@twes.local';
        $this->sellerId = $this->createUser($email, 'password-1234', $this->company, self::SELLING, 'seller')->getId()->toRfc4122();
        $this->login($email, 'password-1234');
        self::assertResponseIsSuccessful();
        $this->postJson($this->path('stock-movements'), ['operation' => 'receive', 'productId' => $this->productId, 'locationId' => $this->defaultLocationId(), 'quantity' => '1']);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
    }

    public function testTwoDeliveryNotesTakingTheLastUnitAtOnceSellItOnce(): void
    {
        $customerId = $this->customer();
        $this->raceForTheLastUnit(['delivery_note', 'delivery_note'], [$this->draft('delivery-notes', $customerId), $this->draft('delivery-notes', $customerId)]);
    }

    public function testTwoInvoicesTakingTheLastUnitAtOnceSellItOnce(): void
    {
        $customerId = $this->customer();
        $this->raceForTheLastUnit(['invoice', 'invoice'], [$this->draft('invoices', $customerId), $this->draft('invoices', $customerId)]);
    }

    /**
     * Two series, so no number's lock lines them up: a note and an invoice of the same goods are held apart only by the
     * stock's own lock, under which the shortfall is read.
     */
    public function testADeliveryNoteAndAnInvoiceTakingTheLastUnitAtOnceSellItOnce(): void
    {
        $customerId = $this->customer();
        $this->raceForTheLastUnit(['delivery_note', 'invoice'], [$this->draft('delivery-notes', $customerId), $this->draft('invoices', $customerId)]);
    }

    /**
     * @param list<string> $kinds
     * @param list<string> $documents
     */
    private function raceForTheLastUnit(array $kinds, array $documents): void
    {
        $outcomes = Race::run(SellDocument::class, 2, ['kinds' => $kinds, 'company' => $this->company->getId()->toRfc4122(), 'documents' => $documents, 'actor' => $this->sellerId]);

        $kinds = array_map(static fn (Outcome $outcome): string => $outcome->kind, $outcomes);
        sort($kinds);
        self::assertSame([Outcome::ERROR, Outcome::OK], $kinds, implode("\n", array_map(static fn (Outcome $outcome): string => $outcome->describe(), $outcomes)));
        foreach ($outcomes as $outcome) {
            if (Outcome::ERROR === $outcome->kind) {
                self::assertStringContainsString('ShortOfStock', $outcome->message, 'the sale left over is refused for its short line');
            }
        }
        self::assertSame(['0.000'], array_column($this->levels(), 'quantity'), 'the last unit sold once, the stock never below zero');
    }

    private function draft(string $resource, string $customerId): string
    {
        $header = 'invoices' === $resource
            ? ['supplyDate' => null, 'paymentTermsDays' => null, 'notesPrinted' => null, 'discountAmount' => null, 'documentTaxComponentIds' => null]
            : ['deliveryDate' => null, 'deliveryAddressLine1' => null, 'deliveryAddressLine2' => null, 'deliveryPostalCode' => null, 'deliveryCity' => null, 'deliveryCountryCode' => null, 'remarksPrinted' => null];
        $this->postJson($this->path($resource), [
            'customerId' => $customerId,
            'establishmentId' => null,
            'customerReference' => null,
            'notesInternal' => null,
            'lines' => [['productId' => $this->productId, 'quantity' => '1']],
        ] + $header);
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
        $this->getJson($this->path('stock-levels').'?productId='.$this->productId);
        self::assertResponseIsSuccessful();

        return $this->jsonList();
    }

    private function defaultLocationId(): string
    {
        $this->getJson($this->path('stock-locations'));
        self::assertResponseIsSuccessful();

        return $this->stringAt($this->jsonList()[0], 'id');
    }

    private function path(string $resource): string
    {
        return '/api/companies/'.$this->company->getId()->toRfc4122().'/'.$resource;
    }
}
