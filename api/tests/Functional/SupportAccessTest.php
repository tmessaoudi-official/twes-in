<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Fiscal\Application\Company\ProvisionCompany;
use App\Fiscal\Domain\Repository\UnitRepository;
use App\Module\Products\Domain\Model\Product;
use App\Module\Products\Domain\ValueObject\ProductDetails;
use App\Module\Products\Domain\ValueObject\ProductKind;
use App\Shared\Application\RealtimePublisher;
use App\Tenancy\Application\Support\RecordSupportVisit;
use App\Tenancy\Application\Support\SupportAccess;
use App\Tenancy\Domain\Model\Company;
use App\Tenancy\Domain\Model\Membership;
use App\Tenancy\Domain\Model\SupportGrant;
use App\Tests\Support\RecordingRealtimePublisher;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

/**
 * « Accès support » (docs/SPEC.md § 8 row 67): the owner lets the platform's operators read the company for an hour, a
 * day or three, and they open it from their switcher, read-only, never its exports nor its costs, every visit in the
 * activity log. Without a live grant an operator meets the company as any stranger does, the same 404, since
 * operators keep no standing access to a company's data. The operator here is NOT a member of the company: the seed
 * makes the development operator the demo company's owner, which would hide every refusal.
 */
final class SupportAccessTest extends ApiTestCase
{
    private const string OWNER = 'owner@twes.local';
    private const string CO_OWNER = 'partner@twes.local';
    private const string MANAGER = 'manager@twes.local';
    private const string OPERATOR = 'operator@twes.local';
    private const string PASSWORD = 'password-1234';

    private Company $company;
    private string $productId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = $this->createCompany('Acme');
        static::getContainer()->get(ProvisionCompany::class)->handle($this->company);
        $owner = $this->createUser(self::OWNER, self::PASSWORD, $this->company);
        // A second owner shares the first one's role: a company holds one role of each name.
        $role = $this->em()->getRepository(Membership::class)->findOneBy(['user' => $owner])?->getRole();
        self::assertNotNull($role);
        $this->em()->persist(new Membership($this->createUser(self::CO_OWNER, self::PASSWORD), $this->company, $role, self::heldNow()));
        $this->em()->flush();
        $this->createUser(self::MANAGER, self::PASSWORD, $this->company, ['company.settings', 'customer.read', 'customer.write'], 'manager');
        $this->createUser(self::OPERATOR, self::PASSWORD, operator: true);

