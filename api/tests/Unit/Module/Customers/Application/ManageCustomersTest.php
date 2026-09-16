<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Module\Customers\Application;

use App\CustomFields\Domain\CustomFieldDefinition;
use App\CustomFields\Domain\CustomFieldEntity;
use App\CustomFields\Domain\CustomFieldType;
use App\Fiscal\Application\Company\ProvisionCompany;
use App\Fiscal\Application\Regime\SyncCustomerTaxRegimes;
use App\Module\Customers\Application\CustomerInput;
use App\Module\Customers\Application\CustomerNotFound;
use App\Module\Customers\Application\CustomerNumberTaken;
use App\Module\Customers\Application\ManageCustomers;
use App\Module\Customers\Domain\CustomerGroup;
use App\Module\Customers\Domain\CustomerKind;
use App\Module\Customers\Domain\CustomerProfile;
use App\Module\Customers\Domain\InvalidCustomer;
use App\Shared\Domain\PostalAddress;
use App\Tenancy\Domain\Company;
use App\Tests\Support\FakeTransactions;
use App\Tests\Support\InMemoryAuditTrail;
use App\Tests\Support\InMemoryCustomerGroups;
use App\Tests\Support\InMemoryCustomers;
use App\Tests\Support\InMemoryCustomerTaxRegimes;
use App\Tests\Support\InMemoryCustomFieldDefinitions;
use App\Tests\Support\InMemoryEstablishments;
use App\Tests\Support\InMemoryNumberingSeries;
use App\Tests\Support\InMemoryTaxComponents;
use App\Tests\Support\InMemoryUnits;
use App\Tests\Support\ShippedFiscalPresets;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

final class ManageCustomersTest extends TestCase
{
    private const string MATRICULE = '1234567A/B/M/000';

    private InMemoryCustomerGroups $groups;
    private InMemoryTaxComponents $taxes;
    private InMemoryAuditTrail $audit;
    private InMemoryCustomFieldDefinitions $fields;
    private ManageCustomers $manage;
    private Company $company;
    private Company $globex;

    protected function setUp(): void
    {
        $clock = new MockClock('2026-09-14 09:00:00');
        $regimes = new InMemoryCustomerTaxRegimes();
        (new SyncCustomerTaxRegimes(ShippedFiscalPresets::presets(), $regimes, $clock))->handle();
        $this->taxes = new InMemoryTaxComponents();
        $provision = new ProvisionCompany(ShippedFiscalPresets::presets(), $this->taxes, new InMemoryUnits(), new InMemoryEstablishments(), new InMemoryNumberingSeries(), ShippedFiscalPresets::scales(), $clock);
        $this->groups = new InMemoryCustomerGroups();
        $transactions = new FakeTransactions();
        $this->audit = new InMemoryAuditTrail($transactions);
        $this->fields = new InMemoryCustomFieldDefinitions();
        $this->manage = new ManageCustomers(new InMemoryCustomers(), $this->groups, $regimes, $this->taxes, ShippedFiscalPresets::presets(), $this->audit, $clock, $this->fields, $transactions);
        $this->company = new Company('Acme', 'TN', 'TND', 'fr', 'Africa/Tunis');
        $this->globex = new Company('Globex', 'TN', 'TND', 'fr', 'Africa/Tunis');
        $provision->handle($this->company);
        $provision->handle($this->globex);
    }

    public function testADomesticBusinessCustomerCarriesTheIdentifiersItsPresetRequires(): void
    {
        $this->assertRefused('identifiers.matricule_fiscal', fn () => $this->manage->create($this->company, self::input(), null));
        $this->assertRefused('identifiers.matricule_fiscal', fn () => $this->manage->create($this->company, self::input(identifiers: ['matricule_fiscal' => '1234567']), null));

        $customer = $this->manage->create($this->company, self::input(identifiers: ['matricule_fiscal' => self::MATRICULE]), Uuid::v7());

        self::assertSame([$customer], $this->manage->list($this->company));
        self::assertSame('standard', $customer->getTaxRegime()->getCode());
        self::assertSame([ManageCustomers::ENTITY_TYPE, ManageCustomers::CREATED, []], [$this->audit->entries[0]->entityType, $this->audit->entries[0]->action, $this->audit->entries[0]->changes]);
    }

