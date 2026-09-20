<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Fiscal\Application\Company\ProvisionCompany;
use App\Fiscal\Application\Regime\SyncCustomerTaxRegimes;
use App\Fiscal\Domain\UnitRepository;
use App\Module\Customers\Application\CustomerInput;
use App\Module\Customers\Application\ManageCustomers;
use App\Module\Customers\Domain\CustomerKind;
use App\Module\Customers\Domain\CustomerProfile;
use App\Module\Products\Domain\Product;
use App\Module\Products\Domain\ProductDetails;
use App\Module\Products\Domain\ProductKind;
use App\Shared\Domain\PostalAddress;
use App\Tenancy\Domain\Company;
use Symfony\Component\HttpFoundation\Response;

/**
 * The invoice form's two pickers (docs/SPEC.md § 8 row 55, § 7 2026-09-17 ruling 3). Before these, opening a form
 * handed the browser every active customer and every active product; now it asks for the few the words find.
 *
 * They answer under the INVOICE's permission, not the customer's or the product's, so the rule the options endpoint
 * states still holds: someone who writes invoices need not also read customers or products to fill one in.
 */
final class InvoicePickTest extends ApiTestCase
{
    /** More than a picker shows, so the cap is visible rather than assumed. */
    private const int MANY = 25;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        static::getContainer()->get(SyncCustomerTaxRegimes::class)->handle();
        $this->company = $this->createCompany('Quincaillerie');
        static::getContainer()->get(ProvisionCompany::class)->handle($this->company);
        $piece = static::getContainer()->get(UnitRepository::class)->ofCodeInCompany('C62', $this->company->getId());
        self::assertNotNull($piece);
        $now = new \DateTimeImmutable();
        for ($n = 1; $n <= self::MANY; ++$n) {
            $reference = \sprintf('VIS-%03d', $n);
            $this->em()->persist(Product::create($this->company, $reference, new ProductDetails('Vis '.$n, null, ProductKind::Goods, '0.450'), $piece, null, [], $now));
        }
        $this->em()->persist(Product::create($this->company, 'BOU-001', new ProductDetails('Boulon inox', null, ProductKind::Goods, '1.200'), $piece, null, [], $now));
        $retired = Product::create($this->company, 'VIS-OLD', new ProductDetails('Vis retirée', null, ProductKind::Goods, '0.100'), $piece, null, [], $now);
        $retired->revise($retired->getReference(), $retired->getDetails(), $piece, null, [], false, $now);
        $this->em()->persist($retired);
        $this->em()->flush();

