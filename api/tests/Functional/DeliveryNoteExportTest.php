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
use App\Module\Customers\Domain\CustomerKind;
use App\Module\Customers\Domain\CustomerProfile;
use App\Module\Products\Domain\Product;
use App\Module\Products\Domain\ProductDetails;
use App\Module\Products\Domain\ProductKind;
use App\Tenancy\Domain\Company;
use Symfony\Component\HttpFoundation\Response;

/** The delivery notes list exported as a file, under the search, status, customer and order the screen shows (docs/SPEC.md § 7, row 60). */
final class DeliveryNoteExportTest extends ApiTestCase
{
    private Company $company;
    private string $customerId;
    private string $productId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = $this->createCompany('Acme');
        static::getContainer()->get(ProvisionCompany::class)->handle($this->company);
        static::getContainer()->get(SyncCustomerTaxRegimes::class)->handle();
        $regime = static::getContainer()->get(CustomerTaxRegimeRepository::class)->ofPresetAndCode('TN', 'standard');
        self::assertNotNull($regime);
        $now = new \DateTimeImmutable();
        $customer = Customer::create($this->company, 'CLI-0001', new CustomerProfile(CustomerKind::Company, 'Carthage Conseil'), null, $regime, [], $now);
        $this->em()->persist($customer);
        $unit = static::getContainer()->get(UnitRepository::class)->ofCodeInCompany('C62', $this->company->getId());
        $tax = static::getContainer()->get(TaxComponentRepository::class)->ofCodeInCompany('TVA19', $this->company->getId());
        self::assertNotNull($unit);
        self::assertNotNull($tax);
        $product = Product::create($this->company, 'ART-001', new ProductDetails('Portable 14"', null, ProductKind::Goods, '100'), $unit, null, [$tax->getId()], $now);
        $this->em()->persist($product);
        $this->em()->flush();
        $this->customerId = $customer->getId()->toRfc4122();
        $this->productId = $product->getId()->toRfc4122();
        $this->createUser('sales@twes.local', 'password-1234', $this->company, ['delivery_note.read', 'delivery_note.write', 'delivery_note.validate'], 'member');
        $this->login('sales@twes.local', 'password-1234');
        $this->draft('PO-DRAFT', '1');
        $validated = $this->draft('PO-77', '2');
        $this->postJson($this->path().'/delivery-notes/'.$validated.'/validate', null);
        self::assertResponseIsSuccessful();
    }

    public function testEveryNoteIsOneRowWithItsFiguresAsDecimals(): void
    {
        $lines = $this->csv('/exports/delivery-notes.csv');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('delivery-notes.csv', (string) $this->client->getResponse()->headers->get('Content-Disposition'));
        $header = array_map(strval(...), str_getcsv(ltrim($lines[0], "\xEF\xBB\xBF"), escape: ''));
        self::assertSame(['number', 'status', 'customer_number', 'customer', 'issue_date', 'delivery_date', 'currency', 'total_net', 'total_tax', 'total', 'customer_reference'], $header);
        self::assertCount(3, $lines, 'the header and the two notes');
        $rows = array_map(static fn (string $line): array => array_combine($header, str_getcsv($line, escape: '')), \array_slice($lines, 1));
        $byReference = array_column($rows, null, 'customer_reference');
        self::assertSame('', $byReference['PO-DRAFT']['number'], 'a draft has no number yet');
        self::assertSame('draft', $byReference['PO-DRAFT']['status']);
        self::assertSame('CLI-0001', $byReference['PO-DRAFT']['customer_number']);
        self::assertSame('Carthage Conseil', $byReference['PO-DRAFT']['customer']);
        self::assertSame('validated', $byReference['PO-77']['status']);
        self::assertMatchesRegularExpression('/^BL-\d{4}-00001$/', (string) $byReference['PO-77']['number']);
        self::assertSame(['TND', '200.000', '38.000', '238.000'], [$byReference['PO-77']['currency'], $byReference['PO-77']['total_net'], $byReference['PO-77']['total_tax'], $byReference['PO-77']['total']]);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', (string) $byReference['PO-77']['issue_date']);
    }

    public function testTheSearchTheStatusAndTheOrderOfTheScreenNarrowAndSortTheFile(): void
    {
        self::assertCount(2, $this->csv('/exports/delivery-notes.csv?status=validated'), 'the header and the validated note');
        self::assertCount(2, $this->csv('/exports/delivery-notes.csv?status=draft'));
        self::assertCount(2, $this->csv('/exports/delivery-notes.csv?q=PO-77'));
        self::assertCount(1, $this->csv('/exports/delivery-notes.csv?q=nobody-here'), 'the header alone');
        self::assertCount(3, $this->csv('/exports/delivery-notes.csv?customerId='.$this->customerId));
        $lines = $this->csv('/exports/delivery-notes.csv?order[status]=desc');
        self::assertStringContainsString('validated', $lines[1]);
    }

    public function testAnXlsxIsOfferedToo(): void
    {
        $this->client->request('GET', $this->path().'/exports/delivery-notes.xlsx');

        self::assertResponseIsSuccessful();
        self::assertStringStartsWith('PK', $this->content(), 'a zip archive');
    }

    public function testWhoCannotReadDeliveryNotesIsAnsweredAsAStranger(): void
    {
        $this->createUser('stock@twes.local', 'password-1234', $this->em()->find(Company::class, $this->company->getId()), ['product.read'], 'stock');
        $this->login('stock@twes.local', 'password-1234');

        $this->client->request('GET', $this->path().'/exports/delivery-notes.csv');

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    private function draft(string $reference, string $quantity): string
    {
        $this->postJson($this->path().'/delivery-notes', [
            'customerId' => $this->customerId,
            'establishmentId' => null,
            'deliveryDate' => null,
            'deliveryAddressLine1' => null,
            'deliveryAddressLine2' => null,
            'deliveryPostalCode' => null,
            'deliveryCity' => null,
            'deliveryCountryCode' => null,
            'customerReference' => $reference,
            'remarksPrinted' => null,
            'notesInternal' => null,
            'lines' => [['productId' => $this->productId, 'quantity' => $quantity]],
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        return $this->stringAt($this->json(), 'id');
    }

    /** @return list<string> */
    private function csv(string $suffix): array
    {
        $this->client->request('GET', $this->path().$suffix);

        return array_values(array_filter(explode("\n", $this->content()), static fn (string $line): bool => '' !== trim($line)));
    }

    private function content(): string
    {
        // A file response is deleted once sent: what the browser got is what the client kept.
        return $this->client->getInternalResponse()->getContent();
    }

    private function path(): string
    {
        return '/api/companies/'.$this->company->getId()->toRfc4122();
    }
}