    public function testAForeignBusinessOrAPrivateIndividualNeedsNoIdentifier(): void
    {
        $this->manage->create($this->company, self::input(number: 'EXP-1', country: 'FR', regime: 'export'), null);
        $this->manage->create($this->company, self::input(number: 'IND-1', kind: CustomerKind::Individual), null);

        self::assertCount(2, $this->manage->list($this->company));
    }

    public function testANumberAnotherCustomerOfTheCompanyHasIsRefused(): void
    {
        $this->manage->create($this->globex, self::input(identifiers: ['matricule_fiscal' => self::MATRICULE]), null);
        $this->manage->create($this->company, self::input(identifiers: ['matricule_fiscal' => self::MATRICULE]), null);
        $other = $this->manage->create($this->company, self::input(number: 'CLI-0002', kind: CustomerKind::Individual), null);

        try {
            $this->manage->revise($this->company, $other->getId(), self::input(number: 'CLI-0001', kind: CustomerKind::Individual), null);
            self::fail('A customer took the number of another.');
        } catch (CustomerNumberTaken) {
        }
        $this->expectException(CustomerNumberTaken::class);
        $this->manage->create($this->company, self::input(kind: CustomerKind::Individual), null);
    }

    public function testTheRegimeIsOneTheCompanysPresetOffersCustomers(): void
    {
        $this->assertRefused('taxRegime', fn () => $this->manage->create($this->company, self::input(kind: CustomerKind::Individual, regime: 'franchise'), null));
    }

    public function testTheGroupIsOneOfTheCompanysOwn(): void
    {
        $theirs = CustomerGroup::create($this->globex, 'Grossistes', null, new \DateTimeImmutable());
        $this->groups->save($theirs);
        $mine = CustomerGroup::create($this->company, 'Grossistes', null, new \DateTimeImmutable());
        $this->groups->save($mine);

        $this->assertRefused('customerGroupId', fn () => $this->manage->create($this->company, self::input(kind: CustomerKind::Individual, groupId: $theirs->getId()), null));
        $this->assertRefused('customerGroupId', fn () => $this->manage->create($this->company, self::input(kind: CustomerKind::Individual, groupId: Uuid::v7()), null));

        self::assertSame($mine, $this->manage->create($this->company, self::input(kind: CustomerKind::Individual, groupId: $mine->getId()), null)->getGroup());
    }

    public function testDefaultTaxesAreActiveTaxesOfTheCompanyThatTheRegimeCharges(): void
    {
        $vat = $this->taxes->ofCodeInCompany('TVA19', $this->company->getId());
        $fodec = $this->taxes->ofCodeInCompany('FODEC', $this->company->getId());
        $theirVat = $this->taxes->ofCodeInCompany('TVA19', $this->globex->getId());
        self::assertNotNull($vat);
        self::assertNotNull($fodec);
        self::assertNotNull($theirVat);

        $this->assertRefused('defaultTaxComponentIds', fn () => $this->manage->create($this->company, self::input(kind: CustomerKind::Individual, taxes: [$theirVat->getId()]), null));
        $this->assertRefused('defaultTaxComponentIds', fn () => $this->manage->create($this->company, self::input(kind: CustomerKind::Individual, regime: 'exempt', taxes: [$fodec->getId(), $vat->getId()]), null));

        $exempt = $this->manage->create($this->company, self::input(kind: CustomerKind::Individual, regime: 'exempt', taxes: [$fodec->getId()]), null);
        self::assertSame([$fodec->getId()->toRfc4122()], $exempt->getDefaultTaxComponentIds());
    }

    public function testADeactivatedTaxIsNoLongerADefault(): void
    {
        $vat13 = $this->taxes->ofCodeInCompany('TVA13', $this->company->getId());
        self::assertNotNull($vat13);
        $vat13->revise($vat13->getName(), $vat13->getRate(), $vat13->getAmount(), $vat13->getThreshold(), $vat13->entersVatBase(), $vat13->isDefault(), false, $vat13->getExemptionMention(), $vat13->getSortOrder(), 3, new \DateTimeImmutable());

        $this->assertRefused('defaultTaxComponentIds', fn () => $this->manage->create($this->company, self::input(kind: CustomerKind::Individual, taxes: [$vat13->getId()]), null));
    }

