<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Fiscal\Application\Regime\SyncCustomerTaxRegimes;
use App\Tenancy\Domain\Company;
use Symfony\Component\HttpFoundation\Response;

/**
 * A company's custom fields: declared by whoever may change its settings, read by every member so screens can render
 * them, and checked on every customer written.
 */
final class CustomFieldsTest extends ApiTestCase
{
    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = $this->createCompany('Acme');
        static::getContainer()->get(SyncCustomerTaxRegimes::class)->handle();
    }

    public function testASettingsWriterDeclaresAndRevisesAField(): void
    {
        $this->signedIn(['company.read', 'company.settings']);

        $this->sendJson('POST', $this->path(), self::sector());
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $created = $this->json();
        self::assertSame(['sector', 'choice', ['retail', 'wholesale'], true, true], [$created['key'], $created['type'], $created['choices'], $created['required'], $created['isActive']]);

        $this->sendJson('PUT', $this->path(self::id($created)), [...self::sector(), 'label' => 'Activité', 'isActive' => false]);
        self::assertResponseIsSuccessful();
        self::assertSame(['Activité', false], [$this->json()['label'], $this->json()['isActive']]);

        $this->getJson($this->path().'?entity=customer');
        self::assertSame(['sector'], array_column($this->jsonList(), 'key'));
        self::assertEquals(1, $this->em()->getConnection()->fetchOne("SELECT count(*) FROM audit_log WHERE action = 'custom_field.revised'"));
    }

    public function testAMemberReadsTheFieldsButDeclaresNone(): void
    {
        $this->signedIn(['company.read', 'customer.read']);

        $this->getJson($this->path().'?entity=customer');
        self::assertResponseIsSuccessful();
        self::assertSame([], $this->jsonList());

        $this->getJson($this->path().'?entity=robot');
        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);

        $this->sendJson('POST', $this->path(), self::sector());
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testAKeyTakenAMalformedFieldAndAChangedKeyAreRefused(): void
    {
        $this->signedIn(['company.read', 'company.settings']);
        $this->sendJson('POST', $this->path(), self::sector());
        $id = self::id($this->json());

        $this->sendJson('POST', $this->path(), self::sector());
        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);

        $this->sendJson('POST', $this->path(), [...self::sector(), 'key' => 'segment', 'choices' => []]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertStringContainsString('choices', (string) $this->client->getResponse()->getContent());

        $this->sendJson('POST', $this->path(), [...self::sector(), 'key' => 'segment', 'choices' => ['first' => 'retail']]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, 'choices are a list, never a map');
        self::assertStringContainsString('choices', (string) $this->client->getResponse()->getContent());

        $this->sendJson('PUT', $this->path($id), [...self::sector(), 'key' => 'segment']);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function testAnotherCompanysFieldIsNotFound(): void
    {
        // Every account exists before the first request: the test client reboots the kernel, detaching these companies.
        $globex = $this->createCompany('Globex');
        $this->createUser('boss@globex.test', 'password-1234', $globex, ['company.read', 'company.settings'], 'owner');
        $this->createUser('admin@twes.local', 'password-1234', $this->company, ['company.read', 'company.settings'], 'member');
        $this->login('boss@globex.test', 'password-1234');
        $this->sendJson('POST', '/api/companies/'.$globex->getId()->toRfc4122().'/custom-fields', self::sector());
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $theirs = self::id($this->json());
        $this->sendJson('POST', '/api/auth/logout', []);

        $this->login('admin@twes.local', 'password-1234');
        $this->sendJson('PUT', $this->path($theirs), self::sector());

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testACustomersValuesAreCheckedAgainstItsCompanysFields(): void
    {
        $this->signedIn(['company.read', 'company.settings', 'customer.read', 'customer.write']);
        $this->sendJson('POST', $this->path(), self::sector());
        $sector = self::id($this->json());
        $customers = '/api/companies/'.$this->company->getId()->toRfc4122().'/customers';

        $this->sendJson('POST', $customers, self::customer([]));
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertStringContainsString('customFields.sector', (string) $this->client->getResponse()->getContent());

        $this->sendJson('POST', $customers, self::customer(['sector' => 'retail', 'colour' => 'blue']));
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertStringContainsString('customFields.colour', (string) $this->client->getResponse()->getContent());

        $this->sendJson('POST', $customers, self::customer(['sector' => 'retail']));
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $customer = $this->json();
        self::assertSame(['sector' => 'retail'], $customer['customFields']);

        $this->sendJson('PUT', $this->path($sector), [...self::sector(), 'isActive' => false]);
        $this->sendJson('PUT', $customers.'/'.self::id($customer), self::customer([]));
        self::assertResponseIsSuccessful();
        self::assertSame(['sector' => 'retail'], $this->json()['customFields'], 'a retired field keeps what the customer holds');
    }

    /** @return array<string, mixed> */
    private static function sector(): array
    {
        return ['entity' => 'customer', 'key' => 'sector', 'label' => 'Secteur', 'type' => 'choice', 'required' => true, 'choices' => ['retail', 'wholesale'], 'sortOrder' => 0, 'isActive' => true];
    }

    /**
     * @param array<string, mixed> $customFields
     *
     * @return array<string, mixed>
     */
    private static function customer(array $customFields): array
    {
        return ['number' => 'CLI-0001', 'kind' => 'individual', 'name' => 'Amel', 'taxRegime' => 'standard', 'customFields' => (object) $customFields];
    }

    /** @param array<string, mixed> $row */
    private static function id(array $row): string
    {
        $id = $row['id'] ?? null;
        self::assertIsString($id);

        return $id;
    }

    /** @param list<string> $permissions */
    private function signedIn(array $permissions): void
    {
        $this->createUser('admin@twes.local', 'password-1234', $this->company, $permissions, 'member');
        $this->login('admin@twes.local', 'password-1234');
        self::assertResponseIsSuccessful();
    }

    private function path(?string $id = null): string
    {
        return '/api/companies/'.$this->company->getId()->toRfc4122().'/custom-fields'.(null === $id ? '' : '/'.$id);
    }
}
