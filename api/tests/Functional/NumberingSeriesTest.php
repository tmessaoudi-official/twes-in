<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Fiscal\Application\Company\ProvisionCompany;
use App\Tenancy\Domain\Model\Company;
use App\Tenancy\Domain\Model\NumberingSeries;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

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
        self::assertSame(['credit_note', 'delivery_note', 'invoice', 'purchase_order', 'quote'], array_map(fn (array $row): string => $this->stringAt($row, 'documentType'), $rows));
        $invoice = $rows[2];
        self::assertSame('FAC-{YYYY}-{MM}-{SEQ:5}', $invoice['format']);
        self::assertSame(1, $invoice['nextNumber']);
        self::assertSame('yearly', $invoice['resetPeriod']);
        self::assertTrue($invoice['isDefault']);
        self::assertFalse($invoice['numbered']);
        self::assertSame('000', $invoice['establishmentCode']);
        self::assertMatchesRegularExpression('/^FAC-\d{4}-\d{2}-00001$/', $this->stringAt($invoice, 'preview'));
    }

    public function testAnAdministratorRevisesASeries(): void
    {
        $this->signedIn(['company.read', 'company.settings']);

        $this->revise($this->idOf('invoice'), ['format' => 'F{YY}{MM}-{SEQ:4}', 'nextNumber' => 120, 'resetPeriod' => 'monthly']);

        self::assertResponseIsSuccessful();
        $body = $this->json();
        self::assertSame('F{YY}{MM}-{SEQ:4}', $body['format']);
        self::assertSame(120, $body['nextNumber']);
        self::assertSame('monthly', $body['resetPeriod']);
        self::assertMatchesRegularExpression('/^F\d{4}-0120$/', $this->stringAt($body, 'preview'));
        $count = $this->em()->getConnection()->fetchOne("SELECT COUNT(*) FROM audit_log WHERE action = 'numbering_series.revised'");
        self::assertEquals(1, $count);
    }

    public function testAFormatThatCannotNumberADocumentIsUnprocessable(): void
    {
        $this->signedIn(['company.read', 'company.settings']);

        $this->revise($this->idOf('invoice'), ['format' => 'FAC-{YYYY}', 'nextNumber' => 1, 'resetPeriod' => 'yearly']);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame([['propertyPath' => 'format', 'reason' => 'numbering_format', 'params' => ['max' => 12]]], $this->refusals());

        $this->revise($this->idOf('invoice'), ['format' => 'FAC-{YYYY}-{SEQ:13}', 'nextNumber' => 1, 'resetPeriod' => 'yearly']);
        self::assertSame([['propertyPath' => 'format', 'reason' => 'numbering_format', 'params' => ['max' => 12]]], $this->refusals());
    }

    public function testAResetWhosePeriodTheFormatDoesNotPrintIsUnprocessable(): void
    {
        $this->signedIn(['company.read', 'company.settings']);

        $this->revise($this->idOf('invoice'), ['format' => 'F{YY}-{SEQ:4}', 'nextNumber' => 120, 'resetPeriod' => 'monthly']);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame([['propertyPath' => 'format', 'reason' => 'format_prints_month', 'params' => []]], $this->refusals());

        $this->revise($this->idOf('invoice'), ['format' => 'F-{SEQ:4}', 'nextNumber' => 120, 'resetPeriod' => 'yearly']);
        self::assertSame([['propertyPath' => 'format', 'reason' => 'format_prints_year', 'params' => []]], $this->refusals());
    }

    public function testASequenceBelowOneIsUnprocessable(): void
    {
        $this->signedIn(['company.read', 'company.settings']);

        $this->revise($this->idOf('invoice'), ['format' => 'FAC-{SEQ}', 'nextNumber' => 0, 'resetPeriod' => 'yearly']);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame([['propertyPath' => 'nextNumber', 'reason' => 'sequence_from', 'params' => ['next' => 1]]], $this->refusals());
    }

    public function testAResetPeriodThatDoesNotExistIsUnprocessable(): void
    {
        $this->signedIn(['company.read', 'company.settings']);

        $this->revise($this->idOf('invoice'), ['format' => 'FAC-{SEQ}', 'nextNumber' => 1, 'resetPeriod' => 'weekly']);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame([['propertyPath' => 'resetPeriod', 'reason' => 'unknown_choice', 'params' => []]], $this->refusals());
    }

    public function testAReaderMayNotReviseASeries(): void
    {
        $this->signedIn(['company.read']);

        $this->revise($this->idOf('invoice'), ['format' => 'X-{SEQ}', 'nextNumber' => 1, 'resetPeriod' => 'never']);

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testASeriesOfAnotherCompanyIsNotFound(): void
    {
        $globex = $this->createCompany('Globex');
        static::getContainer()->get(ProvisionCompany::class)->handle($globex);
        $theirs = $this->em()->getRepository(NumberingSeries::class)->findOneBy(['company' => $globex]);
        self::assertNotNull($theirs);
        $this->signedIn(['company.read', 'company.settings']);

        $this->sendJson('PUT', $this->path().'/'.$theirs->getId()->toRfc4122(), ['version' => 1, 'format' => 'X-{SEQ}', 'nextNumber' => 1, 'resetPeriod' => 'never']);

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

        $this->revise($id, ['format' => 'BL-{YY}-{SEQ:5}', 'nextNumber' => 1, 'resetPeriod' => 'yearly']);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertStringContainsString('nextNumber', $this->stringAt($this->json(), 'detail'));
        self::assertSame([['propertyPath' => 'nextNumber', 'reason' => 'sequence_from', 'params' => ['next' => 2]]], $this->refusals());

        $this->revise($id, ['format' => 'BL-{YY}-{SEQ:5}', 'nextNumber' => 2, 'resetPeriod' => 'yearly']);
        self::assertResponseIsSuccessful();
        self::assertMatchesRegularExpression('/^BL-\d{2}-00002$/', $this->stringAt($this->json(), 'preview'));
    }

    /**
     * Two series of one document type never print the same number: a format that could print another establishment's
     * numbers is refused when it is written, naming that establishment, not at the issue its number would collide on.
     */
    public function testAFormatThatCouldPrintTheNumbersOfAnotherSeriesOfItsDocumentIsAConflict(): void
    {
        $this->signedIn(['company.read', 'company.settings']);
        $this->postJson('/api/companies/'.$this->company->getId()->toRfc4122().'/establishments', ['code' => '001', 'name' => 'Agence de Sfax']);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $branch = $this->idOf('invoice', '001');

        foreach (['FAC-{YYYY}-{MM}-{SEQ:5}', 'FAC-{YYYY}-{MM}-1{SEQ:4}'] as $format) {
            $this->revise($branch, ['format' => $format, 'nextNumber' => 1, 'resetPeriod' => 'yearly']);
            self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT, $format);
            self::assertStringContainsString('000', $this->stringAt($this->json(), 'detail'));
        }

        // Its own numbers kept apart, by a prefix; another document's format is its own, as its numbers are.
        $this->revise($branch, ['format' => 'SFX-{YYYY}-{MM}-{SEQ:5}', 'nextNumber' => 1, 'resetPeriod' => 'yearly']);
        self::assertResponseIsSuccessful();
        $this->revise($this->idOf('credit_note', '001'), ['format' => 'FAC-{YYYY}-{MM}-{SEQ:5}', 'nextNumber' => 1, 'resetPeriod' => 'yearly']);
        self::assertResponseIsSuccessful();
        // A series is never in conflict with itself.
        $this->revise($this->idOf('invoice'), ['format' => 'FAC-{YYYY}-{MM}-{SEQ:5}', 'nextNumber' => 1, 'resetPeriod' => 'yearly']);
        self::assertResponseIsSuccessful();
    }

    /** A number given after the form was opened moves the series on: writing the form back would give that number twice. */
    public function testASeriesSavedFromAFormOpenedBeforeANumberWasGivenIsRefusedAndTheCounterStays(): void
    {
        $this->signedIn(['company.read', 'company.settings']);
        $id = $this->idOf('invoice');
        $this->getJson($this->path());
        $opened = array_find($this->jsonList(), static fn (array $row): bool => $id === $row['id']) ?? self::fail('no invoice series');

        $series = $this->em()->find(NumberingSeries::class, Uuid::fromString($id)) ?? self::fail('no series');
        $series->allocate(new \DateTimeImmutable('today'), new \DateTimeImmutable());
        $this->em()->flush();

        $this->sendJson('PUT', $this->path().'/'.$id, ['version' => $opened['version'], 'format' => $opened['format'], 'nextNumber' => $opened['nextNumber'], 'resetPeriod' => $opened['resetPeriod']]);

        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        self::assertSame('/problems/record-changed', $this->json()['type']);
        $this->getJson($this->path());
        $saved = array_find($this->jsonList(), static fn (array $row): bool => $id === $row['id']) ?? self::fail('no invoice series');
        self::assertSame(2, $saved['nextNumber']);
        self::assertIsInt($opened['version']);
        self::assertSame($opened['version'] + 1, $saved['version']);
    }

    public function testARevisionThatChangesNothingKeepsItsVersionAndOneWithoutAVersionIsRefused(): void
    {
        $this->signedIn(['company.read', 'company.settings']);
        $id = $this->idOf('invoice');
        $this->getJson($this->path());
        $opened = array_find($this->jsonList(), static fn (array $row): bool => $id === $row['id']) ?? self::fail('no invoice series');
        $body = ['format' => $opened['format'], 'nextNumber' => $opened['nextNumber'], 'resetPeriod' => $opened['resetPeriod']];

        $this->sendJson('PUT', $this->path().'/'.$id, ['version' => $opened['version'], ...$body]);
        self::assertResponseIsSuccessful();
        self::assertSame($opened['version'], $this->json()['version']);

        $this->sendJson('PUT', $this->path().'/'.$id, $body);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame(['version'], array_column($this->arrayAt($this->json(), 'violations'), 'propertyPath'));
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

    private function idOf(string $documentType, string $establishmentCode = '000'): string
    {
        $this->getJson($this->path());
        foreach ($this->jsonList() as $row) {
            if ($row['documentType'] === $documentType && $row['establishmentCode'] === $establishmentCode) {
                return $this->stringAt($row, 'id');
            }
        }
        self::fail("no $documentType series");
    }

    /**
     * Revises a row at the version the list holds now, as a form opened just before would.
     *
     * @param array<string, mixed> $body
     */
    private function revise(string $id, array $body): void
    {
        $this->getJson($this->path());
        $row = array_find($this->jsonList(), static fn (array $row): bool => $id === $row['id']) ?? self::fail("no row $id");
        $this->sendJson('PUT', $this->path().'/'.$id, ['version' => $row['version'], ...$body]);
    }
}
