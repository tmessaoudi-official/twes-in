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
use App\Settings\Domain\Model\Setting;
use App\Settings\Domain\ValueObject\SettingAddress;
use App\Tenancy\Domain\Model\Company;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;

/**
 * What the words match that a product selector does not offer, so an empty selector says why rather than « aucun
 * produit »: the products switched off, everywhere; the services, where only goods are offered (an order, the stock);
 * the goods whose stock is not kept, in the stock's. Each answer is read under its selector's own permission, as the
 * selector is, so a person who may not read the catalogue still learns why.
 */
final class UnofferedProductsTest extends ApiTestCase
{
    /** Each selector, the permission it is read with, and what « café » matches that it does not offer. */
    private const array SELECTORS = [
        'invoice' => ['invoice-options/products', 'invoice.read', ['deactivated' => 2, 'services' => 0, 'notStocked' => 0]],
        'quote' => ['quote-options/products', 'quote.read', ['deactivated' => 2, 'services' => 0, 'notStocked' => 0]],
        'delivery note' => ['delivery-note-options/products', 'delivery_note.read', ['deactivated' => 2, 'services' => 0, 'notStocked' => 0]],
        // An order offers goods: the service on sale and the one switched off are both services to it.
        'purchase order' => ['purchase-order-options/products', 'purchase.read', ['deactivated' => 1, 'services' => 2, 'notStocked' => 0]],
        'stock' => ['stock-options/products', 'stock.read', ['deactivated' => 1, 'services' => 2, 'notStocked' => 1]],
    ];

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = $this->createCompany('Acme');
        static::getContainer()->get(ProvisionCompany::class)->handle($this->company);
        $piece = static::getContainer()->get(UnitRepository::class)->ofCodeInCompany('C62', $this->company->getId());
        self::assertNotNull($piece);
        $now = new \DateTimeImmutable();
        $product = fn (string $reference, string $name, ProductKind $kind, bool $active): Product => $this->persisted(
            Product::create($this->company, $reference, new ProductDetails($name, null, $kind, '10'), $piece, null, [], $now),
            $active,
            $now,
        );
        $product('CAF-1', 'Café moulu', ProductKind::Goods, true);
        $product('CAF-2', 'Café en grain', ProductKind::Goods, false);
        $product('CAF-3', 'Café dégustation', ProductKind::Service, true);
        $product('CAF-4', 'Café livraison', ProductKind::Service, false);
        $filter = $product('CAF-5', 'Café filtre', ProductKind::Goods, true);
        $product('THE-1', 'Thé vert', ProductKind::Goods, false);
        $this->em()->persist(new Setting(SettingAddress::company($this->company), 'article.stock_tracking', true, $now));
        $this->em()->persist(new Setting(SettingAddress::product($this->company, $filter->getId()), 'article.stock_tracking', false, $now));
        $this->em()->flush();
    }

    /**
     * @param array{deactivated: int, services: int, notStocked: int} $expected
     */
    #[DataProvider('selectors')]
    public function testASelectorSaysWhatTheWordsMatchThatItDoesNotOffer(string $path, string $permission, array $expected): void
    {
        $this->signedIn([$permission]);

        $this->getJson($this->companyPath()."/$path/unoffered?q=caf%C3%A9");
        self::assertResponseIsSuccessful();
        self::assertSame($expected, $this->json());

        // Words matching nothing hidden, and no words at all, say nothing is hidden.
        foreach (['?q=moulu', '?q=', ''] as $query) {
            $this->getJson($this->companyPath()."/$path/unoffered$query");
            self::assertResponseIsSuccessful();
            self::assertSame(['deactivated' => 0, 'services' => 0, 'notStocked' => 0], $this->json(), $query);
        }
        // A reference spelled whole is matched as the selector matches it.
        $this->getJson($this->companyPath()."/$path/unoffered?q=THE-1");
        self::assertSame(1, $this->json()['deactivated']);
    }

    #[DataProvider('readers')]
    public function testOnlyTheSelectorsOwnReadersAreAnsweredAndOnlyForTheirCompany(string $path, string $permission): void
    {
        // Without the selector's permission it answers as the selector does: as a stranger, naming nothing.
        $this->signedIn(['customer.read']);
        $this->getJson($this->companyPath()."/$path?q=caf%C3%A9");
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND, "$permission is what reads the selector");
        $this->getJson($this->companyPath()."/$path/unoffered?q=caf%C3%A9");
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND, "$permission is what reads what it leaves out");

        $other = $this->createCompany('Other');
        $this->getJson('/api/companies/'.$other->getId()->toRfc4122()."/$path/unoffered?q=caf%C3%A9");
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND, 'another company answers as a stranger');
    }

    /** @return iterable<string, array{string, string, array{deactivated: int, services: int, notStocked: int}}> */
    public static function selectors(): iterable
    {
        yield from self::SELECTORS;
    }

    /** @return iterable<string, array{string, string}> */
    public static function readers(): iterable
    {
        foreach (self::SELECTORS as $name => [$path, $permission]) {
            yield $name => [$path, $permission];
        }
    }

    private function persisted(Product $product, bool $active, \DateTimeImmutable $now): Product
    {
        if (!$active) {
            $details = $product->getDetails();
            $product->revise($product->getReference(), $details, $product->getUnit(), null, [], false, $now);
        }
        $this->em()->persist($product);

        return $product;
    }

    /** @param list<string> $permissions */
    private function signedIn(array $permissions): void
    {
        $this->createUser('clerk@twes.local', 'password-1234', $this->company, $permissions, 'member');
        $this->login('clerk@twes.local', 'password-1234');
        self::assertResponseIsSuccessful();
    }

    private function companyPath(): string
    {
        return '/api/companies/'.$this->company->getId()->toRfc4122();
    }
}
