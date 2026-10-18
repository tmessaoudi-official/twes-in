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
use App\Settings\Domain\Model\Setting;
use App\Settings\Domain\ValueObject\SettingAddress;
use App\Tenancy\Domain\Model\Company;
use App\Tests\Support\InMemorySettings;
use PHPUnit\Framework\TestCase;

final class ReadSettingTest extends TestCase
{
    public function testCodeReadsTheValueInForce(): void
    {
        $settings = new InMemorySettings();
        $company = new Company('Acme', 'TN', 'TND', 'fr', 'Africa/Tunis');
        $settings->save(new Setting(SettingAddress::company($company), 'presentation.scheme', 'dark', new \DateTimeImmutable()));
        $read = new ReadSetting(new ResolveSettings(new SettingCatalog([new PresentationSettings(\App\Tests\Support\ShippedLanguages::catalogue())]), $settings));

        self::assertSame('dark', $read->value(new SettingContext($company), 'presentation.scheme'));
    }

    /** A text setting always holds text, its declared default when nobody chose: anything else is a bug, never a fallback. */
    public function testATextSettingIsReadAsTextAndAnotherIsRefused(): void
    {
        $read = new ReadSetting(new ResolveSettings(new SettingCatalog([new PresentationSettings(\App\Tests\Support\ShippedLanguages::catalogue())]), new InMemorySettings()));

        self::assertSame(\App\Tests\Support\ShippedLanguages::catalogue()->default(), $read->text(new SettingContext(), PresentationSettings::LANGUAGE));
        $this->expectException(\UnexpectedValueException::class);
        $read->text(new SettingContext(), 'presentation.show-coming');
    }

    public function testCodeCannotReadAKeyNobodyDeclared(): void
    {
        $read = new ReadSetting(new ResolveSettings(new SettingCatalog([new PresentationSettings(\App\Tests\Support\ShippedLanguages::catalogue())]), new InMemorySettings()));

        $this->expectException(UnknownSetting::class);
        $read->value(new SettingContext(), 'document.hardcoded_somewhere');
    }
}
