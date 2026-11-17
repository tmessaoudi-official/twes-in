<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Records\Application;

use App\Settings\Application\ReadSetting;
use App\Settings\Application\SettingContext;
use App\Tenancy\Domain\Model\Company;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * A company's undo window as its deletes, merges and imports keep it, and as its session says it. Apart from
 * RecordsUndoSettings, which declares the setting: a declaration the catalog builds cannot itself read the catalog.
 */
final readonly class RecordsUndoWindow
{
    public function __construct(
        private ReadSetting $settings,
        #[Autowire(param: 'app.records.undoable_for')]
        private string $operatorWindow,
    ) {
    }

    /** The company's window, as a delete, a merge or an import keeps it. */
    public function undoableFor(Company $company): \DateInterval
    {
        return new \DateInterval('PT'.$this->seconds($company).'S');
    }

    /** The same in seconds, as the screens say it. */
    public function seconds(Company $company): int
    {
        return RecordsUndoSettings::secondsOf($this->settings->integer(new SettingContext($company), RecordsUndoSettings::UNDOABLE_FOR), $this->operatorWindow);
    }
}
