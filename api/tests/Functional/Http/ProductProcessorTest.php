<?php

/*
 * This file is part of twes-in.
 *
 * (c) Takieddine MESSAOUDI <takieddine.messaoudi.official@gmail.com>
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Twes\Tests\Functional\Http;

use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Post;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Twes\Application\Product\CreateProductHandler;
use Twes\Domain\Money\Currency;
use Twes\Domain\Money\Money;
use Twes\Domain\Pricing\PricedBy;
use Twes\Domain\Pricing\ProductPricing;
use Twes\Domain\Pricing\Rate;
use Twes\Domain\Product\Product;
use Twes\Tests\Support\FixedIdGenerator;
use Twes\Tests\Support\InMemoryProductRepository;
use Twes\Tests\Support\RecordingTransactionalScope;
use Twes\UI\Http\ApiResource\NewProductInput;
use Twes\UI\Http\State\CreateProductProcessor;
use Twes\UI\Http\State\ProductProvider;

/**
 * The product processor and provider driven directly — the status-code mapping, the response shape, and the one
 * decision F4 will not let anyone else make.
 *
 * {@see ProductWriteSurfaceTest} goes through the real kernel, which is right for validation cascading and the
 * published contract and wrong for the successful shape: reaching the processor over HTTP with a VALID payload
 * needs a database and a bound tenant, so a kernel test can only ever assert refusals.
 *
 * **NO KERNEL AND NO DATABASE.** What is proven here is the translation between the wire and the domain — which
 * pricing constructor is chosen, and that authorship survives into the response. The round trip through real
 * `NUMERIC` columns is `DoctrineProductRepositoryTest`'s subject and is deliberately not re-proven with a fake.
 */
#[CoversClass(CreateProductProcessor::class)]
#[CoversClass(ProductProvider::class)]
#[CoversClass(CreateProductHandler::class)]
final class ProductProcessorTest extends TestCase
{
    private const STORED = 'dddddddd-dddd-4ddd-8ddd-dddddddddddd';

    private InMemoryProductRepository $products;
    private FixedIdGenerator $identifiers;
    private RecordingTransactionalScope $transaction;

    protected function setUp(): void
    {
        $this->products = new InMemoryProductRepository();
        $this->identifiers = new FixedIdGenerator();
        $this->transaction = new RecordingTransactionalScope();
    }

    /**
     * **A RATE-AUTHORED PRODUCT KEEPS ITS AUTHORSHIP AND STILL REPORTS BOTH FIGURES.**
     *
     * Both are on the wire because F4's § "Bidirectional editing" requires a form showing three linked fields
     * where none can lie; `authoredBy` is what tells a client which of them it may not write back.
     */
    public function testARateAuthoredProductIsReturnedWithBothFigures(): void
    {
        $resource = $this->processor()->process(
            new NewProductInput(
                name: 'Café moulu',
                currency: 'TND',
                cost: '100.000',
                vatRate: '19',
                sku: 'CAFE-500G',
                profitRate: '30',
            ),
            new Post(),
        );

        self::assertSame($this->identifiers->handedOut[0], $resource->id);
        self::assertSame('Café moulu', $resource->name);
        self::assertSame('CAFE-500G', $resource->sku);
        self::assertSame('TND', $resource->pricing->currency);
        self::assertSame(PricedBy::ProfitRate->value, $resource->pricing->authoredBy);
        self::assertSame(
            Rate::fromPercentage('30')->percentage(),
            $resource->pricing->profitRate,
            'the typed rate is reported as typed',
        );
        self::assertSame(
            Money::of('130.000', Currency::of('TND'))->amount(),
            $resource->pricing->netPrice,
            'the derived price is cost x (1 + rate), in ONE multiplication',
        );
        self::assertSame(Rate::fromPercentage('19')->percentage(), $resource->vatRate);
    }

    /** The mirror case: a typed price keeps ITS authorship, and the rate becomes the derived figure. */
    public function testANetPriceAuthoredProductKeepsItsAuthorship(): void
    {
        $resource = $this->processor()->process(
            new NewProductInput(
                name: 'Café moulu',
                currency: 'TND',
                cost: '100.000',
                vatRate: '19',
                netPrice: '140.000',
            ),
            new Post(),
        );

        self::assertSame(PricedBy::NetPrice->value, $resource->pricing->authoredBy);
        self::assertSame(Money::of('140.000', Currency::of('TND'))->amount(), $resource->pricing->netPrice);
        self::assertSame(
            Rate::fromPercentage('40')->percentage(),
            $resource->pricing->profitRate,
            'the derived rate is (net - cost) / cost',
        );
    }

    /**
     * **A ZERO COST REPORTS A `null` RATE, never `0` and never an error.**
     *
     * F4 is explicit: the rate is UNDEFINED at zero cost — a division by zero — and the field shows empty. `0`
     * would claim the product is sold at cost, which is a different and false statement; an error would make a
     * legitimate product unreadable. This is the case a naive implementation gets wrong in both directions.
     */
    public function testAZeroCostProductHasNoProfitRateRatherThanAZeroOne(): void
    {
        $resource = $this->processor()->process(
            new NewProductInput(
                name: 'Échantillon',
                currency: 'TND',
                cost: '0.000',
                vatRate: '19',
                netPrice: '10.000',
            ),
            new Post(),
        );

        self::assertNull(
            $resource->pricing->profitRate,
            'the rate is undefined at zero cost — empty, never zero, never an error',
        );
        self::assertSame(Money::of('10.000', Currency::of('TND'))->amount(), $resource->pricing->netPrice);
    }

