<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\DeliveryNotes\Application;

use App\Settings\Application\DeclaresSettings;
use App\Settings\Domain\SettingChain;
use App\Settings\Domain\SettingDefinition;
use App\Settings\Domain\SettingLevel;
use App\Settings\Domain\SettingType;

/**
 * The delivery notes module's settings, in the parties chain, so a customer group, a customer or a note can differ from
 * its company.
 */
final readonly class DeliveryNoteSettings implements DeclaresSettings
{
    /** The module's key, as DeliveryNotesModule declares it. */
    private const string MODULE = 'delivery_notes';

    public function settings(): iterable
    {
        $parties = [SettingLevel::Company, SettingLevel::CustomerGroup, SettingLevel::Customer, SettingLevel::Document];

        yield new SettingDefinition('delivery_note.show_prices', SettingType::Bool, true, SettingChain::Parties, $parties, 'settings.delivery_note.show_prices', self::MODULE);
    }
}
