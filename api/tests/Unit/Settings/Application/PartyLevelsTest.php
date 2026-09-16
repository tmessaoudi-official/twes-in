<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Settings\Application;

use App\Settings\Application\BusinessDefaultSettings;
use App\Settings\Application\ChangeSettings;
use App\Settings\Application\ForgetSettings;
use App\Settings\Application\ResolveSettings;
use App\Settings\Application\SettingCatalog;
use App\Settings\Application\SettingContext;
use App\Settings\Application\SettingLevelRefused;
use App\Settings\Domain\Setting;
use App\Settings\Domain\SettingAddress;
use App\Settings\Domain\SettingLevel;
use App\Tenancy\Domain\Company;
use App\Tests\Support\FakeTransactions;
use App\Tests\Support\InMemoryAuditTrail;
use App\Tests\Support\InMemorySettings;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

/** The parties chain below the company: what a customer group says, then what a customer says (docs/SPEC.md § 3 Settings). */
final class PartyLevelsTest extends TestCase
{
    private const string TERMS = 'document.payment_terms_days';

    private InMemorySettings $settings;
    private ResolveSettings $resolve;
    private ChangeSettings $change;
    private Company $company;
    private Uuid $groupId;
    private Uuid $customerId;

    protected function setUp(): void
    {
        $this->settings = new InMemorySettings();
        $catalog = new SettingCatalog([new BusinessDefaultSettings()]);
        $this->resolve = new ResolveSettings($catalog, $this->settings);
        $this->change = new ChangeSettings($catalog, $this->settings, $this->resolve, new InMemoryAuditTrail($transactions = new FakeTransactions()), new MockClock('2026-09-14 09:00:00'), $transactions);
        $this->company = new Company('Acme', 'TN', 'TND', 'fr', 'Africa/Tunis');
        $this->groupId = Uuid::v7();
        $this->customerId = Uuid::v7();
    }

    public function testACustomerInheritsItsGroupsTermsAndMayOverrideThem(): void
    {
        $group = new SettingContext($this->company, customerGroupId: $this->groupId);
        $this->change->change($group, self::TERMS, SettingLevel::CustomerGroup, 45, null);

        $customer = new SettingContext($this->company, customerGroupId: $this->groupId, customerId: $this->customerId);
        $inherited = $this->resolve->one($customer, self::TERMS);
        self::assertSame([45, SettingLevel::CustomerGroup], [$inherited->value, $inherited->source]);

        $own = $this->change->change($customer, self::TERMS, SettingLevel::Customer, 10, null);
        self::assertSame([10, SettingLevel::Customer], [$own->value, $own->source]);
        self::assertSame(['customer_group' => 45, 'customer' => 10], $own->explicit);

        $ungrouped = new SettingContext($this->company, customerId: Uuid::v7());
        self::assertSame(30, $this->resolve->one($ungrouped, self::TERMS)->value);
    }

    public function testAGroupsValueStaysInItsCompany(): void
    {
        $this->settings->save(new Setting(SettingAddress::customerGroup($this->company, $this->groupId), self::TERMS, 45, new \DateTimeImmutable()));

        $elsewhere = new SettingContext(new Company('Globex', 'TN', 'TND', 'fr', 'Africa/Tunis'), customerGroupId: $this->groupId);

        self::assertSame(30, $this->resolve->one($elsewhere, self::TERMS)->value);
    }

    public function testALevelWithoutItsSubjectIsRefused(): void
    {
        $this->expectException(SettingLevelRefused::class);
        $this->change->change(new SettingContext($this->company, customerGroupId: $this->groupId), self::TERMS, SettingLevel::Customer, 10, null);
    }

    public function testForgettingASubjectRemovesEveryValueStoredForIt(): void
    {
        $group = new SettingContext($this->company, customerGroupId: $this->groupId);
        $this->change->change($group, self::TERMS, SettingLevel::CustomerGroup, 45, null);
        $this->change->change($group, 'document.language', SettingLevel::CustomerGroup, 'en', null);
        $this->change->change($group, self::TERMS, SettingLevel::Company, 60, null);

        (new ForgetSettings($this->settings))->at(SettingAddress::customerGroup($this->company, $this->groupId));

        self::assertCount(1, $this->settings->settings);
        self::assertSame(60, $this->resolve->one($group, self::TERMS)->value);
    }
}