    public function testARevisionIsAuditedWithTheNamesOfTheFieldsItChangedAndNotAtAllWhenNothingChanged(): void
    {
        $customer = $this->manage->create($this->company, self::input(kind: CustomerKind::Individual), null);

        $this->manage->revise($this->company, $customer->getId(), self::input(kind: CustomerKind::Individual), null);
        self::assertCount(1, $this->audit->entries);

        $this->manage->revise($this->company, $customer->getId(), self::input(kind: CustomerKind::Individual, email: 'amel@example.tn', active: false), null);
        self::assertSame([ManageCustomers::REVISED, ['fields' => ['email', 'isActive']]], [$this->audit->entries[1]->action, $this->audit->entries[1]->changes]);
        self::assertFalse($this->manage->get($this->company, $customer->getId())->isActive());
    }

    public function testAnotherCompanysCustomerIsNotFound(): void
    {
        $theirs = $this->manage->create($this->globex, self::input(kind: CustomerKind::Individual), null);

        try {
            $this->manage->get($this->company, $theirs->getId());
            self::fail("Another company's customer was read.");
        } catch (CustomerNotFound) {
        }
        $this->expectException(CustomerNotFound::class);
        $this->manage->revise($this->company, $theirs->getId(), self::input(kind: CustomerKind::Individual), null);
    }

    public function testCustomFieldValuesAreCheckedAgainstTheCompanysFieldsAndKeptWhenAFieldRetires(): void
    {
        $sector = CustomFieldDefinition::create($this->company, CustomFieldEntity::Customer, 'sector', 'Secteur', CustomFieldType::Choice, true, ['retail', 'wholesale'], 0, new \DateTimeImmutable());
        $this->fields->save($sector);
        $this->fields->save(CustomFieldDefinition::create($this->globex, CustomFieldEntity::Customer, 'region', 'Région', CustomFieldType::Text, true, [], 0, new \DateTimeImmutable()));

        $this->assertRefused('customFields.sector', fn () => $this->manage->create($this->company, self::input(kind: CustomerKind::Individual), null));
        $this->assertRefused('customFields.region', fn () => $this->manage->create($this->company, self::input(kind: CustomerKind::Individual, customFields: ['sector' => 'retail', 'region' => 'Nord']), null));

        $customer = $this->manage->create($this->company, self::input(kind: CustomerKind::Individual, customFields: ['sector' => 'retail']), null);
        self::assertSame(['sector' => 'retail'], $customer->getCustomFields());

        $this->manage->revise($this->company, $customer->getId(), self::input(kind: CustomerKind::Individual, customFields: ['sector' => 'wholesale']), null);
        self::assertSame(['fields' => ['customFields.sector']], $this->audit->entries[1]->changes);

        $sector->revise('Secteur', true, ['retail', 'wholesale'], 0, false, new \DateTimeImmutable());
        $this->manage->revise($this->company, $customer->getId(), self::input(kind: CustomerKind::Individual), null);
        self::assertSame(['sector' => 'wholesale'], $this->manage->get($this->company, $customer->getId())->getCustomFields());
        self::assertCount(2, $this->audit->entries, 'carrying a retired value over changes nothing');
    }

    /** @param callable(): mixed $attempt */
    private function assertRefused(string $field, callable $attempt): void
    {
        try {
            $attempt();
            self::fail("Nothing refused $field.");
        } catch (InvalidCustomer $refused) {
            self::assertSame($field, $refused->field, $refused->getMessage());
        }
    }

    /**
     * @param array<string, string> $identifiers
     * @param list<Uuid>            $taxes
     * @param array<string, mixed>  $customFields
     */
    private static function input(
        string $number = 'CLI-0001',
        CustomerKind $kind = CustomerKind::Company,
        array $identifiers = [],
        string $country = 'TN',
        string $regime = 'standard',
        ?Uuid $groupId = null,
        array $taxes = [],
        ?string $email = null,
        bool $active = true,
        array $customFields = [],
    ): CustomerInput {
        return new CustomerInput(
            $number,
            new CustomerProfile($kind, 'Carthage Conseil', identifiers: $identifiers, email: $email, billingAddress: new PostalAddress(city: 'Tunis', countryCode: $country)),
            $groupId,
            $regime,
            $taxes,
            $active,
            $customFields,
        );
    }
}
