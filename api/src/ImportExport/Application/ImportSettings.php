<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\ImportExport\Application;

use App\Settings\Application\DeclaresSettings;
use App\Settings\Domain\ValueObject\SettingChain;
use App\Settings\Domain\ValueObject\SettingDefinition;
use App\Settings\Domain\ValueObject\SettingLevel;
use App\Settings\Domain\ValueObject\SettingType;

/**
 * How many rows one imported file may hold, header excluded, which the platform's operators set: they know what their
 * machine bears, a shop does not. A file is one unit of work run inside its request, so the ceiling is what one request
 * was measured to bear within app.long_request.max_seconds: 5,000 product rows took 35 s on a developer's machine.
 *
 * A company's own two, each read by the import guide and changeable for one file on the screen: the encoding a CSV is
 * read in first (« auto » finds it on the file, as before), for a shop whose Excel always writes the same one; and
 * whether the next file of a subject starts from the pairing of its last import (true, as before), which a shop
 * receiving files from several sources turns off.
 */
final readonly class ImportSettings implements DeclaresSettings
{
    public const string MODULE = 'core';
    public const string MAX_ROWS = 'import.max_rows';
    public const int CEILING = 5000;
    public const string CSV_ENCODING = 'import.csv_encoding';
    /** The encoding found on the file itself, as a request naming none is read. */
    public const string AUTOMATIC = 'auto';
    public const string REUSE_LAST_PAIRING = 'import.reuse_last_pairing';

    public function settings(): iterable
    {
        yield new SettingDefinition(self::MAX_ROWS, SettingType::Int, self::CEILING, SettingChain::Platform, [SettingLevel::Platform], 'settings.platform.import_max_rows', self::MODULE, min: 1, max: self::CEILING);
        $company = [SettingLevel::Company];
        // Keys and choices written out for the label gate, which reads them as written; ImportSettingsTest holds them to the
        // constants and the choices to TextEncoding.
        yield new SettingDefinition('import.csv_encoding', SettingType::Enum, self::AUTOMATIC, SettingChain::Parties, $company, 'settings.import.csv_encoding', self::MODULE, choices: ['auto', 'utf-8', 'windows-1252', 'windows-1256', 'utf-16']);
        yield new SettingDefinition('import.reuse_last_pairing', SettingType::Bool, true, SettingChain::Parties, $company, 'settings.import.reuse_last_pairing', self::MODULE);
    }
}
