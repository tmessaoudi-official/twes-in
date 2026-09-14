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

final class EstablishmentsTest extends ApiTestCase
{
    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = $this->createCompany('Acme');
        static::getContainer()->get(ProvisionCompany::class)->handle($this->company);
    }

    public function testAReaderSeesTheDefaultEstablishmentAndTheShapeOfACode(): void
    {
        $this->signedIn(['company.read']);

        $this->getJson($this->path());

        self::assertResponseIsSuccessful();
        $rows = $this->jsonList();
        self::assertCount(1, $rows);
        self::assertSame('000', $rows[0]['code']);
        self::assertSame('Acme', $rows[0]['name']);
        self::assertTrue($rows[0]['isDefault']);
        self::assertSame('^[0-9]{3}$', $rows[0]['codePattern']);
        self::assertFalse($rows[0]['codeLocked']);
    }

    public function testAnAdministratorAddsAnEstablishmentThatNumbersItsOwnDocuments(): void
    {
        $this->signedIn(['company.read', 'company.settings']);

        $this->postJson($this->path(), ['code' => '001', 'name' => 'Agence de Sfax', 'city' => 'Sfax']);

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $body = $this->json();
        self::assertSame('001', $body['code']);
        self::assertSame('Sfax', $body['city']);
        self::assertFalse($body['isDefault']);

        $this->getJson('/api/companies/'.$this->company->getId()->toRfc4122().'/numbering-series');
        $codes = array_map(fn (array $row): string => $this->stringAt($row, 'establishmentCode'), $this->jsonList());
        self::assertSame(['000', '000', '000', '001', '001', '001'], $codes);
        $count = $this->em()->getConnection()->fetchOne("SELECT COUNT(*) FROM audit_log WHERE action = 'establishment.created'");
        self::assertEquals(1, $count);
    }

    public function testACodeWithoutThePresetsShapeIsUnprocessable(): void
    {
        $this->signedIn(['company.read', 'company.settings']);

        $this->postJson($this->path(), ['code' => '12', 'name' => 'Agence']);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function testACodeAlreadyUsedIsAConflict(): void
    {
        $this->signedIn(['company.read', 'company.settings']);

        $this->postJson($this->path(), ['code' => '000', 'name' => 'Again']);

        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
    }

    public function testAnotherEstablishmentBecomesTheDefault(): void
    {
        $this->signedIn(['company.read', 'company.settings']);
        $this->postJson($this->path(), ['code' => '001', 'name' => 'Agence de Sfax']);
        $sfax = $this->stringAt($this->json(), 'id');

        $this->sendJson('PUT', $this->path().'/'.$sfax, ['code' => '001', 'name' => 'Agence de Sfax', 'isDefault' => true]);

        self::assertResponseIsSuccessful();
        $this->getJson($this->path());
        $defaults = array_map(fn (array $row): string => $this->stringAt($row, 'code'), array_filter($this->jsonList(), static fn (array $row): bool => true === $row['isDefault']));
        self::assertSame(['001'], array_values($defaults));
    }

    public function testTheDefaultStaysTheDefaultUntilAnotherTakesItsPlace(): void
    {
        $this->signedIn(['company.read', 'company.settings']);

        $this->sendJson('PUT', $this->path().'/'.$this->idOf('000'), ['code' => '000', 'name' => 'Siège', 'isDefault' => false]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function testAnAdministratorCorrectsTheDefaultEstablishment(): void
    {
        $this->signedIn(['company.read', 'company.settings']);

        $this->sendJson('PUT', $this->path().'/'.$this->idOf('000'), ['code' => '002', 'name' => 'Siège social', 'postalCode' => '1000', 'isDefault' => true]);

        self::assertResponseIsSuccessful();
        $body = $this->json();
        self::assertSame('002', $body['code']);
        self::assertSame('Siège social', $body['name']);
        self::assertSame('1000', $body['postalCode']);
    }

    public function testACodeNumberedDocumentsCarryCannotChangeButTheRestCan(): void
    {
        $this->signedIn(['company.read', 'company.settings']);
        $this->em()->getConnection()->executeStatement(
            "UPDATE numbering_series SET last_reset_year = 2026, last_reset_month = 9, next_number = 2 WHERE company_id = ? AND document_type = 'delivery_note'",
            [$this->company->getId()->toRfc4122()],
        );
        $id = $this->idOf('000');

        $this->getJson($this->path());
        self::assertTrue($this->jsonList()[0]['codeLocked']);

        $this->sendJson('PUT', $this->path().'/'.$id, ['code' => '002', 'name' => 'Acme', 'isDefault' => true]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertStringContainsString('code', $this->stringAt($this->json(), 'detail'));

        $this->sendJson('PUT', $this->path().'/'.$id, ['code' => '000', 'name' => 'Siège social', 'isDefault' => true]);
        self::assertResponseIsSuccessful();
        self::assertSame('Siège social', $this->json()['name']);
        self::assertTrue($this->json()['codeLocked']);
    }

    public function testAReaderMayNotAddAnEstablishment(): void
    {
        $this->signedIn(['company.read']);

        $this->postJson($this->path(), ['code' => '001', 'name' => 'Agence']);

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testAnEstablishmentOfAnotherCompanyIsNotFound(): void
    {
        $globex = $this->createCompany('Globex');
        static::getContainer()->get(ProvisionCompany::class)->handle($globex);
        $theirs = $this->em()->getRepository(\App\Tenancy\Domain\Establishment::class)->findOneBy(['company' => $globex]);
        self::assertNotNull($theirs);
        $this->signedIn(['company.read', 'company.settings']);

        $this->sendJson('PUT', $this->path().'/'.$theirs->getId()->toRfc4122(), ['code' => '009', 'name' => 'Hijacked', 'isDefault' => true]);

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    /** @param list<string> $permissions */
    private function signedIn(array $permissions): void
    {
        $this->createUser('admin@twes.local', 'password-1234', $this->company, $permissions, 'admin');
        $this->login('admin@twes.local', 'password-1234');
        self::assertResponseIsSuccessful();
    }

    private function path(): string
    {
        return '/api/companies/'.$this->company->getId()->toRfc4122().'/establishments';
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
