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
 * The delivery note form's two pickers (docs/SPEC.md § 8 row 55, § 7 2026-09-17 ruling 3). What they share with the
 * invoice form's — the words, the cap, the by-id resolution — is pinned by `InvoicePickTest`; what is pinned here is
 * what is DIFFERENT, and the difference is the reason there are four endpoints rather than two:
 *
 * - the permission answering is the delivery note's, not the invoice's;
 * - the customer row is NARROWER, because a delivery note charges nothing and so has no use for a discount or for
 *   document taxes. A picker answers what the form it serves needs, not everything anybody might.
 */
final class DeliveryNotePickTest extends ApiTestCase
{
    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        static::getContainer()->get(SyncCustomerTaxRegimes::class)->handle();
        $this->company = $this->createCompany('Quincaillerie');
        static::getContainer()->get(ProvisionCompany::class)->handle($this->company);
        $piece = static::getContainer()->get(UnitRepository::class)->ofCodeInCompany('C62', $this->company->getId());
        self::assertNotNull($piece);
        $this->em()->persist(Product::create($this->company, 'BOU-001', new ProductDetails('Boulon inox', null, ProductKind::Goods, '1.200'), $piece, null, [], new \DateTimeImmutable()));
        $this->em()->flush();
        $manage = static::getContainer()->get(ManageCustomers::class);
        $manage->create($this->company, $this->customer('CLI-001', 'Boulangerie Mercier'), null);
        $manage->create($this->company, $this->customer('CLI-OLD', 'Ancienne Épicerie', active: false), null);
        $this->em()->flush();
    }

    /** Narrower on purpose: a delivery note charges nothing, so a discount and document taxes are not its business. */
    public function testTheCustomerPickerAnswersOnlyWhatADeliveryNoteNeeds(): void
    {
        $this->signedIn(['delivery_note.read']);

        $this->getJson($this->path('customers').'?q=mercier');

        self::assertResponseIsSuccessful();
        $picks = $this->jsonList();
        self::assertCount(1, $picks);
        self::assertSame(['id', 'number', 'name', 'excludedFamilies'], array_keys($picks[0]));
        self::assertSame(['CLI-001', 'Boulangerie Mercier'], [$picks[0]['number'], $picks[0]['name']]);
    }

    /** A line starts from the same three facts here as on an invoice: what it is sold in, its price, its taxes. */
    public function testTheProductPickerCarriesWhatALineStartsFrom(): void
    {
        $this->signedIn(['delivery_note.read']);

        $this->getJson($this->path('products').'?q=boulon');

        self::assertResponseIsSuccessful();
        $pick = $this->jsonList()[0] ?? null;
        self::assertIsArray($pick);
        self::assertSame(['id', 'reference', 'name', 'unitId', 'unitPriceNet', 'defaultTaxComponentIds'], array_keys($pick));
        self::assertSame(['BOU-001', '1.2000'], [$pick['reference'], $pick['unitPriceNet']]);
    }

    /** The placement's whole point: writing delivery notes is enough, reading customers is not asked for. */
    public function testSomebodyWhoReadsDeliveryNotesButNotCustomersStillPicksOne(): void
    {
        $this->signedIn(['delivery_note.read']);

        $this->getJson($this->path('customers'));

        self::assertResponseIsSuccessful();
        self::assertCount(1, $this->jsonList());
    }

    /** And the mirror: the invoice's permission does not open this form's pickers. */
    public function testTheInvoicePermissionDoesNotAnswerHere(): void
    {
        $this->signedIn(['invoice.read', 'customer.read', 'product.read']);

        $this->getJson($this->path('customers'));

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    /**
     * A deactivated customer is not offered for a NEW note, and is still answered by id — a note written last year
     * names who it was for, and its form must be able to say so. Pinned here rather than on the invoice picker
     * because this is where the options endpoint used to pin it.
     */
    public function testADeactivatedCustomerIsNotOfferedButIsStillResolvedById(): void
    {
        $this->signedIn(['delivery_note.read']);

        $this->getJson($this->path('customers'));
        self::assertResponseIsSuccessful();
        self::assertSame(['CLI-001'], array_column($this->jsonList(), 'number'));

        $gone = $this->em()->getConnection()->fetchOne('SELECT id FROM customer WHERE number = ?', ['CLI-OLD']);
        self::assertIsString($gone);
        $this->getJson($this->path('customers').'?ids[]='.$gone);

        self::assertResponseIsSuccessful();
        self::assertSame(['CLI-OLD'], array_column($this->jsonList(), 'number'));
    }

    private function path(string $subject): string
    {
        return '/api/companies/'.$this->company->getId()->toRfc4122().'/delivery-note-options/'.$subject;
    }

    private function customer(string $number, string $name, bool $active = true): CustomerInput
    {
        return new CustomerInput(
            $number,
            new CustomerProfile(CustomerKind::Individual, $name, null, [], null, null, null, new PostalAddress(countryCode: $this->company->getCountryCode())),
            null,
            'standard',
            [],
            $active,
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
