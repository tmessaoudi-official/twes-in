<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\ImportExport\Application;

use App\ImportExport\Application\ImportSettings;
use App\Settings\Domain\ValueObject\SettingChain;
use App\Settings\Domain\ValueObject\SettingDefinition;
use App\Settings\Domain\ValueObject\SettingLevel;
use App\Shared\Application\Spreadsheet\TextEncoding;
use PHPUnit\Framework\TestCase;

/**
 * A company's import defaults are what a shop saw before they existed: the encoding found on each file, and the next
 * file starting from the last pairing. Only the owner's choice at the company changes them.
 */
final class ImportSettingsTest extends TestCase
{
    public function testTheDefaultsAreWhatAnImportDidBefore(): void
    {
        $settings = $this->settings();

        self::assertSame('auto', $settings[ImportSettings::CSV_ENCODING]->default);
        self::assertTrue($settings[ImportSettings::REUSE_LAST_PAIRING]->default);
        foreach ([ImportSettings::CSV_ENCODING, ImportSettings::REUSE_LAST_PAIRING] as $key) {
            self::assertSame(SettingChain::Parties, $settings[$key]->chain, $key);
            self::assertSame([SettingLevel::Company], $settings[$key]->overridableAt, $key.' is the company\'s alone');
        }
    }

    /** The choices are written out for the label gate; they are automatic and every encoding a CSV may be read in. */
    public function testTheEncodingsOfferedAreAutomaticAndEveryOneAFileMayBeReadIn(): void
    {
        self::assertSame(
            [ImportSettings::AUTOMATIC, ...array_map(static fn (TextEncoding $encoding): string => $encoding->value, TextEncoding::cases())],
            $this->settings()[ImportSettings::CSV_ENCODING]->choices,
        );
    }

    /** @return array<string, SettingDefinition> */
    private function settings(): array
    {
        $settings = [];
        foreach ((new ImportSettings())->settings() as $definition) {
            $settings[$definition->key] = $definition;
        }

        return $settings;
    }
}
