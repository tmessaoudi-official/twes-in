<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Audit\Domain\AuditLog;
use App\Identity\Domain\User;
use App\Tenancy\Application\Seed\SeedPlatform;
use App\Tenancy\Domain\Company;
use App\Tenancy\Domain\Role;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

/**
 * « Journal d'activité »: the company's audit log read back, for whoever holds audit.read (docs/SPEC.md § 7,
 * 2026-09-26 23:04). What changed is named, never its value; a sign-in address is shown to who manages the team.
 */
final class ActivityJournalTest extends ApiTestCase
{
    private Company $company;
    private User $owner;
    private User $clerk;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = $this->createCompany('Acme');
        $this->owner = $this->createUser('owner@twes.local', 'password-1234', $this->company);
        $this->clerk = $this->createUser('clerk@twes.local', 'password-1234', $this->company, ['company.read', 'audit.read'], 'lecteur');
    }

    public function testTheJournalNamesWhoDidWhatToWhichRecordNewestFirstAndNothingOfAnotherCompany(): void
    {
        $customer = Uuid::v7();
        $this->entry('customer', $customer, 'customer.created', $this->owner, ['fields' => ['name', 'email']], '-2 days');
        $this->entry('customer', $customer, 'customer.revised', $this->clerk, ['fields' => ['email']], '-1 day');
        $elsewhere = $this->createCompany('Elsewhere');
        $this->entry('customer', Uuid::v7(), 'customer.created', $this->owner, [], '-1 hour', $elsewhere);
        $this->entry('user', $this->owner->getId(), 'auth.login_failed', null, ['email' => 'owner@twes.local'], '-1 hour', null);

        $this->login('owner@twes.local', 'password-1234');
        $this->client->request('GET', $this->path().'?entityType[]=customer', server: ['HTTP_ACCEPT' => 'application/ld+json']);

        self::assertResponseIsSuccessful();
        self::assertSame(2, $this->jsonPage()['totalItems']);
        $rows = $this->jsonList();
        self::assertSame(['customer.revised', 'customer.created'], array_column($rows, 'action'));
        self::assertSame([$customer->toRfc4122(), $customer->toRfc4122()], array_column($rows, 'entityId'));
        self::assertSame(['Clerk', 'Owner'], array_column($rows, 'actorName'));
        self::assertSame($this->clerk->getId()->toRfc4122(), $rows[0]['actorId']);
        self::assertSame([['email'], ['name', 'email']], array_column($rows, 'fields'));
    }

    public function testWhatChangedIsNamedAndItsValueIsNeverShown(): void
    {
        $this->entry('tax_component', Uuid::v7(), 'tax_component.created', $this->owner, ['code' => 'TVA19', 'rate' => '19.000'], '-1 hour');

        $this->login('clerk@twes.local', 'password-1234');
        $this->client->request('GET', $this->path().'?entityType[]=tax_component', server: ['HTTP_ACCEPT' => 'application/ld+json']);

        self::assertResponseIsSuccessful();
        self::assertSame(['code', 'rate'], $this->jsonList()[0]['fields']);
        $body = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString('TVA19', $body);
        self::assertStringNotContainsString('19.000', $body);
    }

    public function testASignInAddressIsShownToWhoManagesTheTeamAndToNobodyElse(): void
    {
        $this->entry('user', $this->clerk->getId(), 'auth.login', $this->clerk, [], '-1 hour', ip: '198.51.100.7');

        $this->login('clerk@twes.local', 'password-1234');
        $this->client->request('GET', $this->path().'?action[]=auth.login&actorId='.$this->clerk->getId()->toRfc4122(), server: ['HTTP_ACCEPT' => 'application/ld+json']);
        self::assertResponseIsSuccessful();
        self::assertNotEmpty($this->jsonList());
        foreach ($this->jsonList() as $row) {
            self::assertArrayHasKey('ip', $row);
            self::assertNull($row['ip'], 'a reader who does not manage the team');
        }

        $this->login('owner@twes.local', 'password-1234');
        $this->client->request('GET', $this->path().'?action[]=auth.login&actorId='.$this->clerk->getId()->toRfc4122(), server: ['HTTP_ACCEPT' => 'application/ld+json']);
        self::assertContains('198.51.100.7', array_column($this->jsonList(), 'ip'));
    }

    public function testThePersonTheRecordTheActionAndTheDaysNarrowTheJournal(): void
    {
        $invoice = Uuid::v7();
        $this->entry('invoice', $invoice, 'invoice.created', $this->owner, [], '2026-03-02 10:00');
        $this->entry('invoice', $invoice, 'invoice.issued', $this->clerk, [], '2026-03-04 10:00');
        $this->entry('invoice', Uuid::v7(), 'invoice.created', $this->clerk, [], '2026-03-05 10:00');
        $this->login('owner@twes.local', 'password-1234');

        foreach ([
            'entityType[]=invoice&entityId='.$invoice->toRfc4122() => ['invoice.issued', 'invoice.created'],
            'entityType[]=invoice&actorId='.$this->clerk->getId()->toRfc4122() => ['invoice.created', 'invoice.issued'],
            'entityType[]=invoice&actorId[]='.$this->clerk->getId()->toRfc4122().'&actorId[]='.$this->owner->getId()->toRfc4122() => ['invoice.created', 'invoice.issued', 'invoice.created'],
            'action[]=invoice.issued' => ['invoice.issued'],
            'entityType[]=invoice&at[from]=2026-03-03&at[to]=2026-03-04' => ['invoice.issued'],
            'entityType[]=invoice&order[at]=asc' => ['invoice.created', 'invoice.issued', 'invoice.created'],
        ] as $query => $actions) {
            $this->client->request('GET', $this->path().'?'.$query, server: ['HTTP_ACCEPT' => 'application/ld+json']);
            self::assertResponseIsSuccessful($query);
            self::assertSame($actions, array_column($this->jsonList(), 'action'), $query);
        }
    }

    public function testTheJournalKeepsTwelveMonthsUnlessTheCompanyKeepsMore(): void
    {
        $this->entry('vendor', Uuid::v7(), 'vendor.created', $this->owner, [], '-13 months');
        $this->entry('vendor', Uuid::v7(), 'vendor.revised', $this->owner, [], '-11 months');
        $this->login('owner@twes.local', 'password-1234');

        $this->client->request('GET', $this->path().'?entityType[]=vendor&at[from]=2000-01-01', server: ['HTTP_ACCEPT' => 'application/ld+json']);
        self::assertSame(['vendor.revised'], array_column($this->jsonList(), 'action'), 'older than the company keeps, even when asked');

        $this->sendJson('PUT', '/api/companies/'.$this->company->getId()->toRfc4122().'/settings/activity.retention_months', ['level' => 'company', 'value' => 24]);
        self::assertResponseIsSuccessful();
        $this->client->request('GET', $this->path().'?entityType[]=vendor', server: ['HTTP_ACCEPT' => 'application/ld+json']);
        self::assertSame(['vendor.revised', 'vendor.created'], array_column($this->jsonList(), 'action'));

        $this->sendJson('PUT', '/api/companies/'.$this->company->getId()->toRfc4122().'/settings/activity.retention_months', ['level' => 'company', 'value' => 6]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, 'never kept less than twelve months');
    }

    public function testTheJournalIsAFileUnderTheFiltersOfItsScreenWithoutAddressesOrValues(): void
    {
        $customer = Uuid::v7();
        $this->entry('customer', $customer, 'customer.revised', $this->clerk, ['fields' => ['email'], 'email' => 'secret@client.tn'], '2026-03-04 10:00', ip: '198.51.100.7');
        $this->entry('vendor', Uuid::v7(), 'vendor.created', $this->owner, [], '2026-03-04 11:00');
        $this->login('owner@twes.local', 'password-1234');
        $this->stepUp('password-1234');

        $this->client->request('GET', '/api/companies/'.$this->company->getId()->toRfc4122().'/exports/activity.csv?entityType[]=customer');

        self::assertResponseIsSuccessful();
        $lines = array_values(array_filter(explode("\n", (string) $this->client->getInternalResponse()->getContent()), static fn (string $line): bool => '' !== trim($line)));
        $header = str_getcsv(ltrim($lines[0], "\xEF\xBB\xBF"), escape: '');
        self::assertSame(['at', 'actor', 'action', 'entity_type', 'entity_id', 'fields'], $header);
        self::assertCount(2, $lines, 'the header and the one customer entry');
        self::assertSame(['2026-03-04 11:00:00', 'Clerk', 'customer.revised', 'customer', $customer->toRfc4122(), 'email'], str_getcsv($lines[1], escape: ''), 'the moment in the company\'s own time');
        self::assertStringNotContainsString('198.51.100.7', $lines[1]);
        self::assertStringNotContainsString('secret@client.tn', $lines[1]);
    }

    public function testWithoutAuditReadTheCompanyHasNoJournal(): void
    {
        $this->createUser('seller@twes.local', 'password-1234', $this->company, ['company.read', 'invoice.read'], 'vendeur');
        $this->login('seller@twes.local', 'password-1234');

        $this->client->request('GET', $this->path(), server: ['HTTP_ACCEPT' => 'application/ld+json']);

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testTheBuiltInAdministratorReadsTheJournal(): void
    {
        self::assertContains('audit.read', SeedPlatform::BUILT_IN_ROLES[Role::ADMIN]);
        self::assertNotContains('audit.read', SeedPlatform::BUILT_IN_ROLES[Role::MEMBER]);
        self::assertNotContains('audit.read', SeedPlatform::BUILT_IN_ROLES[Role::CLERK]);
    }

    private function path(): string
    {
        return '/api/companies/'.$this->company->getId()->toRfc4122().'/activity';
    }

    /**
     * @param array<string, mixed> $changes
     * @param Company|false|null   $company false for the company under test, null for a row of no company (before a sign-in)
     */
    private function entry(string $type, ?Uuid $id, string $action, ?User $actor, array $changes, string $when, Company|false|null $company = false, ?string $ip = null): void
    {
        $owner = false === $company ? $this->company : $company;
        $this->em()->persist(new AuditLog($type, $id, $action, $actor?->getId(), $changes, new \DateTimeImmutable($when), $ip, $owner?->getId()));
        $this->em()->flush();
    }
}
