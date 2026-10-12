<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Fiscal\Application\Company\ProvisionCompany;
use App\Fiscal\Domain\UnitRepository;
use App\Module\Products\Domain\Product;
use App\Module\Products\Domain\ProductDetails;
use App\Module\Products\Domain\ProductKind;
use App\Tenancy\Application\Support\SupportAccess;
use App\Tenancy\Domain\Company;
use App\Tenancy\Domain\Membership;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\Response;

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
        $this->em()->persist(new Membership($this->createUser(self::CO_OWNER, self::PASSWORD), $this->company, $role));
        $this->em()->flush();
        $this->createUser(self::MANAGER, self::PASSWORD, $this->company, ['company.settings', 'customer.read', 'customer.write'], 'manager');
        $this->createUser(self::OPERATOR, self::PASSWORD, operator: true);

        $unit = static::getContainer()->get(UnitRepository::class)->ofCodeInCompany('C62', $this->company->getId());
        self::assertNotNull($unit);
        $product = Product::create($this->company, 'VIS-6X40', new ProductDetails('Vis 6x40', null, ProductKind::Goods, '0.45', '0.22'), $unit, null, [], new \DateTimeImmutable());
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
        $this->postJson($this->path('/support-access'), ['hours' => 24]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $until = $this->json()['endsAt'] ?? null;
        self::assertIsString($until);
        $hours = ((new \DateTimeImmutable($until))->getTimestamp() - time()) / 3600;
        self::assertEqualsWithDelta(24, $hours, 0.1);

        $this->getJson($this->path('/support-access'));
        self::assertResponseIsSuccessful();
        self::assertSame($until, $this->json()['endsAt']);

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
        $this->connection()->executeStatement("UPDATE support_grant SET ends_at = now() - interval '1 minute' WHERE ended_at IS NULL");
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

        // Granting again while a grant is live replaces it: one window at a time.
        $this->postJson($this->path('/support-access'), ['hours' => 1]);
        $this->postJson($this->path('/support-access'), ['hours' => 72]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        self::assertEquals(1, $this->connection()->fetchOne('SELECT COUNT(*) FROM support_grant WHERE ended_at IS NULL'));

        $actions = $this->connection()->fetchFirstColumn("SELECT action FROM audit_log WHERE entity_type = 'support_grant' ORDER BY action");
        self::assertSame(['support_access.ended', 'support_access.granted', 'support_access.granted'], $actions);
    }

    private function grant(int $hours): void
    {
        $this->as(self::OWNER);
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
