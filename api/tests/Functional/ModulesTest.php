<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\CustomFields\Infrastructure\ApiPlatform\CustomFieldResource;
use App\Fiscal\Application\Regime\SyncCustomerTaxRegimes;
use App\Fiscal\Domain\CustomerTaxRegimeRepository;
use App\Module\Customers\Domain\Customer;
use App\Module\Customers\Domain\CustomerGroup;
use App\Module\Customers\Domain\CustomerKind;
use App\Module\Customers\Domain\CustomerProfile;
use App\Module\Customers\Infrastructure\ApiPlatform\ContactResource;
use App\Module\Customers\Infrastructure\ApiPlatform\CustomerGroupResource;
use App\Module\Customers\Infrastructure\ApiPlatform\CustomerOptionsResource;
use App\Module\Customers\Infrastructure\ApiPlatform\CustomerResource;
use App\ModuleRegistry\Domain\ModuleState;
use App\ModuleRegistry\Infrastructure\ApiPlatform\ModuleOwnership;
use App\Tenancy\Domain\Company;
use Symfony\Component\HttpFoundation\Response;

/**
 * The module registry through the API (docs/SPEC.md § 3 Modules). Customers, products, delivery notes and invoices are
 * the real modules, the last two needing the first two; the test environment also declares `fixture_ledger`, which needs
 * customers, so a dependency refusal is exercised on a module nothing else touches.
 */
