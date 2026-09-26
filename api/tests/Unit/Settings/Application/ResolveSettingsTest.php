<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Settings\Application;

use App\Settings\Application\PresentationSettings;
use App\Settings\Application\ResolvedSetting;
use App\Settings\Application\ResolveSettings;
use App\Settings\Application\SettingCatalog;
use App\Settings\Application\SettingContext;
use App\Settings\Application\UnknownSetting;
use App\Settings\Domain\Setting;
use App\Settings\Domain\SettingAddress;
use App\Settings\Domain\SettingChain;
use App\Settings\Domain\SettingLevel;
use App\Tenancy\Domain\Company;
use App\Tests\Support\InMemorySettings;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class ResolveSettingsTest extends TestCase
{
    private InMemorySettings $settings;
    private ResolveSettings $resolve;
    private Company $company;
    private Uuid $roleId;
    private Uuid $userId;

    protected function setUp(): void
    {
        $this->settings = new InMemorySettings();
        $this->resolve = new ResolveSettings(new SettingCatalog([new PresentationSettings()]), $this->settings);
        $this->company = new Company('Acme', 'TN', 'TND', 'fr', 'Africa/Tunis');
        $this->roleId = Uuid::v7();
        $this->userId = Uuid::v7();
    }

    public function testNothingChosenAnswersTheDeclaredDefault(): void
    {
        $density = $this->find('presentation.density');

        self::assertSame('comfortable', $density->value);
        self::assertNull($density->source);
        self::assertSame([], $density->explicit);
    }

    public function testTheMostSpecificLevelWins(): void
    {
        $this->store(SettingAddress::company($this->company), 'presentation.density', 'compact');
        self::assertSame('compact', $this->find('presentation.density')->value);

        $this->store(SettingAddress::user($this->userId), 'presentation.density', 'comfortable');
        $density = $this->find('presentation.density');

        self::assertSame('comfortable', $density->value);
        self::assertSame(SettingLevel::User, $density->source);
        self::assertSame(['company' => 'compact', 'user' => 'comfortable'], $density->explicit);
    }

    public function testARoleValueReachesThatRoleInThatCompanyOnly(): void
    {
        $this->store(SettingAddress::role($this->company, $this->roleId), 'presentation.scheme', 'dark');

        self::assertSame('dark', $this->find('presentation.scheme')->value);
        self::assertSame(SettingLevel::Role, $this->find('presentation.scheme')->source);
        $elsewhere = new SettingContext(new Company('Globex', 'TN', 'TND', 'fr', 'Africa/Tunis'), $this->roleId, $this->userId);
        self::assertSame('auto', $this->find('presentation.scheme', $elsewhere)->value);
        $anotherRole = new SettingContext($this->company, Uuid::v7(), $this->userId);
        self::assertSame('auto', $this->find('presentation.scheme', $anotherRole)->value);
    }

    public function testAUserPreferenceFollowsTheUserIntoEveryCompany(): void
    {
        $this->store(SettingAddress::user($this->userId), 'presentation.density', 'compact');

        $elsewhere = new SettingContext(new Company('Globex', 'TN', 'TND', 'fr', 'Africa/Tunis'), null, $this->userId);
        self::assertSame('compact', $this->find('presentation.density', $elsewhere)->value);
    }

    public function testAStoredValueTheSettingNoLongerAcceptsIsIgnored(): void
    {
        $this->store(SettingAddress::company($this->company), 'presentation.density', 'compact');
        $this->store(SettingAddress::user($this->userId), 'presentation.density', 'cosy');

        $density = $this->find('presentation.density');
        self::assertSame('compact', $density->value);
        self::assertSame(['company' => 'compact'], $density->explicit);
    }

    public function testEveryListLayoutOfTheUserIsResolvedUnderItsOwnKey(): void
    {
        $layout = ['hidden' => ['email'], 'order' => [], 'widths' => [], 'sort' => null];
        $this->store(SettingAddress::user($this->userId), 'presentation.list.members', $layout);
        $this->store(SettingAddress::user($this->userId), 'presentation.list.members.views', []);
        // Neither a layout at a level lists do not allow nor a setting nobody declares any more is answered.
        $this->store(SettingAddress::company($this->company), 'presentation.list.customers', $layout);
        $this->store(SettingAddress::user($this->userId), 'presentation.retired', 'x');

        $resolved = $this->resolve->handle(SettingChain::Presentation, $this->context());

        $keys = array_map(static fn (ResolvedSetting $setting) => $setting->key, $resolved);
        self::assertSame(
            ['presentation.accent', 'presentation.scheme', 'presentation.density', 'presentation.sidebar', 'presentation.sidebar-settings', 'presentation.plan-labels', 'presentation.language', 'presentation.customer-view.cost', 'presentation.customer-view.supplier-codes', 'presentation.show-coming', 'presentation.shortcuts', 'presentation.date-format', 'presentation.number-format', 'presentation.folded-sections', 'presentation.list.members', 'presentation.list.members.views'],
            $keys,
        );
        // Read by key and not by position: what this case is about is the layout arriving under its own key, and an
        // ordinal would make every future presentation setting shift an assertion that has nothing to do with it.
        self::assertSame($layout, $resolved[array_search('presentation.list.members', $keys, true)]->value);
    }

    public function testASettingOutsideTheCatalogueCannotBeResolved(): void
    {
        $this->expectException(UnknownSetting::class);
        $this->resolve->one($this->context(), 'presentation.invented');
    }

    private function context(): SettingContext
    {
        return new SettingContext($this->company, $this->roleId, $this->userId);
    }

    private function find(string $key, ?SettingContext $context = null): ResolvedSetting
    {
        return $this->resolve->one($context ?? $this->context(), $key);
    }

    private function store(SettingAddress $address, string $key, mixed $value): void
    {
        $this->settings->save(new Setting($address, $key, $value, new \DateTimeImmutable('2026-09-14 09:00:00')));
    }
}
