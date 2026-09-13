<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Settings\Application;

use App\Settings\Application\PresentationSettings;
use App\Settings\Application\ReadSetting;
use App\Settings\Application\ResolveSettings;
use App\Settings\Application\SettingCatalog;
use App\Settings\Application\SettingContext;
use App\Settings\Application\UnknownSetting;
use App\Settings\Domain\Setting;
use App\Settings\Domain\SettingAddress;
use App\Tenancy\Domain\Company;
use App\Tests\Support\InMemorySettings;
use PHPUnit\Framework\TestCase;

final class ReadSettingTest extends TestCase
{
    public function testCodeReadsTheValueInForce(): void
    {
        $settings = new InMemorySettings();
        $company = new Company('Acme', 'TN', 'TND', 'fr', 'Africa/Tunis');
        $settings->save(new Setting(SettingAddress::company($company), 'presentation.scheme', 'dark', new \DateTimeImmutable()));
        $read = new ReadSetting(new ResolveSettings(new SettingCatalog([new PresentationSettings()]), $settings));

        self::assertSame('dark', $read->value(new SettingContext($company), 'presentation.scheme'));
    }

    public function testCodeCannotReadAKeyNobodyDeclared(): void
    {
        $read = new ReadSetting(new ResolveSettings(new SettingCatalog([new PresentationSettings()]), new InMemorySettings()));

        $this->expectException(UnknownSetting::class);
        $read->value(new SettingContext(), 'document.hardcoded_somewhere');
    }
}
