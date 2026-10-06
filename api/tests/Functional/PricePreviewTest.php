<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Fiscal\Application\Company\ProvisionCompany;
use App\Fiscal\Domain\TaxComponentRepository;
use App\Tenancy\Domain\Company;
use Symfony\Component\HttpFoundation\Response;

/**
 * What a price being typed comes to with its taxes, per unit and per pack, counted by the calculator every document
 * uses: the price calculator asks here rather than guess the compounding and the rounding in the browser.
 */
final class PricePreviewTest extends ApiTestCase
{
    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = $this->createCompany('Acme');
        static::getContainer()->get(ProvisionCompany::class)->handle($this->company);
    }

    public function testAPriceComesWithItsTaxesPerUnitAndPerPackAsAnInvoiceLineWouldCountIt(): void
    {
        $this->signedIn(['product.read']);

        $this->postJson($this->path(), ['unitPriceNet' => '100', 'taxComponentIds' => $this->taxes(['FODEC', 'TVA19']), 'quantities' => ['1', '12']]);

        self::assertResponseIsSuccessful();
        self::assertSame([
            ['quantity' => '1', 'net' => '100.000', 'tax' => '20.190', 'total' => '120.190'],
            ['quantity' => '12', 'net' => '1200.000', 'tax' => '242.280', 'total' => '1442.280'],
        ], $this->json()['prices'], 'FODEC enters the VAT base, and a pack is one line of twelve, rounded once');
    }

    public function testAPriceWithNoTaxIsItsOwnTotalAtTheCurrencyScale(): void
    {
        $this->signedIn(['product.read']);

        $this->postJson($this->path(), ['unitPriceNet' => '10.1234', 'taxComponentIds' => [], 'quantities' => ['1']]);

        self::assertResponseIsSuccessful();
        self::assertSame([['quantity' => '1', 'net' => '10.123', 'tax' => '0.000', 'total' => '10.123']], $this->json()['prices']);
    }

    public function testATaxThatIsNotOneOfTheCompanysLineTaxesIsRefused(): void
    {
        $this->signedIn(['product.read']);
        $other = $this->createCompany('Globex');
        static::getContainer()->get(ProvisionCompany::class)->handle($other);
        $foreign = static::getContainer()->get(TaxComponentRepository::class)->ofCodeInCompany('TVA19', $other->getId());
        self::assertNotNull($foreign);

        $this->postJson($this->path(), ['unitPriceNet' => '100', 'taxComponentIds' => [$foreign->getId()->toRfc4122()], 'quantities' => ['1']]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, 'another company\'s tax');

        $this->postJson($this->path(), ['unitPriceNet' => '100', 'taxComponentIds' => $this->taxes(['TIMBRE']), 'quantities' => ['1']]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, 'a document tax is no product\'s');

        $this->postJson($this->path(), ['unitPriceNet' => '100', 'taxComponentIds' => $this->taxes(['RS1']), 'quantities' => ['1']]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, 'a withholding has a rate and is still no line tax');
    }

    public function testAPriceOrAQuantityThatIsNotOneIsRefused(): void
    {
        $this->signedIn(['product.read']);

        foreach ([
            'a price that is no amount' => ['unitPriceNet' => 'cent', 'quantities' => ['1']],
            'a negative price' => ['unitPriceNet' => '-1', 'quantities' => ['1']],
            'five decimals' => ['unitPriceNet' => '1.12345', 'quantities' => ['1']],
            'no quantity' => ['unitPriceNet' => '1', 'quantities' => []],
            'a quantity of nothing' => ['unitPriceNet' => '1', 'quantities' => ['0']],
            'a quantity that is no number' => ['unitPriceNet' => '1', 'quantities' => ['douze']],
            'more packs than a product has' => ['unitPriceNet' => '1', 'quantities' => array_map('strval', range(1, 21))],
        ] as $case => $body) {
            $this->postJson($this->path(), $body + ['taxComponentIds' => []]);
            self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, $case);
        }
    }

    public function testSomebodyWhoCannotReadProductsIsAnsweredAsAStranger(): void
    {
        $this->signedIn(['company.read']);

        $this->postJson($this->path(), ['unitPriceNet' => '100', 'taxComponentIds' => [], 'quantities' => ['1']]);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);

        $other = $this->createCompany('Globex');
        $this->postJson('/api/companies/'.$other->getId()->toRfc4122().'/price-preview', ['unitPriceNet' => '100', 'taxComponentIds' => [], 'quantities' => ['1']]);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    /**
     * @param list<string> $codes
     *
     * @return list<string>
     */
    private function taxes(array $codes): array
    {
        $taxes = static::getContainer()->get(TaxComponentRepository::class);

        return array_map(function (string $code) use ($taxes): string {
            $tax = $taxes->ofCodeInCompany($code, $this->company->getId());
            self::assertNotNull($tax, $code);

            return $tax->getId()->toRfc4122();
        }, $codes);
    }

    /** @param list<string> $permissions */
    private function signedIn(array $permissions): void
    {
        $this->createUser('clerk@twes.local', 'password-1234', $this->company, $permissions, 'clerk');
        $this->login('clerk@twes.local', 'password-1234');
        self::assertResponseIsSuccessful();
    }

    private function path(): string
    {
        return '/api/companies/'.$this->company->getId()->toRfc4122().'/price-preview';
    }
}
