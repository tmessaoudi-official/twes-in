<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Tenancy\Domain\Company;
use App\Tenancy\Domain\Role;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\HttpFoundation\Response;

/**
 * The rest of the short list of actions that ask who is at the screen again (docs/SPEC.md § 7, the step-up list):
 * changing the company's bank details, adding or removing a member, changing a role, granting « Accès du support ». A
 * stolen session changing the IBAN so customers pay the attacker is the attack it was ruled for. Exports are
 * ExportStepUpTest's, the platform's writes PlatformWritesStepUpTest's and a vendor's bank details VendorBankDetailsStepUpTest's.
 */
final class SensitiveChangesStepUpTest extends ApiTestCase
{
    private const string PASSWORD = 'password-1234';

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = $this->createCompany('Acme', named: false);
        $this->createUser('owner@twes.local', self::PASSWORD, $this->company);
        $this->login('owner@twes.local', self::PASSWORD);
    }

    protected function tearDown(): void
    {
        Clock::set(new NativeClock());
        parent::tearDown();
    }

    public function testInvitingSomebodyWaitsForTheProofAndSendsNothingBefore(): void
    {
        // A company's roles are its own rows; the member role exists once somebody holds it.
        $this->createUser('member@twes.local', self::PASSWORD, $this->company, ['company.read'], Role::MEMBER);
        $this->postJson($this->path('members'), ['email' => 'joiner@twes.local', 'role' => Role::MEMBER]);

        $this->assertStepUpRequired();
        self::assertSame(0, $this->rows('SELECT COUNT(*) FROM invitation'));

        $this->stepUp(self::PASSWORD);
        $this->postJson($this->path('members'), ['email' => 'joiner@twes.local', 'role' => Role::MEMBER]);
        self::assertResponseIsSuccessful();
        self::assertSame(1, $this->rows('SELECT COUNT(*) FROM invitation'));
    }

    public function testRemovingAMemberWaitsForTheProof(): void
    {
        $member = $this->createUser('member@twes.local', self::PASSWORD, $this->company, ['company.read'], Role::MEMBER);
        $path = $this->path('members/'.$member->getId()->toRfc4122());

        $this->sendJson('DELETE', $path);

        $this->assertStepUpRequired();
        self::assertSame(2, $this->rows('SELECT COUNT(*) FROM membership'));

        $this->stepUp(self::PASSWORD);
        $this->sendJson('DELETE', $path);
        self::assertResponseIsSuccessful();
        self::assertSame(1, $this->rows('SELECT COUNT(*) FROM membership'));
    }

    public function testCreatingRevisingAndDeletingARoleEachWaitForTheProof(): void
    {
        $this->postJson($this->path('roles'), ['name' => 'barista', 'permissions' => ['customer.read']]);
        $this->assertStepUpRequired();
        self::assertSame(0, $this->rows("SELECT COUNT(*) FROM role WHERE name = 'barista'"));

        $this->stepUp(self::PASSWORD);
        $this->postJson($this->path('roles'), ['name' => 'barista', 'permissions' => ['customer.read']]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $id = $this->stringAt($this->json(), 'id');
        $this->forgetTheProof();

        $this->sendJson('PUT', $this->path('roles/'.$id), ['name' => 'barista', 'permissions' => ['customer.read', 'product.read']]);
        $this->assertStepUpRequired();
        $this->sendJson('DELETE', $this->path('roles/'.$id));
        $this->assertStepUpRequired();
        $held = $this->em()->getConnection()->fetchOne("SELECT permissions FROM role WHERE name = 'barista'");
        self::assertSame('["customer.read"]', $held, 'nothing changed while the proof was missing');
    }

    public function testChangingTheBankDetailsWaitsForTheProofAndKeepsTheOldOnes(): void
    {
        $this->sendJson('PUT', $this->path('profile'), $this->profile(['iban' => 'tn59 1000 6035 1835 9847 8831', 'bic' => 'biattntt']));

        $this->assertStepUpRequired();
        $this->getJson($this->path('profile'));
        self::assertArrayNotHasKey('iban', $this->json());

        $this->stepUp(self::PASSWORD);
        $this->sendJson('PUT', $this->path('profile'), $this->profile(['iban' => 'tn59 1000 6035 1835 9847 8831', 'bic' => 'biattntt']));
        self::assertResponseIsSuccessful();
        self::assertSame('TN5910006035183598478831', $this->json()['iban']);
    }

    public function testOnlyTheBicChangingAsksToo(): void
    {
        $this->stepUp(self::PASSWORD);
        $this->sendJson('PUT', $this->path('profile'), $this->profile(['iban' => 'TN5910006035183598478831', 'bic' => 'BIATTNTT']));
        self::assertResponseIsSuccessful();
        $this->forgetTheProof();

        $this->sendJson('PUT', $this->path('profile'), $this->profile(['iban' => 'TN5910006035183598478831', 'bic' => 'STBKTNTT']));

        $this->assertStepUpRequired();
    }

    public function testEverythingElseOnTheProfileIsRevisedWithoutAProof(): void
    {
        $this->stepUp(self::PASSWORD);
        $this->sendJson('PUT', $this->path('profile'), $this->profile(['iban' => 'TN5910006035183598478831', 'bic' => 'BIATTNTT']));
        self::assertResponseIsSuccessful();
        $this->forgetTheProof();

        // The same bank details, written as a person types them, and another address.
        $this->sendJson('PUT', $this->path('profile'), $this->profile([
            'iban' => 'tn59 1000 6035 1835 9847 8831',
            'bic' => 'biattntt',
            'addressLine1' => '14 rue de Marseille',
        ]));

        self::assertResponseIsSuccessful();
        self::assertSame('14 rue de Marseille', $this->json()['addressLine1']);
    }

    /** Opening the company to the platform's operators lets someone outside it read all of it (« Accès du support »). */
    public function testGrantingSupportAccessWaitsForTheProof(): void
    {
        $this->postJson($this->path('support-access'), ['hours' => 24]);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertSame('step_up_required', $this->json()['error'] ?? null);
        self::assertSame(0, $this->rows('SELECT COUNT(*) FROM support_grant'));

        $this->stepUp(self::PASSWORD);
        $this->postJson($this->path('support-access'), ['hours' => 24]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        self::assertSame(1, $this->rows('SELECT COUNT(*) FROM support_grant'));
    }

    /** What the person can fix is said before the proof is asked: the window, and that only an owner grants. */
    public function testASupportWindowOutOfBoundsAndANonOwnerAreAnsweredBeforeTheProof(): void
    {
        // Made before the first request: the client's kernel reboot leaves the company detached afterwards.
        $this->createUser('manager@twes.local', self::PASSWORD, $this->company, ['company.settings'], 'manager');
        $this->postJson($this->path('support-access'), ['hours' => 2]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame('support_window', $this->json()['error'] ?? null);

        $this->login('manager@twes.local', self::PASSWORD);
        $this->postJson($this->path('support-access'), ['hours' => 24]);
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertSame('owner_only', $this->json()['error'] ?? null);
    }

    /** The proof is asked last: who may not act at all is answered as before, never told to prove who they are. */
    public function testAStrangerAndAMemberWithoutTheRightAreAnsweredAsBefore(): void
    {
        $this->createUser('member@twes.local', self::PASSWORD, $this->company, ['company.read'], Role::MEMBER);
        $this->login('member@twes.local', self::PASSWORD);
        $this->postJson($this->path('members'), ['email' => 'joiner@twes.local', 'role' => Role::MEMBER]);
        // The same 404 as a stranger, as for anything a member may not do.
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);

        $this->createUser('stranger@twes.local', self::PASSWORD, $this->createCompany('Other'));
        $this->login('stranger@twes.local', self::PASSWORD);
        $this->sendJson('PUT', $this->path('profile'), $this->profile(['iban' => 'TN5910006035183598478831']));
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    /** Refused with the token API Platform's refusals lead their detail with, which the screens read to ask for the proof. */
    private function assertStepUpRequired(): void
    {
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertStringStartsWith('step_up_required:', $this->stringAt($this->json(), 'detail'));
    }

    /** The few minutes a proof holds (app.step_up.valid_for) have passed. */
    private function forgetTheProof(): void
    {
        Clock::set(new MockClock(new \DateTimeImmutable('+10 minutes')));
    }

    private function rows(string $sql): int
    {
        $count = $this->em()->getConnection()->fetchOne($sql);
        self::assertIsInt($count);

        return $count;
    }

    /**
     * @param array<string, mixed> $changes
     *
     * @return array<string, mixed>
     */
    private function profile(array $changes = []): array
    {
        return [...[
            'legalName' => null,
            'legalForm' => 'SARL',
            'identifiers' => ['matricule_fiscal' => '1234567A/B/M/000'],
            'addressLine1' => '12 rue de Marseille',
            'addressLine2' => null,
            'postalCode' => '1000',
            'city' => 'Tunis',
            'email' => 'billing@acme.tn',
            'phone' => '+216 71 000 000',
            'website' => 'https://acme.tn',
            'iban' => null,
            'bic' => null,
            'vatRegime' => 'standard',
            'invoiceFooterText' => 'Merci de votre confiance.',
            'latePenaltyText' => null,
        ], ...$changes];
    }

    private function path(string $rest): string
    {
        return '/api/companies/'.$this->company->getId()->toRfc4122().'/'.$rest;
    }
}