        $unit = static::getContainer()->get(UnitRepository::class)->ofCodeInCompany('C62', $this->company->getId());
        self::assertNotNull($unit);
        $product = Product::create($this->company, 'VIS-6X40', new ProductDetails('Vis 6x40', null, ProductKind::Goods, '0.45', '0.22'), $unit, null, [], self::heldNow());
        $this->em()->persist($product);
        $this->em()->flush();
        $this->productId = $product->getId()->toRfc4122();
    }

    public function testWithoutAGrantAnOperatorMeetsTheCompanyAsAStrangerDoes(): void
    {
        $this->as(self::OPERATOR);

        $this->getJson($this->path('/customers'));
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        $this->postJson('/api/me/company', ['companyId' => $this->companyId()]);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        $this->getJson('/api/me/companies');
        self::assertResponseIsSuccessful();
        self::assertSame([], $this->jsonList());
    }

    public function testTheOwnerGrantsADayAndTheOperatorReadsTheCompanyFromTheSwitcher(): void
    {
        $this->as(self::OWNER);
        $this->stepUp(self::PASSWORD);
        $this->postJson($this->path('/support-access'), ['hours' => 24]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $until = $this->json()['endsAt'] ?? null;
        self::assertIsString($until);
        $hours = ((new \DateTimeImmutable($until))->getTimestamp() - self::heldNow()->getTimestamp()) / 3600;
        self::assertEqualsWithDelta(24, $hours, 0.1);

        $this->getJson($this->path('/support-access'));
        self::assertResponseIsSuccessful();
        self::assertSame($until, $this->json()['endsAt']);
        // The windows offered and the first one are the operator's (app.support.windows_hours), as shipped: today's.
        self::assertSame([1, 24, 72], $this->json()['hours']);
        self::assertSame(24, $this->json()['defaultHours']);

        // The other owner is told; the one who granted it knows.
        self::assertSame([self::CO_OWNER], $this->toldOf('support_access.granted'));

        $this->as(self::OPERATOR);
        $this->getJson('/api/me/companies');
        self::assertResponseIsSuccessful();
        $offered = $this->jsonList();
        self::assertCount(1, $offered);
        self::assertSame($this->companyId(), $offered[0]['companyId']);
        self::assertSame('support', $offered[0]['role']);
        self::assertSame($until, $offered[0]['supportAccessUntil']);

        $this->postJson('/api/me/company', ['companyId' => $this->companyId()]);
        self::assertResponseIsSuccessful();
        self::assertSame('support', $this->json()['role']);

        $this->getJson('/api/auth/me');
        self::assertResponseIsSuccessful();
        $me = $this->json();
        self::assertSame($until, $me['supportAccessUntil']);
        $company = $me['company'];
        self::assertIsArray($company);
        self::assertSame('support', $company['role']);
        $permissions = $me['permissions'];
        self::assertIsArray($permissions);
        self::assertContains('customer.read', $permissions);
        self::assertNotContains('customer.write', $permissions);
        self::assertNotContains('product.cost.read', $permissions);
        foreach ($permissions as $permission) {
            self::assertIsString($permission);
            self::assertStringEndsWith('.read', $permission);
        }

        $this->getJson($this->path('/customers'));
        self::assertResponseIsSuccessful();
        $this->getJson($this->path('/customers'));
        self::assertResponseIsSuccessful();

        // Entering once, and the page once however often it is read within the quarter hour.
        self::assertSame(['support.entered' => 1, 'support.read' => 1], $this->supportVisits());

        // The members see that support is in, and until when.
        $this->as(self::MANAGER);
        $this->getJson('/api/auth/me');
        self::assertSame($until, $this->json()['supportAccessUntil']);
    }

    /**
     * A page support reads is written to the journal, never said to the members' open tabs: every one of them hears a
     * change to the grant (« support_grant » is a session kind) and reads its session again, so at PT0M each page the
     * operator opened reloaded every member's screens. The grant's own changes are still said.
     */
    public function testAPageSupportReadsIsJournalledWithoutReloadingTheMembersScreens(): void
    {
        // A kernel of its own, whose live changes the recorder hears: the setup's provisioning already used the publisher.
        static::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->client->setServerParameter('HTTP_ORIGIN', 'http://localhost');
        $this->client->setServerParameter('HTTP_ACCEPT_LANGUAGE', 'fr');
        $this->client->disableReboot();
        $publisher = new RecordingRealtimePublisher();
        static::getContainer()->set(RealtimePublisher::class, $publisher);
        $this->grant(24);
        $this->as(self::OPERATOR);
        $this->postJson('/api/me/company', ['companyId' => $this->companyId()]);
        self::assertResponseIsSuccessful();

        $this->getJson($this->path('/customers'));
        self::assertResponseIsSuccessful();

        self::assertSame(['support.entered' => 1, 'support.read' => 1], $this->supportVisits());
        $said = [];
        foreach ($publisher->pushed as $push) {
            if ('changed' === ($push['data']['type'] ?? null) && 'support_grant' === ($push['data']['kind'] ?? null)) {
                $action = $push['data']['action'] ?? null;
                self::assertIsString($action);
                $said[] = $action;
            }
        }
        self::assertContains('support_access.granted', $said, 'the grant itself is still said to the members');
        self::assertNotContains(RecordSupportVisit::READ, $said, 'a page read reloads nobody');
    }

    public function testUnderAGrantNothingIsWrittenExportedOrCostedAndTheOwnersOwnPagesStayClosed(): void
    {
        $this->grant(24);
        $this->as(self::OPERATOR);
        $this->postJson('/api/me/company', ['companyId' => $this->companyId()]);
        self::assertResponseIsSuccessful();

        $this->postJson($this->path('/customers'), []);
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertSame('support_read_only', $this->json()['error']);

        $this->sendJson('PUT', $this->path('/products/'.$this->productId), []);
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertSame('support_read_only', $this->json()['error']);

        $this->getJson($this->path('/exports/customers.csv'));
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertSame('support_read_only', $this->json()['error']);
        $this->getJson($this->path('/exports/invoices.pdf'));
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        // The month's withholding declaration carries every payment withheld at once: an export by another path.
        $this->getJson($this->path('/withholding-declarations/tej/2026-10'));
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertSame('support_read_only', $this->json()['error']);
        // A report's file carries every customer's figures at once, as a list's export does.
        $this->getJson($this->path('/reports/sales-by-customer/export.csv?from=2026-01-01&to=2026-12-31'));
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertSame('support_read_only', $this->json()['error']);

        $this->getJson($this->path('/products/'.$this->productId));
        self::assertResponseIsSuccessful();
        self::assertNull($this->json()['costPrice']);

        // What only an owner or a settings holder reaches stays as closed as to a stranger.
        $this->getJson($this->path('/data-erasure'));
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        $this->getJson($this->path('/support-access'));
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testAPathSpelledWithPercentEscapesMeetsTheSameWall(): void
    {
        // The router decodes the path once before matching it; the wall must read the path the router routes.
        $this->grant(24);
        $this->as(self::OPERATOR);
        $this->postJson('/api/me/company', ['companyId' => $this->companyId()]);
        self::assertResponseIsSuccessful();
        $id = $this->companyId();
        $escapedId = '%'.bin2hex($id[0]).substr($id, 1);

        $this->getJson($this->path('/%65xports/customers.csv'));
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertSame('support_read_only', $this->json()['error']);
        $this->getJson('/api/companies/'.$escapedId.'/exports/customers.csv');
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        $this->postJson('/api/companies/'.$escapedId.'/customers', []);
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertSame('support_read_only', $this->json()['error']);

        // A read so spelled is still written in the activity log.
        $this->getJson('/%61pi/companies/'.$escapedId.'/%63ustomers');
        self::assertResponseIsSuccessful();
        self::assertSame(['support.entered' => 1, 'support.read' => 1], $this->supportVisits());
    }

    public function testTheMembersKeepWorkingWhileSupportIsIn(): void
    {
        $this->grant(1);
        $this->as(self::MANAGER);

        $this->postJson($this->path('/customers'), []);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function testAGrantEndedByTheOwnerOrPastItsEndClosesTheCompanyAgain(): void
    {
        $this->grant(72);
        $this->as(self::OPERATOR);
        $this->postJson('/api/me/company', ['companyId' => $this->companyId()]);
        self::assertResponseIsSuccessful();

        $this->as(self::OWNER);
        $this->sendJson('DELETE', $this->path('/support-access'));
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
        self::assertSame([self::CO_OWNER], $this->toldOf('support_access.ended'));

        $this->as(self::OPERATOR);
        $this->getJson($this->path('/customers'));
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        // The session the operator entered with no longer carries the company, nor anything read through it.
        $this->getJson('/api/auth/me');
        self::assertResponseIsSuccessful();
        $me = $this->json();
        self::assertNull($me['supportAccessUntil']);
        self::assertSame([], $me['permissions']);
        $company = $me['company'];
        self::assertTrue(null === $company || (\is_array($company) && $this->companyId() !== $company['id']), 'the closed company is still the working one');

        $this->grant(1);
        $this->connection()->executeStatement('UPDATE support_grant SET ends_at = ? WHERE ended_at IS NULL', [self::apiNow('-1 minute')]);
        $this->as(self::OPERATOR);
        $this->getJson($this->path('/customers'));
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        $this->getJson('/api/me/companies');
        self::assertSame([], $this->jsonList());
    }

    public function testAnOperatorWithoutASecondFactorOfTheirOwnIsNotLetIn(): void
    {
        $operator = $this->createUser('second-operator@twes.local', self::PASSWORD, operator: true, authenticator: false);
        $this->grant(24);

        // The grant itself refuses them, whatever else stands in the way.
        self::assertNull(static::getContainer()->get(SupportAccess::class)->grantOf($operator->getId(), $this->company->getId()));
        self::assertSame([], static::getContainer()->get(SupportAccess::class)->grantsFor($operator->getId(), 10));

        // And an operator is made to enrol one before anything else.
        $this->as('second-operator@twes.local');
        $this->getJson($this->path('/customers'));
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertSame('mfa_enrolment_required', $this->json()['error']);
    }

    public function testAnotherCompanyStaysClosed(): void
    {
        $other = $this->createCompany('Globex');
        static::getContainer()->get(ProvisionCompany::class)->handle($other);
        $this->grant(24);
        $this->as(self::OPERATOR);

        $this->getJson('/api/companies/'.$other->getId()->toRfc4122().'/customers');
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    /** Workflow hunt SUP-1: the owner says what is wrong, so the operator opens the company knowing where to look. */
    public function testTheOwnerSaysWhatIsWrongAndOnlyTheOperatorReadingItIsShown(): void
    {
        $this->as(self::OWNER);
        $this->stepUp(self::PASSWORD);
        $reason = 'La facture du 3 mars s’imprime sans le timbre';
        $this->postJson($this->path('/support-access'), ['hours' => 24, 'reason' => "\u{00A0} {$reason}\n"]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        self::assertSame($reason, $this->json()['reason'] ?? null, 'kept without what was typed around it');
        $this->getJson($this->path('/support-access'));
        self::assertSame($reason, $this->json()['reason'] ?? null);
        $changes = $this->connection()->fetchOne("SELECT changes FROM audit_log WHERE action = 'support_access.granted'");
        self::assertIsString($changes);
        self::assertStringContainsString('reason', $changes);
        self::assertStringNotContainsString('timbre', $changes, 'the journal names the field, never what it says');

        $this->as(self::OPERATOR);
        $this->getJson('/api/me/companies');
        self::assertSame($reason, $this->jsonList()[0]['supportAccessReason'] ?? null);
        $this->postJson('/api/me/company', ['companyId' => $this->companyId()]);
        $this->getJson('/api/auth/me');
        self::assertSame($reason, $this->json()['supportAccessReason'] ?? null);

        $this->as(self::MANAGER);
        $this->getJson('/api/auth/me');
        self::assertNull($this->json()['supportAccessReason'] ?? null, 'a member sees that support is in, not what the owner asked of it');

        $this->as(self::OWNER);
        $this->stepUp(self::PASSWORD);
        $this->postJson($this->path('/support-access'), ['hours' => 1, 'reason' => " \u{200B} "]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        self::assertNull($this->json()['reason'] ?? null, 'nothing said is no reason');
    }

    public function testAReasonIsTextAndShortEnoughToRead(): void
    {
        $this->as(self::OWNER);
        $this->stepUp(self::PASSWORD);
        foreach ([42, ['a'], str_repeat('é', 501)] as $reason) {
            $this->postJson($this->path('/support-access'), ['hours' => 24, 'reason' => $reason]);
            self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, (string) json_encode($reason));
            self::assertSame(['support_reason', ['max' => 500]], $this->refusalOf('reason'));
        }
        self::assertEquals(0, $this->connection()->fetchOne('SELECT COUNT(*) FROM support_grant'));
        $this->postJson($this->path('/support-access'), ['hours' => 24, 'reason' => str_repeat('é', 500)]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
    }

    public function testOnlyTheOwnerGrantsAndOnlyForAnHourADayOrThree(): void
    {
        $this->as(self::MANAGER);
        $this->postJson($this->path('/support-access'), ['hours' => 24]);
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertSame('owner_only', $this->json()['error']);

        $this->as(self::OWNER);
        foreach ([0, 2, 168, '24', null] as $hours) {
            $this->postJson($this->path('/support-access'), ['hours' => $hours]);
            self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, (string) json_encode($hours));
        }
        $this->getJson($this->path('/support-access'));
        self::assertResponseIsSuccessful();
        self::assertNull($this->json()['endsAt']);

        $this->stepUp(self::PASSWORD);
        // Granting again while a grant is live replaces it: one window at a time.
        $this->postJson($this->path('/support-access'), ['hours' => 1]);
        $this->postJson($this->path('/support-access'), ['hours' => 72]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        self::assertEquals(1, $this->connection()->fetchOne('SELECT COUNT(*) FROM support_grant WHERE ended_at IS NULL'));

        $actions = $this->connection()->fetchFirstColumn("SELECT action FROM audit_log WHERE entity_type = 'support_grant' ORDER BY action");
        self::assertSame(['support_access.ended', 'support_access.granted', 'support_access.granted'], $actions);
    }

    /**
     * The owner may name the one operator who comes in. The page offers exactly those a grant would let in, by the name
     * the activity log already shows the owner; every other operator meets the company as a stranger does, in its
     * switcher too.
     */
    public function testTheOwnerNamesTheOperatorAndOnlyTheyReadTheCompany(): void
    {
        $named = $this->createUser('named-operator@twes.local', self::PASSWORD, operator: true);
        $this->createUser('password-only@twes.local', self::PASSWORD, operator: true, authenticator: false);

        $this->as(self::OWNER);
        $this->getJson($this->path('/support-access'));
        self::assertResponseIsSuccessful();
        $offered = $this->json()['operators'];
        self::assertIsArray($offered);
        self::assertEqualsCanonicalizing([
            ['id' => $named->getId()->toRfc4122(), 'name' => $named->getDisplayName()],
            ['id' => $this->userId(self::OPERATOR), 'name' => 'Operator'],
        ], $offered, 'the operators a grant lets in, by name only');
        self::assertNull($this->json()['operator']);

        $this->stepUp(self::PASSWORD);
        $this->postJson($this->path('/support-access'), ['hours' => 24, 'operator' => $named->getId()->toRfc4122()]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        self::assertSame(['id' => $named->getId()->toRfc4122(), 'name' => $named->getDisplayName()], $this->json()['operator']);
        $changes = $this->connection()->fetchOne("SELECT changes FROM audit_log WHERE action = 'support_access.granted'");
        self::assertIsString($changes);
        $recorded = json_decode($changes, true);
        self::assertIsArray($recorded);
        self::assertSame(['endsAt', 'operator'], $recorded['fields'] ?? null);

        $this->as(self::OPERATOR);
        $this->getJson('/api/me/companies');
        self::assertSame([], $this->jsonList());
        $this->postJson('/api/me/company', ['companyId' => $this->companyId()]);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        $this->getJson($this->path('/customers'));
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);

        $this->as('named-operator@twes.local');
        $this->getJson('/api/me/companies');
        self::assertCount(1, $this->jsonList());
        $this->postJson('/api/me/company', ['companyId' => $this->companyId()]);
        self::assertResponseIsSuccessful();
        $this->getJson($this->path('/customers'));
        self::assertResponseIsSuccessful();
    }

    /** Opening it again to another operator closes it to the one already inside, on their very next request. */
    public function testOpeningItToAnotherOperatorClosesItToTheOneInside(): void
    {
        $named = $this->createUser('named-operator@twes.local', self::PASSWORD, operator: true);
        $this->grant(24);
        $this->as(self::OPERATOR);
        $this->postJson('/api/me/company', ['companyId' => $this->companyId()]);
        self::assertResponseIsSuccessful();
        $this->getJson($this->path('/customers'));
        self::assertResponseIsSuccessful();
        $inside = $this->client->getCookieJar()->all();

        $this->as(self::OWNER);
        $this->stepUp(self::PASSWORD);
        $this->postJson($this->path('/support-access'), ['hours' => 24, 'operator' => $named->getId()->toRfc4122()]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        // The operator's own session, still signed in, as it was before the owner changed the grant.
        $this->client->getCookieJar()->clear();
        foreach ($inside as $cookie) {
            $this->client->getCookieJar()->set($cookie);
        }
        $this->getJson($this->path('/customers'));
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        $this->getJson('/api/auth/me');
        self::assertResponseIsSuccessful();
        $me = $this->json();
        self::assertSame([], $me['permissions']);
        $company = $me['company'];
        self::assertTrue(null === $company || (\is_array($company) && $this->companyId() !== $company['id']), 'the company closed to them is still their working one');
        $this->getJson('/api/me/companies');
        self::assertSame([], $this->jsonList());
        // Their banner no longer says they read it: the grant is still live, but not for them.
        $this->getJson('/api/auth/me');
        self::assertNull($this->json()['supportAccessUntil']);
    }

    /**
     * A name the grant could not let in is refused on its field before the proof is asked: someone not an operator, an
     * operator without a second factor, an operator who is a member (they read as their role says), or not an account.
     */
    public function testANameAGrantCouldNotLetInIsRefusedOnItsField(): void
    {
        $passwordOnly = $this->createUser('password-only@twes.local', self::PASSWORD, operator: true, authenticator: false);
        $memberOperator = $this->createUser('member-operator@twes.local', self::PASSWORD, $this->company, ['customer.read'], 'clerk', operator: true);

        $this->as(self::OWNER);
        foreach ([$this->userId(self::MANAGER), $passwordOnly->getId()->toRfc4122(), $memberOperator->getId()->toRfc4122(), '01900000-0000-7000-8000-000000000000', 'someone', 42] as $operator) {
            $this->postJson($this->path('/support-access'), ['hours' => 24, 'operator' => $operator]);
            self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, (string) json_encode($operator));
            self::assertSame(['support_operator', []], $this->refusalOf('operator'), (string) json_encode($operator));
        }
        self::assertEquals(0, $this->connection()->fetchOne('SELECT COUNT(*) FROM support_grant'));
    }

    /** The switcher's limit counts the grants this operator may read, never one opened to someone else before them. */
    public function testTheSwitcherCountsOnlyTheGrantsOpenedToThisOperator(): void
    {
        $named = $this->createUser('named-operator@twes.local', self::PASSWORD, operator: true);
        $first = $this->createCompany('Aaa first');
        $owner = $this->createUser('first-owner@twes.local', self::PASSWORD, $first);
        $this->em()->persist(new SupportGrant($first, $owner, 24, self::heldNow(), operator: $named));
        $this->em()->flush();
        $this->grant(24);

        $access = static::getContainer()->get(SupportAccess::class);
        $offered = $access->grantsFor(Uuid::fromString($this->userId(self::OPERATOR)), 1);
        self::assertCount(1, $offered);
        self::assertSame('Acme', $offered[0]->getCompany()->getName());
        self::assertSame('Aaa first', $access->grantsFor($named->getId(), 1)[0]->getCompany()->getName());
    }

    /**
     * The named operator's account deleted keeps the grant, a record the company keeps (TenancyFiscalRecords), and opens
     * it to no one: forgetting whom it named never opens it to every operator.
     */
    public function testAGrantWhoseOperatorIsDeletedIsKeptAndOpensToNoOne(): void
    {
        $named = $this->createUser('named-operator@twes.local', self::PASSWORD, operator: true);
        $this->as(self::OWNER);
        $this->stepUp(self::PASSWORD);
        $this->postJson($this->path('/support-access'), ['hours' => 72, 'operator' => $named->getId()->toRfc4122()]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        $this->connection()->executeStatement('DELETE FROM "user" WHERE id = ?', [$named->getId()->toRfc4122()]);
        self::assertEquals(1, $this->connection()->fetchOne('SELECT COUNT(*) FROM support_grant WHERE operator_id IS NULL AND operator_named'));

        $this->em()->clear();
        $this->as(self::OPERATOR);
        $this->getJson($this->path('/customers'));
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        $this->getJson('/api/me/companies');
        self::assertSame([], $this->jsonList());

        // The owner's page says so, never that it opens to every operator.
        $this->as(self::OWNER);
        $this->getJson($this->path('/support-access'));
        self::assertResponseIsSuccessful();
        self::assertSame([null, true], [$this->json()['operator'] ?? null, $this->json()['operatorGone'] ?? null]);
    }

    private function userId(string $email): string
    {
        $id = $this->connection()->fetchOne('SELECT id FROM "user" WHERE email = ?', [$email]);
        self::assertIsString($id);

        return $id;
    }

    private function grant(int $hours): void
    {
        $this->as(self::OWNER);
        // Opening the company to support is on the step-up list.
        $this->stepUp(self::PASSWORD);
        $this->postJson($this->path('/support-access'), ['hours' => $hours]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
    }

    private function as(string $email): void
    {
        $this->client->getCookieJar()->clear();
        $this->login($email, self::PASSWORD);
        self::assertResponseIsSuccessful();
    }

    /** @return list<string> the addresses of those told, in order */
    private function toldOf(string $type): array
    {
        /** @var list<string> $told */
        $told = $this->connection()->fetchFirstColumn('SELECT u.email FROM inbox_item i JOIN "user" u ON u.id = i.recipient_id WHERE i.type = ? ORDER BY u.email', [$type]);

        return $told;
    }

    /** @return array<string, int> */
    private function supportVisits(): array
    {
        $visits = [];
        foreach ($this->connection()->fetchAllAssociative("SELECT action, COUNT(*) AS n FROM audit_log WHERE action LIKE 'support.%' AND company_id = ? GROUP BY action ORDER BY action", [$this->companyId()]) as $row) {
            self::assertIsString($row['action']);
            self::assertIsNumeric($row['n']);
            $visits[$row['action']] = (int) $row['n'];
        }

        return $visits;
    }

    private function connection(): Connection
    {
        return $this->em()->getConnection();
    }

    private function companyId(): string
    {
        return $this->company->getId()->toRfc4122();
    }

    private function path(string $rest): string
    {
        return '/api/companies/'.$this->companyId().$rest;
    }
}
