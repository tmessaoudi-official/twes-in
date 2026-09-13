<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Fiscal\Application\Company\ProvisionCompany;
use App\Tenancy\Domain\Company;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

/** A company's taxes: listed by anyone who may read them, added and revised by whoever may write them. */
final class TaxComponentsTest extends ApiTestCase
{
    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = $this->createCompany('Acme');
        static::getContainer()->get(ProvisionCompany::class)->handle($this->company);
    }

    public function testAReaderListsThePresetsTaxesInOrder(): void
    {
        $this->signedIn(['fiscal.read']);

        $this->getJson($this->path());

        self::assertResponseIsSuccessful();
        $rows = $this->jsonList();
        self::assertSame(['TVA19', 'TVA13', 'TVA7', 'FODEC', 'TIMBRE', 'RS1'], array_column($rows, 'code'));
        $fodec = $rows[3];
        self::assertSame('levy', $fodec['family']);
        self::assertSame('percentage_line', $fodec['kind']);
        self::assertSame('1.000', $fodec['rate']);
        self::assertTrue($fodec['entersVatBase']);
    }

    public function testAWriterAddsATax(): void
    {
        $this->signedIn(['fiscal.read', 'fiscal.write']);

        $this->postJson($this->path(), ['code' => 'TVA12', 'name' => 'TVA 12 %', 'family' => 'vat', 'rate' => '12', 'sortOrder' => 35]);

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $body = $this->json();
        self::assertSame('TVA12', $body['code']);
        self::assertSame('12.000', $body['rate']);
        self::assertTrue($body['isActive']);
        self::assertIsString($body['id']);
    }

    public function testAWriterRevisesATaxButNotItsCodeOrFamily(): void
    {
        $this->signedIn(['fiscal.read', 'fiscal.write']);
        $stamp = $this->idOf('TIMBRE');

        $this->sendJson('PUT', $this->path().'/'.$stamp, ['code' => 'OTHER', 'family' => 'vat', 'name' => 'Droit de timbre', 'amount' => '1.500', 'isDefault' => true, 'isActive' => true, 'sortOrder' => 50]);

        self::assertResponseIsSuccessful();
        $body = $this->json();
        self::assertSame('TIMBRE', $body['code']);
        self::assertSame('stamp', $body['family']);
        self::assertSame('1.500', $body['amount']);
        self::assertSame('Droit de timbre', $body['name']);
    }

    public function testATaxIsDeactivatedRatherThanDeleted(): void
    {
        $this->signedIn(['fiscal.read', 'fiscal.write']);

        $this->sendJson('PUT', $this->path().'/'.$this->idOf('TVA7'), ['name' => 'TVA 7 %', 'rate' => '7', 'isActive' => false, 'sortOrder' => 30]);

        self::assertResponseIsSuccessful();
        self::assertFalse($this->json()['isActive']);
    }

    public function testADuplicateCodeIsAConflict(): void
    {
        $this->signedIn(['fiscal.read', 'fiscal.write']);

        $this->postJson($this->path(), ['code' => 'TVA19', 'name' => 'Again', 'family' => 'vat', 'rate' => '19']);

        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
    }

    public function testAFiscalRuleTheEntityRefusesIsUnprocessable(): void
    {
        $this->signedIn(['fiscal.read', 'fiscal.write']);

        $this->postJson($this->path(), ['code' => 'STAMP2', 'name' => 'Stamp', 'family' => 'stamp', 'rate' => '1', 'amount' => '1']);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function testAMalformedShapeIsUnprocessable(): void
    {
        $this->signedIn(['fiscal.read', 'fiscal.write']);

        $this->postJson($this->path(), ['code' => 'lower case', 'name' => '', 'family' => 'luxury']);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function testAReaderMayNotWrite(): void
    {
        $this->signedIn(['fiscal.read']);

        $this->postJson($this->path(), ['code' => 'TVA12', 'name' => 'TVA 12 %', 'family' => 'vat', 'rate' => '12']);

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testATaxOfAnotherCompanyIsNotFound(): void
    {
        $globex = $this->createCompany('Globex');
        static::getContainer()->get(ProvisionCompany::class)->handle($globex);
        $this->signedIn(['fiscal.read', 'fiscal.write']);
        $theirs = $this->em()->getRepository(\App\Fiscal\Domain\TaxComponent::class)->findOneBy(['company' => $globex, 'code' => 'TVA19']);
        self::assertNotNull($theirs);

        $this->sendJson('PUT', $this->path().'/'.$theirs->getId()->toRfc4122(), ['name' => 'Hijacked', 'rate' => '0', 'isActive' => true, 'sortOrder' => 0]);

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testACompanyTheCallerHasNothingToDoWithLooksAbsent(): void
    {
        $globex = $this->createCompany('Globex');
        $this->signedIn(['fiscal.read']);

        $this->getJson('/api/companies/'.$globex->getId()->toRfc4122().'/tax-components');

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testAnAnonymousCallerIsRefused(): void
    {
        $this->getJson($this->path());

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testAnUnknownComponentIsNotFound(): void
    {
        $this->signedIn(['fiscal.read', 'fiscal.write']);

        $this->sendJson('PUT', $this->path().'/'.Uuid::v7()->toRfc4122(), ['name' => 'Ghost', 'rate' => '1', 'isActive' => true, 'sortOrder' => 0]);

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testRevisionsAreAudited(): void
    {
        $this->signedIn(['fiscal.read', 'fiscal.write']);

        $this->sendJson('PUT', $this->path().'/'.$this->idOf('TVA13'), ['name' => 'TVA 13 %', 'rate' => '13.5', 'isActive' => true, 'sortOrder' => 20]);

        self::assertResponseIsSuccessful();
        $count = $this->em()->getConnection()->fetchOne("SELECT COUNT(*) FROM audit_log WHERE action = 'tax_component.revised'");
        self::assertEquals(1, $count);
    }

    /** @param list<string> $permissions */
    private function signedIn(array $permissions): void
    {
        $this->createUser('accountant@twes.local', 'password-1234', $this->company, $permissions, 'accountant');
        $this->login('accountant@twes.local', 'password-1234');
        self::assertResponseIsSuccessful();
    }

    private function path(): string
    {
        return '/api/companies/'.$this->company->getId()->toRfc4122().'/tax-components';
    }

    private function idOf(string $code): string
    {
        $this->getJson($this->path());
        foreach ($this->jsonList() as $row) {
            if ($row['code'] === $code) {
                return $this->stringAt($row, 'id');
            }
        }
        self::fail("no $code");
    }
}
