<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\CustomFields\Domain\CustomFieldDefinition;
use App\CustomFields\Domain\CustomFieldEntity;
use App\CustomFields\Domain\CustomFieldType;
use App\Fiscal\Application\Company\ProvisionCompany;
use App\Fiscal\Application\Regime\SyncCustomerTaxRegimes;
use App\ModuleRegistry\Domain\ModuleState;
use App\Shared\Application\Spreadsheet\SpreadsheetFormat;
use App\Shared\Infrastructure\Spreadsheet\OpenSpoutReader;
use App\Tenancy\Application\Seed\SeedPlatform;
use App\Tenancy\Domain\Company;
use App\Tenancy\Domain\Role;
use Symfony\Component\HttpFoundation\Response;

/** The empty file a person fills in to import customers (docs/SPEC.md § 7, 2026-09-17). */
final class ImportTemplatesTest extends ApiTestCase
{
    private const array FIXED = ['number', 'kind', 'name', 'legal_name', 'email', 'phone', 'website', 'billing_line1', 'billing_line2', 'billing_postal_code', 'billing_city', 'billing_country_code', 'shipping_line1', 'shipping_line2', 'shipping_postal_code', 'shipping_city', 'shipping_country_code', 'customer_group', 'tax_regime_code', 'default_tax_codes', 'default_discount_rate', 'notes', 'active'];

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        static::getContainer()->get(SyncCustomerTaxRegimes::class)->handle();
        $this->company = $this->createCompany('Acme');
        static::getContainer()->get(ProvisionCompany::class)->handle($this->company);
    }

    public function testTheCsvTemplateIsOneHeaderRowOfTheCompanysOwnColumns(): void
    {
        $now = new \DateTimeImmutable();
        $em = $this->em();
        $em->persist(CustomFieldDefinition::create($this->company, CustomFieldEntity::Customer, 'sector', 'Secteur', CustomFieldType::Text, false, [], 1, $now));
        $off = CustomFieldDefinition::create($this->company, CustomFieldEntity::Customer, 'legacy', 'Ancien code', CustomFieldType::Text, false, [], 2, $now);
        $off->revise('Ancien code', false, [], 2, false, $now);
        $em->persist($off);
        $em->persist(CustomFieldDefinition::create($this->company, CustomFieldEntity::Product, 'shelf', 'Rayon', CustomFieldType::Text, false, [], 1, $now));
        $em->flush();
        $this->signedIn(['customer.read', 'customer.write']);

        $this->client->request('GET', $this->path('customers.csv'));

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('content-disposition', 'attachment; filename=customers.csv');
        self::assertStringStartsWith('text/csv', (string) $this->client->getResponse()->headers->get('content-type'));
        $rows = $this->rows((string) $this->client->getResponse()->getContent(), SpreadsheetFormat::Csv);
        self::assertSame([[...self::FIXED, 'matricule_fiscal', 'custom.sector']], $rows, 'the preset’s identifier and the active customer field, not the switched-off one nor a product’s; no example row');
    }

    public function testTheXlsxTemplateHoldsTheSameHeader(): void
    {
        $this->signedIn(['customer.read', 'customer.write']);

        $this->client->request('GET', $this->path('customers.xlsx'));

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        self::assertSame([[...self::FIXED, 'matricule_fiscal']], $this->rows((string) $this->client->getResponse()->getContent(), SpreadsheetFormat::Xlsx));
    }

    public function testSomebodyWhoCannotWriteCustomersIsAnsweredAsAStranger(): void
    {
        $this->signedIn(['customer.read']);

        $this->client->request('GET', $this->path('customers.csv'));

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testTheBuiltInMemberAndAdminRolesAreOfferedIt(): void
    {
        foreach ([Role::MEMBER, Role::ADMIN] as $role) {
            $this->createUser($role.'@twes.local', 'password-1234', $this->company, SeedPlatform::BUILT_IN_ROLES[$role], $role);
        }
        $this->login(Role::MEMBER.'@twes.local', 'password-1234');
        $this->client->request('GET', $this->path('customers.csv'));
        self::assertResponseIsSuccessful();
        $this->postJson('/api/auth/logout', null);
        $this->login(Role::ADMIN.'@twes.local', 'password-1234');

        $this->client->request('GET', $this->path('customers.csv'));

        self::assertResponseIsSuccessful();
    }

    public function testNothingElseIsImportable(): void
    {
        $this->signedIn(['customer.read', 'customer.write']);

        $this->client->request('GET', $this->path('spaceships.csv'));

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testASwitchedOffModuleOffersNoTemplate(): void
    {
        $this->em()->persist(ModuleState::of($this->company, 'customers', false, new \DateTimeImmutable()));
        $this->em()->flush();
        $this->signedIn(['customer.read', 'customer.write']);

        $this->client->request('GET', $this->path('customers.csv'));

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    /** @param list<string> $permissions */
    private function signedIn(array $permissions): void
    {
        $this->createUser('sales@twes.local', 'password-1234', $this->company, $permissions, 'member');
        $this->login('sales@twes.local', 'password-1234');
        self::assertResponseIsSuccessful();
    }

    private function path(string $file): string
    {
        return '/api/companies/'.$this->company->getId()->toRfc4122().'/import-templates/'.$file;
    }

    /** @return list<list<string>> */
    private function rows(string $contents, SpreadsheetFormat $format): array
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'template-test-');
        file_put_contents($path, $contents);
        try {
            return array_values(array_map(static fn (array $row): array => $row, iterator_to_array((new OpenSpoutReader())->rows($path, $format))));
        } finally {
            unlink($path);
        }
    }
}
