<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Settings\Domain\Model\Setting;
use App\Settings\Domain\ValueObject\SettingAddress;
use App\Tenancy\Domain\Model\Company;
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

    public function testAnUnknownStatusIsRefused(): void
    {
        $this->signedInAsOperator();

        $this->getJson('/api/platform/companies?status=sleeping');

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
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

    public function testRefusingAWaitingCompanyKeepsItClosedAndTellsItsOwnersWhyAndWhomToAsk(): void
    {
        $this->em()->persist(new Setting(SettingAddress::platform(), 'support.phone', '+216 71 900 868', new \DateTimeImmutable()));
        $this->em()->flush();
        $company = $this->pendingCompany('Nouvelle Société', 'nadia@example.test');
        $this->signedInAsOperator();

        $this->postJson($this->pathOf($company, 'reject'), ['reason' => "  Matricule introuvable au registre\u{00A0}"]);

        self::assertResponseIsSuccessful();
        self::assertSame('suspended', $this->json()['status']);
        self::assertFalse($this->json()['opened'], 'its operators see it was never opened, so opening it approves it');
        self::assertSame(['company.rejected'], $this->auditActionsFor($company));
        $mail = $this->lastMailTo('nadia@example.test');
        self::assertStringContainsString('n’a pas été acceptée', $mail);
        self::assertStringContainsString('Matricule introuvable au registre</bdi>', $mail, 'the reason as typed, its ends trimmed');
        self::assertStringContainsString('+216 71 900 868', $mail, 'whom to ask');
        // A number split between two lines on a phone's narrow screen is misread or miscopied: it is kept whole.
        self::assertStringContainsString('<bdi dir="ltr" style="white-space: nowrap;">+216 71 900 868</bdi>', $mail, 'the number kept on one line');

        $this->getJson(self::WAITING);
        self::assertSame([], $this->jsonList());

        $this->login('nadia@example.test', 'password-1234');
        $this->getJson('/api/companies/'.$company->getId()->toRfc4122().'/members');
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        $this->getJson('/api/auth/me');
        $signedIn = $this->arrayAt($this->json(), 'company');
        self::assertTrue($signedIn['refused'], 'its sign-in says refused, never suspended');
        self::assertSame('Matricule introuvable au registre', $signedIn['suspensionReason']);
    }

    /** A reply to the closing mail reaches the support address the platform names, never the sender nobody reads. */
    public function testAReplyToTheClosingMailReachesTheSupportAddress(): void
    {
        $this->em()->persist(new Setting(SettingAddress::platform(), 'support.email', 'aide@twes.test', new \DateTimeImmutable()));
        $this->em()->flush();
        $company = $this->pendingCompany('Nouvelle Société', 'nadia@example.test');
        $this->signedInAsOperator();

        $this->postJson($this->pathOf($company, 'reject'), []);

        self::assertResponseIsSuccessful();
        $message = self::getMailerMessage();
        self::assertInstanceOf(MimeEmail::class, $message);
        self::assertSame(['aide@twes.test'], array_map(static fn ($address) => $address->getAddress(), $message->getReplyTo()));
    }

    /** With no way to reach the operators set, the mail says so instead of asking for a reply nobody reads. */
    public function testWithNoSupportChannelTheClosingMailSaysThereIsNoneYet(): void
    {
        $company = $this->pendingCompany('Nouvelle Société', 'nadia@example.test');
        $this->signedInAsOperator();

        $this->postJson($this->pathOf($company, 'reject'), []);

        self::assertResponseIsSuccessful();
        $message = self::getMailerMessage();
        self::assertInstanceOf(MimeEmail::class, $message);
        self::assertSame([], $message->getReplyTo());
        $mail = (string) $message->getHtmlBody();
        self::assertStringContainsString('n’a pas encore indiqué comment la joindre', $mail);
        self::assertStringNotContainsString('répondez', $mail);
    }

    /** An operator's reason is written as text: markup typed in it reaches the owners' mail as characters, never as a link. */
    public function testAReasonTypedWithMarkupReachesTheMailAsText(): void
    {
        $company = $this->pendingCompany('Nouvelle Société', 'nadia@example.test');
        $this->signedInAsOperator();

        $this->postJson($this->pathOf($company, 'reject'), ['reason' => 'Voir <a href="https://evil.test">ici</a>']);

        self::assertResponseIsSuccessful();
        $mail = $this->lastMailTo('nadia@example.test');
        self::assertStringContainsString('Voir &lt;a href=&quot;https://evil.test&quot;&gt;ici&lt;/a&gt;</bdi>', $mail);
        self::assertStringNotContainsString('<a href="https://evil.test"', $mail);
    }

    public function testSuspendingAnOpenCompanyClosesItAndTellsItsOwnersEvenWithoutAReason(): void
    {
        $company = $this->createCompany('Acme');
        $this->createUser('nadia@example.test', 'password-1234', $company);
        $this->signedInAsOperator();

        $this->postJson($this->pathOf($company, 'reject'), []);

        self::assertResponseIsSuccessful();
        self::assertSame('suspended', $this->json()['status']);
        self::assertTrue($this->json()['opened']);
        // A company that was open is suspended, not refused: the journal says which.
        self::assertSame(['company.suspended'], $this->auditActionsFor($company));
        $mail = $this->lastMailTo('nadia@example.test');
        self::assertStringContainsString('est suspendu', $mail);
        self::assertStringContainsString('sans en préciser le motif', $mail);

        $this->login('nadia@example.test', 'password-1234');
        $this->getJson('/api/companies/'.$company->getId()->toRfc4122().'/members');
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        $this->getJson('/api/auth/me');
        $signedIn = $this->arrayAt($this->json(), 'company');
        self::assertFalse($signedIn['refused']);
        self::assertNull($signedIn['suspensionReason']);
    }

    public function testRestoringASuspendedCompanySaysAccessIsBackNeverThatItWasApproved(): void
    {
        $company = $this->createCompany('Acme');
        $this->createUser('nadia@example.test', 'password-1234', $company);
        $this->signedInAsOperator();
        $this->postJson($this->pathOf($company, 'reject'), ['reason' => 'Facture impayée']);
        self::assertResponseIsSuccessful();

        $this->postJson($this->pathOf($company, 'approve'), []);

        self::assertResponseIsSuccessful();
        self::assertSame('active', $this->json()['status']);
        $mail = $this->lastMailTo('nadia@example.test');
        self::assertStringContainsString('rétabli', $mail);
        self::assertStringNotContainsString('approuvée', $mail);
        // Two writes inside one second tie on the journal's time: the set, never the order.
        $actions = $this->auditActionsFor($company);
        sort($actions);
        self::assertSame(['company.restored', 'company.suspended'], $actions);
        self::assertNull($this->em()->getConnection()->fetchOne('SELECT suspension_reason FROM company WHERE id = ?', [$company->getId()->toRfc4122()]), 'a reason leaves with its suspension');
    }

    public function testARefusedCompanyApprovedLaterIsApprovedForTheFirstTime(): void
    {
        $company = $this->pendingCompany('Nouvelle Société', 'nadia@example.test');
        $this->signedInAsOperator();
        $this->postJson($this->pathOf($company, 'reject'), []);
        self::assertResponseIsSuccessful();

        $this->postJson($this->pathOf($company, 'approve'), []);

        self::assertResponseIsSuccessful();
        self::assertSame('active', $this->json()['status']);
        self::assertStringContainsString('Nouvelle Société', $this->lastMailTo('nadia@example.test'));
        self::assertStringContainsString('approuvée', $this->lastMailTo('nadia@example.test'));
        $actions = $this->auditActionsFor($company);
        sort($actions);
        self::assertSame(['company.approved', 'company.rejected'], $actions);
    }

    public function testAReasonPastItsLimitIsRefusedAndNothingChanges(): void
    {
        $company = $this->createCompany('Acme');
        $this->signedInAsOperator();

        $this->postJson($this->pathOf($company, 'reject'), ['reason' => str_repeat('a', 501)]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame('active', $this->em()->getConnection()->fetchOne('SELECT status FROM company WHERE id = ?', [$company->getId()->toRfc4122()]));
        self::assertSame([], $this->auditActionsFor($company));
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
        // Every platform write waits for a recent proof (docs/SPEC.md § 7).
        $this->stepUp('password-1234');
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