final class ModulesTest extends ApiTestCase
{
    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = $this->createCompany('Acme');
    }

    public function testAMemberSeesEveryModuleOnUntilTheCompanySwitchesOne(): void
    {
        $this->signedIn(['company.read']);

        $this->getJson($this->path());

        self::assertResponseIsSuccessful();
        self::assertSame([
            ['key' => 'customers', 'labelKey' => 'modules.customers', 'dependencies' => [], 'permissions' => ['customer.read', 'customer.write'], 'enabled' => true],
            ['key' => 'delivery_notes', 'labelKey' => 'modules.delivery_notes', 'dependencies' => ['customers', 'products'], 'permissions' => ['delivery_note.read', 'delivery_note.write', 'delivery_note.validate'], 'enabled' => true],
            ['key' => 'fixture_ledger', 'labelKey' => 'modules.fixture_ledger', 'dependencies' => ['customers'], 'permissions' => [], 'enabled' => true],
            ['key' => 'invoices', 'labelKey' => 'modules.invoices', 'dependencies' => ['customers', 'products'], 'permissions' => ['invoice.read', 'invoice.write', 'invoice.issue'], 'enabled' => true],
            ['key' => 'products', 'labelKey' => 'modules.products', 'dependencies' => [], 'permissions' => ['product.read', 'product.write'], 'enabled' => true],
        ], $this->jsonList());
        $this->getJson('/api/auth/me');
        self::assertSame(['customers', 'delivery_notes', 'fixture_ledger', 'invoices', 'products'], $this->arrayAt($this->json(), 'modules'));
    }

    public function testSwitchingAModuleOffHidesItsResourcesAndKeepsItsData(): void
    {
        static::getContainer()->get(SyncCustomerTaxRegimes::class)->handle();
        $regime = static::getContainer()->get(CustomerTaxRegimeRepository::class)->ofPresetAndCode('TN', 'standard');
        self::assertNotNull($regime);
        $group = CustomerGroup::create($this->company, 'Grossistes', null, new \DateTimeImmutable());
        $this->em()->persist($group);
        $customer = Customer::create($this->company, 'CLI-0001', new CustomerProfile(CustomerKind::Individual, 'Amel'), $group, $regime, [], new \DateTimeImmutable());
        $this->em()->persist($customer);
        $this->em()->flush();
        $this->signedIn(['company.read', 'company.settings', 'customer.read', 'customer.write']);
        $customerId = $customer->getId()->toRfc4122();
        $reads = [
            '/customers',
            '/customers/'.$customerId,
            '/customers/'.$customerId.'/contacts',
            '/customer-groups',
            '/customer-options',
            '/settings?chain=parties&customerGroupId='.$group->getId()->toRfc4122(),
            '/settings?chain=parties&customerId='.$customerId,
        ];
        foreach ($reads as $read) {
            $this->getJson($this->companyPath().$read);
            self::assertResponseIsSuccessful("$read before");
        }

        $this->sendJson('PUT', $this->path('delivery_notes'), ['enabled' => false]);
        self::assertResponseIsSuccessful();
        $this->sendJson('PUT', $this->path('invoices'), ['enabled' => false]);
        self::assertResponseIsSuccessful();
        $this->sendJson('PUT', $this->path('fixture_ledger'), ['enabled' => false]);
        self::assertResponseIsSuccessful();
        $this->sendJson('PUT', $this->path('customers'), ['enabled' => false]);

        self::assertResponseIsSuccessful();
        self::assertSame(['key' => 'customers', 'enabled' => false], array_intersect_key($this->json(), ['key' => 0, 'enabled' => 0]));
        foreach ($reads as $read) {
            $this->getJson($this->companyPath().$read);
            self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND, "$read after");
        }
        $this->postJson($this->companyPath().'/customer-groups', ['name' => 'Export', 'description' => null]);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        $this->getJson('/api/auth/me');
        self::assertSame(['products'], $this->arrayAt($this->json(), 'modules'), 'products needs no customers and stays on');
        self::assertSame(['module.disabled', 'module.disabled', 'module.disabled', 'module.disabled'], $this->em()->getConnection()->fetchFirstColumn("SELECT action FROM audit_log WHERE entity_type = 'module'"));

        $this->sendJson('PUT', $this->path('customers'), ['enabled' => true]);
        self::assertResponseIsSuccessful();
        $this->getJson($this->companyPath().'/customer-groups');
        self::assertResponseIsSuccessful();
        self::assertSame(['Grossistes'], array_column($this->jsonList(), 'name'), 'a module switched off keeps its data');
    }

    public function testAModuleAnEnabledModuleNeedsStaysOnAndComesBackOnlyAfterWhatItNeeds(): void
    {
        $this->signedIn(['company.read', 'company.settings']);

        $this->sendJson('PUT', $this->path('customers'), ['enabled' => false]);
        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        self::assertStringContainsString('fixture_ledger', (string) $this->client->getResponse()->getContent());
        self::assertStringContainsString('delivery_notes', (string) $this->client->getResponse()->getContent());
        self::assertStringContainsString('invoices', (string) $this->client->getResponse()->getContent());

        $this->sendJson('PUT', $this->path('fixture_ledger'), ['enabled' => false]);
        $this->sendJson('PUT', $this->path('delivery_notes'), ['enabled' => false]);
        $this->sendJson('PUT', $this->path('invoices'), ['enabled' => false]);
        $this->sendJson('PUT', $this->path('customers'), ['enabled' => false]);
        self::assertResponseIsSuccessful();

        $this->sendJson('PUT', $this->path('fixture_ledger'), ['enabled' => true]);
        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        self::assertStringContainsString('needs these modules on first: customers', (string) $this->client->getResponse()->getContent());

        $this->sendJson('PUT', $this->path('customers'), ['enabled' => true]);
        $this->sendJson('PUT', $this->path('fixture_ledger'), ['enabled' => true]);
        self::assertResponseIsSuccessful();
    }

    public function testOnlyWhoMayChangeTheCompanysSettingsSwitchesAModule(): void
    {
        $this->signedIn(['company.read', 'customer.read']);

        $this->sendJson('PUT', $this->path('fixture_ledger'), ['enabled' => false]);

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        self::assertEquals(0, $this->em()->getConnection()->fetchOne('SELECT count(*) FROM module_state'));
    }

    public function testAnotherCompanysModulesAreNotFound(): void
    {
        $globex = $this->createCompany('Globex');
        $this->signedIn(['*']);
        $theirs = '/api/companies/'.$globex->getId()->toRfc4122().'/modules';

        $this->getJson($theirs);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        $this->sendJson('PUT', $theirs.'/fixture_ledger', ['enabled' => false]);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testAnUndeclaredModuleIsNotFoundAndASwitchSaysOnOrOff(): void
    {
        $this->signedIn(['company.read', 'company.settings']);

        $this->sendJson('PUT', $this->path('vendors'), ['enabled' => true]);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);

        $this->sendJson('PUT', $this->path('fixture_ledger'), ['enabled' => null]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertStringContainsString('enabled', (string) $this->client->getResponse()->getContent());
        $this->sendJson('PUT', $this->path('fixture_ledger'), ['enabled' => 'no']);
        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
    }

    public function testAnAnonymousCallerIsAskedToSignInWhateverTheCompanysModules(): void
    {
        $this->em()->persist(ModuleState::of($this->company, 'customers', false, new \DateTimeImmutable()));
        $this->em()->flush();

        $this->getJson($this->companyPath().'/customers');
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        $this->sendJson('PUT', $this->path('customers'), ['enabled' => true]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testEveryResourceUnderAModuleDirectoryBelongsToThatModule(): void
    {
        $ownership = static::getContainer()->get(ModuleOwnership::class);

        foreach ([CustomerResource::class, CustomerGroupResource::class, ContactResource::class, CustomerOptionsResource::class] as $resource) {
            self::assertSame('customers', $ownership->ownerOf($resource), $resource);
        }
        foreach ([
            \App\Module\Products\Infrastructure\ApiPlatform\ProductResource::class,
            \App\Module\Products\Infrastructure\ApiPlatform\ProductCategoryResource::class,
            \App\Module\Products\Infrastructure\ApiPlatform\ProductOptionsResource::class,
        ] as $resource) {
            self::assertSame('products', $ownership->ownerOf($resource), $resource);
        }
        self::assertNull($ownership->ownerOf(CustomFieldResource::class), 'the core belongs to no module');
        $directories = glob(\dirname(__DIR__, 2).'/src/Module/*', \GLOB_ONLYDIR) ?: [];
        self::assertNotEmpty($directories);
        foreach ($directories as $directory) {
            self::assertNotNull($ownership->ownerOf('App\\Module\\'.basename($directory).'\\Infrastructure\\ApiPlatform\\SomeResource'), basename($directory).' declares its module');
        }
    }

    public function testSwitchingProductsOffHidesProductsCategoriesAndTheirOptions(): void
    {
        $this->signedIn(['company.read', 'company.settings', 'product.read', 'product.write']);
        $this->postJson($this->companyPath().'/product-categories', ['name' => 'Matériel']);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $categorySettings = '/settings?chain=articles&productCategoryId='.$this->stringAt($this->json(), 'id');
        $this->getJson($this->companyPath().$categorySettings);
        self::assertResponseIsSuccessful();

        $this->sendJson('PUT', $this->path('products'), ['enabled' => false]);
        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT, 'delivery notes need products');
        $this->sendJson('PUT', $this->path('delivery_notes'), ['enabled' => false]);
        $this->sendJson('PUT', $this->path('invoices'), ['enabled' => false]);
        $this->sendJson('PUT', $this->path('products'), ['enabled' => false]);
        self::assertResponseIsSuccessful();

        foreach (['/products', '/product-categories', '/product-options', $categorySettings] as $hidden) {
            $this->getJson($this->companyPath().$hidden);
            self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND, $hidden);
        }
        $this->postJson($this->companyPath().'/product-categories', ['name' => 'Logiciel']);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        $this->getJson('/api/auth/me');
        self::assertSame(['customers', 'fixture_ledger'], $this->arrayAt($this->json(), 'modules'));

        $this->sendJson('PUT', $this->path('products'), ['enabled' => true]);
        $this->getJson($this->companyPath().'/product-categories');
        self::assertSame(['Matériel'], array_column($this->jsonList(), 'name'), 'the data was kept');
    }

    private function companyPath(): string
    {
        return '/api/companies/'.$this->company->getId()->toRfc4122();
    }

    private function path(?string $key = null): string
    {
        return $this->companyPath().'/modules'.(null === $key ? '' : '/'.$key);
    }

    /** @param list<string> $permissions */
    private function signedIn(array $permissions): void
    {
        $this->createUser('admin@twes.local', 'password-1234', $this->company, $permissions, 'member');
        $this->login('admin@twes.local', 'password-1234');
        self::assertResponseIsSuccessful();
    }
}
