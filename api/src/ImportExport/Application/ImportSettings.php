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
 */
final readonly class ImportSettings implements DeclaresSettings
{
    public const string MODULE = 'core';
    public const string MAX_ROWS = 'import.max_rows';
    public const int CEILING = 5000;

    public function settings(): iterable
    {
        yield new SettingDefinition(self::MAX_ROWS, SettingType::Int, self::CEILING, SettingChain::Platform, [SettingLevel::Platform], 'settings.platform.import_max_rows', self::MODULE, min: 1, max: self::CEILING);
    }
}
