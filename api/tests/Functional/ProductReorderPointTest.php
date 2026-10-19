<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Fiscal\Application\Company\ProvisionCompany;
use App\Fiscal\Domain\Repository\UnitRepository;
use App\Tenancy\Domain\Model\Company;
use App\Tenancy\Domain\Repository\EstablishmentRepository;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

/**
 * A reorder point per product per establishment (docs/SPEC.md § 7, 2026-09-24 11:40): the quantity at or under which
 * the product is to be reordered there. Empty means no alert; the alert itself is row 115's.
 */
final class ProductReorderPointTest extends ApiTestCase
{
    private Company $company;
    private string $establishmentId;
    private string $establishmentCode;
    private string $productId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = $this->createCompany('Quincaillerie');
        static::getContainer()->get(ProvisionCompany::class)->handle($this->company);
        $establishment = static::getContainer()->get(EstablishmentRepository::class)->ofCompany($this->company->getId())[0];
        $this->establishmentId = $establishment->getId()->toRfc4122();
        $this->establishmentCode = $establishment->getCode();
    }

    public function testAProductHasOneReorderPointPerEstablishmentSetMovedAndCleared(): void
    {
        $this->signedIn(['product.read', 'product.write']);
        $this->product();

        $this->getJson($this->points());
        self::assertResponseIsSuccessful();
        self::assertSame([[$this->establishmentId, null]], $this->rows(), 'every establishment, with none set: no alert');

        $this->sendJson('PUT', $this->points($this->establishmentId), ['quantity' => ' 12 ', 'version' => $this->storedVersion('product', $this->productId)]);
        self::assertResponseIsSuccessful();
        self::assertSame([$this->establishmentId, $this->establishmentCode, '12.000'], [$this->json()['establishmentId'], $this->json()['establishmentCode'], $this->json()['quantity']]);

        $this->sendJson('PUT', $this->points($this->establishmentId), ['quantity' => '0', 'version' => $this->storedVersion('product', $this->productId)]);
        self::assertResponseIsSuccessful();
        $this->getJson($this->points());
        self::assertSame([[$this->establishmentId, '0.000']], $this->rows(), 'one per establishment, changed in place; zero reorders once none is left');

        $audit = $this->em()->getConnection()->fetchFirstColumn("SELECT action FROM audit_log WHERE entity_type = 'product_reorder_point' AND entity_id = ?", [$this->productId]);
        self::assertEqualsCanonicalizing(['product_reorder_point.set', 'product_reorder_point.set'], $audit);

        $this->sendJson('DELETE', $this->points($this->establishmentId));
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
        $this->getJson($this->points());
        self::assertSame([[$this->establishmentId, null]], $this->rows());
        $this->sendJson('DELETE', $this->points($this->establishmentId));
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT, 'clearing what is clear is no error');
    }

    /** Shown on the product's page beside its form, the points move the product's version, as its homes do. */
    public function testAReorderPointNamesTheProductVersionItWasReadAtAndMovesIt(): void
    {
        $this->signedIn(['product.read', 'product.write']);
        $this->product();
        $point = $this->points($this->establishmentId);
        $read = $this->storedVersion('product', $this->productId);

        $this->sendJson('PUT', $point, ['quantity' => '12']);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, 'a point sent without the version it was read at');
        self::assertContains(['propertyPath' => 'version', 'reason' => 'value_required', 'params' => []], $this->refusals());

        $this->sendJson('PUT', $point, ['quantity' => '12', 'version' => $read]);
        self::assertResponseIsSuccessful();
        $moved = $this->storedVersion('product', $this->productId);
        self::assertSame($read + 1, $moved, 'a point set moves the product\'s version');

        $this->sendJson('PUT', $point, ['quantity' => '15', 'version' => $read]);
        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT, 'a point read before the change is refused');
        self::assertSame('/problems/record-changed', $this->stringAt($this->json(), 'type'));
        $this->getJson($this->points());
        self::assertSame([[$this->establishmentId, '12.000']], $this->rows(), 'and nothing of it is written');
        self::assertSame($moved, $this->storedVersion('product', $this->productId));

        $this->sendJson('PUT', $point, ['quantity' => '12', 'version' => $moved]);
        self::assertResponseIsSuccessful();
        self::assertSame($moved, $this->storedVersion('product', $this->productId), 'the same point changes nothing, its version neither');

        $this->sendJson('PUT', $point, ['quantity' => '15', 'version' => $moved]);
        self::assertResponseIsSuccessful();
        self::assertSame($moved + 1, $this->storedVersion('product', $this->productId), 'a point changed moves it');

        $this->sendJson('DELETE', $point);
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
        self::assertSame($moved + 2, $this->storedVersion('product', $this->productId), 'a point cleared moves it');
        $this->sendJson('DELETE', $point);
        self::assertSame($moved + 2, $this->storedVersion('product', $this->productId), 'clearing what is clear moves nothing');
    }

    /**
     * A point written answers the product's version it left, so the screen's next write sends it without waiting for
     * the product to be read again. A point set to nothing clears it on the version it was read at, as the list reads
     * an establishment without one.
     */
    public function testAPointWrittenAnswersTheVersionItLeftAndNoQuantityClearsOnTheVersionReadAt(): void
    {
        $this->signedIn(['product.read', 'product.write']);
        $this->product();
        $point = $this->points($this->establishmentId);
        $read = $this->storedVersion('product', $this->productId);

        $this->sendJson('PUT', $point, ['quantity' => '12', 'version' => $read]);
        self::assertResponseIsSuccessful();
        $moved = $this->storedVersion('product', $this->productId);
        self::assertSame($read + 1, $moved);
        self::assertSame($moved, $this->intAt($this->json(), 'productVersion'), 'the answer names the version the write left');

        $this->sendJson('PUT', $point, ['quantity' => null, 'version' => $read]);
        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT, 'a clearing read before the change is refused');
        $this->getJson($this->points());
        self::assertSame([[$this->establishmentId, '12.000']], $this->rows(), 'and nothing is cleared');

        $this->sendJson('PUT', $point, ['quantity' => null, 'version' => $moved]);
        self::assertResponseIsSuccessful();
        self::assertNull($this->json()['quantity'] ?? null, 'the answer reads the establishment without a point');
        self::assertSame($moved + 1, $this->intAt($this->json(), 'productVersion'));
        self::assertSame($moved + 1, $this->storedVersion('product', $this->productId));
        $this->getJson($this->points());
        self::assertSame([[$this->establishmentId, null]], $this->rows(), 'the point is cleared');

        $this->sendJson('PUT', $point, ['quantity' => null, 'version' => $moved + 1]);
        self::assertResponseIsSuccessful();
        self::assertSame($moved + 1, $this->intAt($this->json(), 'productVersion'), 'clearing what is clear moves nothing');

        $this->sendJson('PUT', $point, ['quantity' => ' ', 'version' => $moved + 1]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, 'a blank quantity is still refused: nothing is sent as null');
    }

    public function testWhatIsRefusedIsRefusedWithItsField(): void
    {
        $this->signedIn(['product.read', 'product.write']);
        $this->product();

        foreach (['-1' => 'a negative quantity', '1.5' => 'finer than the piece counts', 'douze' => 'not a number', '' => 'blank'] as $quantity => $case) {
            $this->sendJson('PUT', $this->points($this->establishmentId), ['quantity' => (string) $quantity, 'version' => $this->storedVersion('product', $this->productId)]);
            self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, $case);
            self::assertStringContainsString('quantity', (string) $this->client->getResponse()->getContent(), $case);
        }

        $other = $this->createCompany('Ailleurs');
        static::getContainer()->get(ProvisionCompany::class)->handle($other);
        $foreign = static::getContainer()->get(EstablishmentRepository::class)->ofCompany($other->getId())[0]->getId()->toRfc4122();
        $this->sendJson('PUT', $this->points($foreign), ['quantity' => '3', 'version' => $this->storedVersion('product', $this->productId)]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, 'another company’s establishment');
        self::assertStringContainsString('establishmentId', (string) $this->client->getResponse()->getContent());

        $this->sendJson('PUT', '/api/companies/'.$this->company->getId()->toRfc4122().'/products/'.Uuid::v7()->toRfc4122().'/reorder-points/'.$this->establishmentId, ['quantity' => '3', 'version' => 1]);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testAReorderPointIsReadWithProductReadAndSetWithProductWrite(): void
    {
        $this->signedIn(['product.read', 'product.write'], 'writer@twes.local');
        $this->product();
        $this->sendJson('POST', '/api/auth/logout');

        $this->signedIn(['product.read'], 'reader@twes.local', 'reader');
        $this->getJson($this->points());
        self::assertResponseIsSuccessful();
        // As for a home: the company answers 404 to a member who may read a product and not revise one.
        $this->sendJson('PUT', $this->points($this->establishmentId), ['quantity' => '3', 'version' => $this->storedVersion('product', $this->productId)]);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND, 'reading a product is not revising one');
        $this->sendJson('DELETE', $this->points($this->establishmentId));
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    /** @return list<array{mixed, mixed}> each establishment and its point, read row by row: array_column drops a null */
    private function rows(): array
    {
        return array_map(static fn (array $row): array => [$row['establishmentId'] ?? null, $row['quantity'] ?? null], $this->jsonList());
    }

    private function points(?string $establishmentId = null): string
    {
        return '/api/companies/'.$this->company->getId()->toRfc4122().'/products/'.$this->productId.'/reorder-points'.(null === $establishmentId ? '' : '/'.$establishmentId);
    }

    private function product(): void
    {
        $piece = static::getContainer()->get(UnitRepository::class)->ofCodeInCompany('C62', $this->company->getId());
        self::assertNotNull($piece);
        $this->postJson('/api/companies/'.$this->company->getId()->toRfc4122().'/products', [
            'reference' => 'VIS-6X40',
            'name' => 'Vis 6x40',
            'description' => null,
            'kind' => 'goods',
            'unitId' => $piece->getId()->toRfc4122(),
            'unitPriceNet' => '0.45',
            'costPrice' => null,
            'categoryId' => null,
            'barcodes' => [],
            'defaultTaxComponentIds' => [],
            'customFields' => [],
            'isActive' => true,
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $this->productId = $this->stringAt($this->json(), 'id');
    }

    /** @param list<string> $permissions */
    private function signedIn(array $permissions, string $email = 'point@twes.local', string $role = 'member'): void
    {
        $company = $this->em()->find(Company::class, $this->company->getId());
        self::assertInstanceOf(Company::class, $company);
        $this->createUser($email, 'password-1234', $company, $permissions, $role);
        $this->login($email, 'password-1234');
        self::assertResponseIsSuccessful();
    }
}
