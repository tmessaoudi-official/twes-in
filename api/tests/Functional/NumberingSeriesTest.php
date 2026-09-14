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

final class NumberingSeriesTest extends ApiTestCase
{
    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = $this->createCompany('Acme');
        static::getContainer()->get(ProvisionCompany::class)->handle($this->company);
    }

    public function testAReaderSeesEachSeriesWithTheNumberItWouldIssueNext(): void
    {
        $this->signedIn(['company.read']);

        $this->getJson($this->path());

        self::assertResponseIsSuccessful();
        $rows = $this->jsonList();
        self::assertSame(['credit_note', 'delivery_note', 'invoice'], array_map(fn (array $row): string => $this->stringAt($row, 'documentType'), $rows));
        $invoice = $rows[2];
        self::assertSame('FAC-{YYYY}-{SEQ:5}', $invoice['format']);
        self::assertSame(1, $invoice['nextNumber']);
        self::assertSame('yearly', $invoice['resetPeriod']);
        self::assertTrue($invoice['isDefault']);
        self::assertFalse($invoice['numbered']);
        self::assertSame('000', $invoice['establishmentCode']);
        self::assertMatchesRegularExpression('/^FAC-\d{4}-00001$/', $this->stringAt($invoice, 'preview'));
    }

    public function testAnAdministratorRevisesASeries(): void
    {
        $this->signedIn(['company.read', 'company.settings']);

        $this->sendJson('PUT', $this->path().'/'.$this->idOf('invoice'), ['format' => 'F{YY}-{SEQ:4}', 'nextNumber' => 120, 'resetPeriod' => 'monthly']);

        self::assertResponseIsSuccessful();
        $body = $this->json();
        self::assertSame('F{YY}-{SEQ:4}', $body['format']);
        self::assertSame(120, $body['nextNumber']);
        self::assertSame('monthly', $body['resetPeriod']);
        self::assertMatchesRegularExpression('/^F\d{2}-0120$/', $this->stringAt($body, 'preview'));
        $count = $this->em()->getConnection()->fetchOne("SELECT COUNT(*) FROM audit_log WHERE action = 'numbering_series.revised'");
        self::assertEquals(1, $count);
    }

    public function testAFormatThatCannotNumberADocumentIsUnprocessable(): void
    {
        $this->signedIn(['company.read', 'company.settings']);

        $this->sendJson('PUT', $this->path().'/'.$this->idOf('invoice'), ['format' => 'FAC-{YYYY}', 'nextNumber' => 1, 'resetPeriod' => 'yearly']);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function testASequenceBelowOneIsUnprocessable(): void
    {
        $this->signedIn(['company.read', 'company.settings']);

        $this->sendJson('PUT', $this->path().'/'.$this->idOf('invoice'), ['format' => 'FAC-{SEQ}', 'nextNumber' => 0, 'resetPeriod' => 'yearly']);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function testAResetPeriodThatDoesNotExistIsUnprocessable(): void
    {
        $this->signedIn(['company.read', 'company.settings']);

        $this->sendJson('PUT', $this->path().'/'.$this->idOf('invoice'), ['format' => 'FAC-{SEQ}', 'nextNumber' => 1, 'resetPeriod' => 'weekly']);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function testAReaderMayNotReviseASeries(): void
    {
        $this->signedIn(['company.read']);

        $this->sendJson('PUT', $this->path().'/'.$this->idOf('invoice'), ['format' => 'X-{SEQ}', 'nextNumber' => 1, 'resetPeriod' => 'never']);

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testASeriesOfAnotherCompanyIsNotFound(): void
    {
        $globex = $this->createCompany('Globex');
        static::getContainer()->get(ProvisionCompany::class)->handle($globex);
        $theirs = $this->em()->getRepository(\App\Tenancy\Domain\NumberingSeries::class)->findOneBy(['company' => $globex]);
        self::assertNotNull($theirs);
        $this->signedIn(['company.read', 'company.settings']);

        $this->sendJson('PUT', $this->path().'/'.$theirs->getId()->toRfc4122(), ['format' => 'X-{SEQ}', 'nextNumber' => 1, 'resetPeriod' => 'never']);

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testOnceADocumentCarriesANumberTheSequenceIsFrozenButTheFormatIsNot(): void
    {
        $this->signedIn(['company.read', 'company.settings']);
        $this->numberedOnce('delivery_note');
        $id = $this->idOf('delivery_note');

        $this->getJson($this->path());
        $row = array_find($this->jsonList(), static fn (array $row): bool => 'delivery_note' === $row['documentType']) ?? self::fail('no delivery note series');
        self::assertTrue($row['numbered']);
        self::assertSame(2, $row['nextNumber']);

        $this->sendJson('PUT', $this->path().'/'.$id, ['format' => 'BL-{YY}-{SEQ:5}', 'nextNumber' => 1, 'resetPeriod' => 'yearly']);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertStringContainsString('nextNumber', $this->stringAt($this->json(), 'detail'));

        $this->sendJson('PUT', $this->path().'/'.$id, ['format' => 'BL-{YY}-{SEQ:5}', 'nextNumber' => 2, 'resetPeriod' => 'yearly']);
        self::assertResponseIsSuccessful();
        self::assertMatchesRegularExpression('/^BL-\d{2}-00002$/', $this->stringAt($this->json(), 'preview'));
    }

    /** What a first numbered document leaves behind: the period it was numbered in, and the sequence moved on. */
    private function numberedOnce(string $documentType): void
    {
        $this->em()->getConnection()->executeStatement(
            'UPDATE numbering_series SET last_reset_year = 2026, last_reset_month = 9, next_number = 2 WHERE company_id = ? AND document_type = ?',
            [$this->company->getId()->toRfc4122(), $documentType],
        );
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
        return '/api/companies/'.$this->company->getId()->toRfc4122().'/numbering-series';
    }

    private function idOf(string $documentType): string
    {
        $this->getJson($this->path());
        foreach ($this->jsonList() as $row) {
            if ($row['documentType'] === $documentType) {
                return $this->stringAt($row, 'id');
            }
        }
        self::fail("no $documentType series");
    }
}
