<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Tenancy\Domain\Company;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mime\Email as MimeEmail;

/**
 * A company that signed up waits for an operator (docs/SPEC.md § 7, 2026-09-15): operators see the ones waiting,
 * approve one (it opens to its members and its owners are told) or reject it (it is suspended). Nobody else may.
 */
final class CompanyApprovalTest extends ApiTestCase
{
    private const string WAITING = '/api/platform/companies?status=pending';

    public function testAnOperatorSeesTheCompaniesWaitingForApprovalWithTheirOwners(): void
    {
        $waiting = $this->pendingCompany('Nouvelle Société', 'nadia@example.test');
        $this->createCompany('Already Open');
        $this->signedInAsOperator();

        $this->getJson(self::WAITING);

        self::assertResponseIsSuccessful();
        $rows = $this->jsonList();
        self::assertCount(1, $rows);
        self::assertSame($waiting->getId()->toRfc4122(), $rows[0]['id']);
        self::assertSame('Nouvelle Société', $rows[0]['name']);
        self::assertSame('TN', $rows[0]['countryCode']);
        self::assertSame('pending', $rows[0]['status']);
        self::assertSame(['nadia@example.test'], $rows[0]['owners']);
        self::assertIsString($rows[0]['createdAt']);
    }

    public function testAnUnknownStatusIsABadRequest(): void
    {
        $this->signedInAsOperator();

        $this->getJson('/api/platform/companies?status=sleeping');

        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
    }

    public function testApprovingOpensTheCompanyToItsOwnerAndTellsThem(): void
    {
        $company = $this->pendingCompany('Nouvelle Société', 'nadia@example.test');
        $this->signedInAsOperator();

        $this->postJson($this->pathOf($company, 'approve'), []);

        self::assertResponseIsSuccessful();
        self::assertSame('active', $this->json()['status']);
        $mail = $this->lastMailTo('nadia@example.test');
        self::assertStringContainsString('Nouvelle Société', $mail);
        self::assertStringContainsString('/login', $mail);
        self::assertSame(['company.approved'], $this->auditActionsFor($company));

        // The owner now reaches what a pending company kept from them.
        $this->login('nadia@example.test', 'password-1234');
        $this->getJson('/api/companies/'.$company->getId()->toRfc4122().'/members');
        self::assertResponseIsSuccessful();
    }

    public function testApprovingAnOpenCompanyAgainChangesNothingAndMailsNobody(): void
    {
        $company = $this->pendingCompany('Nouvelle Société', 'nadia@example.test');
        $this->signedInAsOperator();
        $this->postJson($this->pathOf($company, 'approve'), []);
        self::assertResponseIsSuccessful();

        $this->postJson($this->pathOf($company, 'approve'), []);

        self::assertResponseIsSuccessful();
        self::assertEmailCount(0);
        self::assertSame(['company.approved'], $this->auditActionsFor($company));
    }

    public function testRejectingSuspendsTheCompanyAndKeepsItClosed(): void
    {
        $company = $this->pendingCompany('Nouvelle Société', 'nadia@example.test');
        $this->signedInAsOperator();

        $this->postJson($this->pathOf($company, 'reject'), []);

        self::assertResponseIsSuccessful();
        self::assertSame('suspended', $this->json()['status']);
        self::assertSame(['company.rejected'], $this->auditActionsFor($company));

        $this->getJson(self::WAITING);
        self::assertSame([], $this->jsonList());

        $this->login('nadia@example.test', 'password-1234');
        $this->getJson('/api/companies/'.$company->getId()->toRfc4122().'/members');
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testAnUnknownCompanyIsNotFound(): void
    {
        $this->signedInAsOperator();

        $this->postJson('/api/platform/companies/0199a0a0-0000-7000-8000-000000000000/approve', []);

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testACompanyOwnerIsNotAnOperator(): void
    {
        $waiting = $this->pendingCompany('Nouvelle Société', 'nadia@example.test');
        $open = $this->createCompany('Acme');
        $this->createUser('owner@twes.local', 'password-1234', $open);
        $this->login('owner@twes.local', 'password-1234');

        $this->getJson(self::WAITING);
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        $this->postJson($this->pathOf($waiting, 'approve'), []);
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        $this->postJson($this->pathOf($waiting, 'reject'), []);
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

        self::assertSame('pending', $this->em()->getConnection()->fetchOne('SELECT status FROM company WHERE id = ?', [$waiting->getId()->toRfc4122()]));
    }

    private function pendingCompany(string $name, string $ownerEmail): Company
    {
        $company = Company::pending($name, 'TN', 'TND', 'fr', 'Africa/Tunis');
        $this->em()->persist($company);
        $this->em()->flush();
        $this->createUser($ownerEmail, 'password-1234', $company);

        return $company;
    }

    private function signedInAsOperator(): void
    {
        $this->createUser('op@twes.local', 'password-1234', operator: true);
        $this->login('op@twes.local', 'password-1234');
        self::assertResponseIsSuccessful();
    }

    private function pathOf(Company $company, string $decision): string
    {
        return '/api/platform/companies/'.$company->getId()->toRfc4122().'/'.$decision;
    }

    /** @return list<mixed> */
    private function auditActionsFor(Company $company): array
    {
        return $this->em()->getConnection()->fetchFirstColumn(
            "SELECT action FROM audit_log WHERE entity_type = 'company' AND entity_id = ? ORDER BY at",
            [$company->getId()->toRfc4122()],
        );
    }

    private function lastMailTo(string $address): string
    {
        $message = self::getMailerMessage();
        self::assertInstanceOf(MimeEmail::class, $message);
        self::assertSame($address, $message->getTo()[0]->getAddress());

        return (string) $message->getHtmlBody();
    }
}