        $manage = static::getContainer()->get(ManageCustomers::class);
        foreach ([['CLI-001', 'Boulangerie Mercier'], ['CLI-002', 'Menuiserie du Sud']] as [$number, $name]) {
            $manage->create($this->company, $this->customer($number, $name), null);
        }
        $this->em()->flush();
    }

    public function testTheProductPickerAnswersWhatTheWordsFind(): void
    {
        $this->signedIn(['invoice.read']);

        $this->getJson($this->path('products').'?q=boulon');

        self::assertResponseIsSuccessful();
        self::assertSame(
            [['reference' => 'BOU-001', 'name' => 'Boulon inox', 'unitPriceNet' => '1.2000']],
            array_map(
                static fn (array $row): array => ['reference' => $row['reference'], 'name' => $row['name'], 'unitPriceNet' => $row['unitPriceNet']],
                $this->jsonList(),
            ),
        );
    }

    /** A line starts from three facts about the product; a picker that answered only a name would not be enough. */
    public function testAPickCarriesWhatALineStartsFrom(): void
    {
        $this->signedIn(['invoice.read']);

        $this->getJson($this->path('products').'?q=boulon');

        self::assertResponseIsSuccessful();
        $pick = $this->jsonList()[0] ?? null;
        self::assertIsArray($pick);
        self::assertSame(['id', 'reference', 'name', 'unitId', 'unitPriceNet', 'defaultTaxComponentIds'], array_keys($pick));
        self::assertNotSame('', $pick['unitId']);
    }

    public function testAPickerShowsAFewAndNeverTheWholeCatalogue(): void
    {
        $this->signedIn(['invoice.read']);

        $this->getJson($this->path('products'));

        self::assertResponseIsSuccessful();
        $picks = $this->jsonList();
        self::assertCount(20, $picks, 'a picker shows twenty, whatever the catalogue holds');
        self::assertSame('BOU-001', $picks[0]['reference'] ?? null, 'and it starts at the first by reference');
    }

    public function testARetiredProductIsNotOffered(): void
    {
        $this->signedIn(['invoice.read']);

        $this->getJson($this->path('products').'?q=retir');

        self::assertResponseIsSuccessful();
        self::assertSame([], $this->jsonList(), 'a product nobody sells any more is not put on a new line');
    }

    public function testTheCustomerPickerAnswersTheShapeADocumentHeaderStartsFrom(): void
    {
        $this->signedIn(['invoice.read']);

        $this->getJson($this->path('customers').'?q=mercier');

        self::assertResponseIsSuccessful();
        $picks = $this->jsonList();
        self::assertCount(1, $picks);
        self::assertSame(['id', 'number', 'name', 'excludedFamilies', 'defaultDiscountRate', 'defaultTaxComponentIds'], array_keys($picks[0]));
        self::assertSame(['CLI-001', 'Boulangerie Mercier'], [$picks[0]['number'], $picks[0]['name']]);
    }

    /** The whole point of the placement: the invoice's permission answers, the customer's is not asked for. */
    public function testSomebodyWhoReadsInvoicesButNotCustomersStillPicksOne(): void
    {
        $this->signedIn(['invoice.read']);

        $this->getJson($this->path('customers').'?q=menuiserie');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $this->jsonList());
    }

    /**
     * What a form opening a document needs: the rows for what it already names, without the catalogue. A retired
     * product must be answered here — a line written last year still names what was sold — which is the one way this
     * differs from the search beside it.
     */
    public function testNamedRecordsAreResolvedByIdEvenOnceTheyAreRetired(): void
    {
        $this->signedIn(['invoice.read']);
        $this->getJson($this->path('products').'?q=boulon');
        $bolt = $this->jsonList()[0]['id'] ?? null;
        self::assertIsString($bolt);
        $retired = $this->em()->getConnection()->fetchOne('SELECT id FROM product WHERE reference = ?', ['VIS-OLD']);
        self::assertIsString($retired);

        $this->getJson($this->path('products').'?ids[]='.$bolt.'&ids[]='.$retired);

        self::assertResponseIsSuccessful();
        self::assertSame(['BOU-001', 'VIS-OLD'], self::sorted(array_column($this->jsonList(), 'reference')));
    }

    /** Asking about something that is gone reads as "not found", never as a bad request. */
    public function testAnIdThatNamesNothingAnswersNothing(): void
    {
        $this->signedIn(['invoice.read']);

        $this->getJson($this->path('customers').'?ids[]=00000000-0000-7000-8000-000000000000&ids[]=not-a-uuid');

        self::assertResponseIsSuccessful();
        self::assertSame([], $this->jsonList());
    }

    public function testSomebodyWithoutTheInvoicePermissionIsAnsweredAsAStranger(): void
    {
        $this->signedIn(['customer.read', 'product.read']);

        $this->getJson($this->path('products'));

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    /**
     * @param list<mixed> $values
     *
     * @return list<mixed>
     */
    private static function sorted(array $values): array
    {
        sort($values);

        return $values;
    }

    private function path(string $subject): string
    {
        return '/api/companies/'.$this->company->getId()->toRfc4122().'/invoice-options/'.$subject;
    }

    private function customer(string $number, string $name): CustomerInput
    {
        return new CustomerInput(
            $number,
            new CustomerProfile(CustomerKind::Individual, $name, null, [], null, null, null, new PostalAddress(countryCode: $this->company->getCountryCode())),
            null,
            'standard',
            [],
            true,
            [],
        );
    }

    /** @param list<string> $permissions */
    private function signedIn(array $permissions): void
    {
        $this->createUser('shop@twes.local', 'password-1234', $this->company, $permissions, 'member');
        $this->login('shop@twes.local', 'password-1234');
        self::assertResponseIsSuccessful();
    }
}
