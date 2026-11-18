<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Reports\Application;

use App\Settings\Application\DeclaresSettings;
use App\Settings\Application\ReadSetting;
use App\Settings\Application\SettingContext;
use App\Settings\Domain\ValueObject\SettingChain;
use App\Settings\Domain\ValueObject\SettingDefinition;
use App\Settings\Domain\ValueObject\SettingLevel;
use App\Settings\Domain\ValueObject\SettingType;
use App\Tenancy\Domain\Model\Company;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * How a report's file is headed: by its columns' keys, as a spreadsheet's formulas or another program read them and as
 * by default, or by the words the report's screen shows, which a person reads. Only a report's file: a list's file is
 * headed by the keys its import matches, so a list read out can come back in. Declared after the undo window, so the
 * settings page draws it on a row of its own.
 */
#[AsTaggedItem(priority: -220)]
final readonly class ReportExportSettings implements DeclaresSettings
{
    public const string HEADERS = 'reports.export_headers';
    public const string KEYS = 'keys';
    public const string WORDS = 'words';

    public function settings(): iterable
    {
        // The key and its choices written out, as the setting-labels gate reads them.
        yield new SettingDefinition('reports.export_headers', SettingType::Enum, 'keys', SettingChain::Parties, [SettingLevel::Company], 'settings.reports.export_headers', ReportCatalogue::MODULE, choices: ['keys', 'words']);
    }

    /** Whether the company's report files are headed in the screen's words. */
    public static function inWords(ReadSetting $settings, Company $company): bool
    {
        return self::WORDS === $settings->text(new SettingContext($company), self::HEADERS);
    }
}
