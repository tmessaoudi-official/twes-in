<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Settings\Application;

use App\Settings\Application\ChangeSettings;
use App\Settings\Application\InvalidSettingValue;
use App\Settings\Application\PresentationSettings;
use App\Settings\Application\ResolveSettings;
use App\Settings\Application\SettingCatalog;
use App\Settings\Application\SettingContext;
use App\Settings\Application\SettingLevelRefused;
use App\Settings\Application\UnknownSetting;
use App\Settings\Domain\SettingLevel;
use App\Tenancy\Domain\Company;
use App\Tests\Support\FakeTransactions;
use App\Tests\Support\InMemoryAuditTrail;
use App\Tests\Support\InMemorySettings;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

final class ChangeSettingsTest extends TestCase
{
    private InMemorySettings $settings;
    private InMemoryAuditTrail $audit;
    private ChangeSettings $change;
    private Company $company;
    private SettingContext $context;
    private Uuid $userId;

    protected function setUp(): void
    {
        $this->settings = new InMemorySettings();
        $transactions = new FakeTransactions();
        $this->audit = new InMemoryAuditTrail($transactions);
        $catalog = new SettingCatalog([new PresentationSettings()]);
        $this->change = new ChangeSettings($catalog, $this->settings, new ResolveSettings($catalog, $this->settings), $this->audit, new MockClock('2026-09-14 09:00:00'), $transactions);
        $this->company = new Company('Acme', 'TN', 'TND', 'fr', 'Africa/Tunis');
        $this->userId = Uuid::v7();
        $this->context = new SettingContext($this->company, Uuid::v7(), $this->userId);
    }

    public function testAUserChoosesADensityForThemselvesWithoutAnAuditEntry(): void
    {
        $density = $this->change->change($this->context, 'presentation.density', SettingLevel::User, 'compact', $this->userId);

        self::assertSame('compact', $density->value);
        self::assertSame(SettingLevel::User, $density->source);
        self::assertCount(1, $this->settings->settings);
        self::assertSame([], $this->audit->entries);
    }

    public function testChangingAgainRevisesTheSameRow(): void
    {
        $this->change->change($this->context, 'presentation.density', SettingLevel::User, 'compact', $this->userId);
        $density = $this->change->change($this->context, 'presentation.density', SettingLevel::User, 'comfortable', $this->userId);

        self::assertSame('comfortable', $density->value);
        self::assertCount(1, $this->settings->settings);
    }

    public function testACompanyDefaultIsNormalisedAndAudited(): void
    {
        $accent = $this->change->change($this->context, 'presentation.accent', SettingLevel::Company, '#A0B1C2', $this->userId);

        self::assertSame('#a0b1c2', $accent->value);
        self::assertCount(1, $this->audit->entries);
        $entry = $this->audit->entries[0];
        self::assertSame('setting.changed', $entry->action);
        self::assertTrue($this->company->getId()->equals($entry->companyId));
        self::assertSame(['key' => 'presentation.accent', 'level' => 'company', 'value' => '#a0b1c2'], $entry->changes);
    }

    public function testAnUnknownSettingIsRefused(): void
    {
        $this->expectException(UnknownSetting::class);
        $this->change->change($this->context, 'presentation.invented', SettingLevel::User, 'x', $this->userId);
    }

    public function testALevelTheSettingDoesNotAllowIsRefused(): void
    {
        $this->expectException(SettingLevelRefused::class);
        $this->change->change($this->context, 'presentation.list.members', SettingLevel::Company, ['hidden' => []], $this->userId);
    }

    public function testALevelOutsideTheSettingsChainIsRefused(): void
    {
        $this->expectException(SettingLevelRefused::class);
        $this->change->change($this->context, 'presentation.density', SettingLevel::Customer, 'compact', $this->userId);
    }

    public function testALevelTheContextCannotAddressIsRefused(): void
    {
        $withoutRole = new SettingContext($this->company, null, $this->userId);

        $this->expectException(SettingLevelRefused::class);
        $this->change->change($withoutRole, 'presentation.density', SettingLevel::Role, 'compact', $this->userId);
    }

    public function testAValueOfTheWrongShapeIsRefusedAndNothingIsStored(): void
    {
        try {
            $this->change->change($this->context, 'presentation.density', SettingLevel::User, 'cosy', $this->userId);
            self::fail('A density nobody declared was accepted.');
        } catch (InvalidSettingValue) {
            self::assertSame([], $this->settings->settings);
        }
    }

    public function testAResetFallsBackToTheNextLevel(): void
    {
        $this->change->change($this->context, 'presentation.density', SettingLevel::Company, 'compact', $this->userId);
        $this->change->change($this->context, 'presentation.density', SettingLevel::User, 'comfortable', $this->userId);

        $density = $this->change->reset($this->context, 'presentation.density', SettingLevel::User, $this->userId);

        self::assertSame('compact', $density->value);
        self::assertSame(SettingLevel::Company, $density->source);
        self::assertCount(1, $this->settings->settings);
    }

    public function testResettingWhatWasNeverChosenChangesNothing(): void
    {
        $density = $this->change->reset($this->context, 'presentation.density', SettingLevel::Company, $this->userId);

        self::assertSame('comfortable', $density->value);
        self::assertSame([], $this->audit->entries);
    }

    public function testAResetOfACompanyDefaultIsAudited(): void
    {
        $this->change->change($this->context, 'presentation.scheme', SettingLevel::Company, 'dark', $this->userId);

        $this->change->reset($this->context, 'presentation.scheme', SettingLevel::Company, $this->userId);

        self::assertSame(['setting.changed', 'setting.reset'], array_map(static fn ($entry) => $entry->action, $this->audit->entries));
        self::assertSame([], $this->settings->settings);
    }
}