    /**
     * **BOTH PRICE FIELDS IS REFUSED RATHER THAN MERGED, at the processor as well as at the validator.**
     *
     * Over HTTP the validator's callback refuses this first, which is the design. The processor's own branch is
     * what makes the rule hold for a caller that is not the validator — a CLI import, a Messenger consumer —
     * and `CLAUDE.md` § Gotchas records four separate times what happens to a guard that exists only in prose.
     */
    public function testSendingBothPriceFieldsIsRefused(): void
    {
        $this->expectException(UnprocessableEntityHttpException::class);
        $this->expectExceptionMessageMatches('/never both and never neither/');

        $this->processor()->process(
            new NewProductInput(
                name: 'Café moulu',
                currency: 'TND',
                cost: '100.000',
                vatRate: '19',
                profitRate: '30',
                netPrice: '130.000',
            ),
            new Post(),
        );
    }

    public function testSendingNeitherPriceFieldIsRefused(): void
    {
        $this->expectException(UnprocessableEntityHttpException::class);
        $this->expectExceptionMessageMatches('/never both and never neither/');

        $this->processor()->process(
            new NewProductInput(name: 'Café moulu', currency: 'TND', cost: '100.000', vatRate: '19'),
            new Post(),
        );
    }

    /** An unknown currency is the caller's error — they picked it — and becomes a 422 rather than a 500. */
    public function testAnUnknownCurrencyIsTheCallersError(): void
    {
        $this->expectException(UnprocessableEntityHttpException::class);

        $this->processor()->process(
            new NewProductInput(
                name: 'Café moulu',
                currency: 'XYZ',
                cost: '100.000',
                vatRate: '19',
                profitRate: '30',
            ),
            new Post(),
        );
    }

    /** ONE unit of work, entered once and never nested — the id, the write and the read-back together. */
    public function testTheWholeCreationIsOneTransactionAndTheProductIsReadBack(): void
    {
        $resource = $this->processor()->process(
            new NewProductInput(
                name: 'Café moulu',
                currency: 'TND',
                cost: '100.000',
                vatRate: '19',
                profitRate: '30',
            ),
            new Post(),
        );

        self::assertSame(1, $this->transaction->entered);
        self::assertSame(1, $this->transaction->maxDepth, 'and never nested inside another');
        self::assertSame(1, $this->products->saves);
        self::assertSame(
            [$resource->id],
            $this->products->reads,
            'the handler must read the product back inside its own transaction',
        );
    }

    public function testAPayloadOfTheWrongTypeIsOurFault(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/wired to an operation/');

        $this->processor()->process(['name' => 'Café moulu'], new Post());
    }

    public function testAStoredProductIsProvidedInFull(): void
    {
        $this->products->save(
            Product::create(
                self::STORED,
                'Café moulu',
                ProductPricing::fromProfitRate(
                    Money::of('100.000', Currency::of('TND')),
                    Rate::fromPercentage('30'),
                ),
                Rate::fromPercentage('19'),
            )->withSku('CAFE-500G'),
        );

        $resource = $this->provider()->provide(new Get(), ['id' => self::STORED]);

        self::assertSame(self::STORED, $resource->id);
        self::assertSame('CAFE-500G', $resource->sku);
        self::assertSame(PricedBy::ProfitRate->value, $resource->pricing->authoredBy);
        self::assertSame(1, $this->transaction->entered, 'the read runs inside a transaction');
    }

    /**
     * Every way of not finding a product gives the SAME 404, and that sameness is the security property: a
     * catalogue is a company's margin structure, so its contents are what a competitor would enumerate.
     */
    public function testEveryWayOfNotFindingAProductIsTheSame404(): void
    {
        foreach ([
            'a well-formed id nobody owns' => '11111111-1111-4111-8111-111111111111',
            'an id of the wrong shape' => 'not-a-uuid',
            'an uppercase spelling of a real id' => strtoupper(self::STORED),
        ] as $why => $id) {
            try {
                $this->provider()->provide(new Get(), ['id' => $id]);
                self::fail(\sprintf('expected a 404 for %s', $why));
            } catch (NotFoundHttpException $refused) {
                self::assertStringContainsString('No such product.', $refused->getMessage(), $why);
            }
        }
    }

    public function testANonStringIdIsA404RatherThanATypeError(): void
    {
        $this->expectException(NotFoundHttpException::class);

        $this->provider()->provide(new Get(), ['id' => 42]);
    }

    private function processor(): CreateProductProcessor
    {
        return new CreateProductProcessor(
            new CreateProductHandler($this->products, $this->identifiers, $this->transaction),
        );
    }

    private function provider(): ProductProvider
    {
        return new ProductProvider($this->products, $this->transaction);
    }
}
