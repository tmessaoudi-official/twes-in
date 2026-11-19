<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Records\Application;

use App\Settings\Application\DeclaresSettings;
use App\Settings\Domain\ValueObject\SettingChain;
use App\Settings\Domain\ValueObject\SettingDefinition;
use App\Settings\Domain\ValueObject\SettingLevel;
use App\Settings\Domain\ValueObject\SettingType;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * When a draft invoice, quote, delivery note or purchase order offers its cancel beside its delete: always, as by
 * default, or only once its delete would be refused, another record naming it, so a draft nothing names offers the delete
 * alone. Only what is offered changes: the delete and the cancel each stay what they are, and what is no draft any more
 * keeps its cancel. DraftCancels reads it. Declared among the parties' last settings, after how screens name a merged
 * vendor (-200) and before the undo window (-210), on a row of its own.
 */
#[AsTaggedItem(priority: -207)]
final readonly class DraftCancelSettings implements DeclaresSettings
{
    public const string OFFER = 'document.drafts_offer_cancel';
    public const string ALWAYS = 'always';
    public const string WHEN_DELETE_REFUSED = 'when_delete_refused';
    /** Not a module: each document's module is, and a draft's delete is the records' own. */
    private const string MODULE = 'core';

    public function settings(): iterable
    {
        // The key and its choices written out, as the setting-labels gate reads them.
        yield new SettingDefinition('document.drafts_offer_cancel', SettingType::Enum, 'always', SettingChain::Parties, [SettingLevel::Company], 'settings.document.drafts_offer_cancel', self::MODULE, choices: ['always', 'when_delete_refused']);
    }
}
