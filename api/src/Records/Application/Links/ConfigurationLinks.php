<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Records\Application\Links;

use App\Records\Application\RecordReference;
use App\Records\Application\RecordUse;
use App\Settings\Application\ReadSetting;
use App\Settings\Application\SettingContext;
use App\Tenancy\Domain\Model\Company;

/**
 * Whether a company's deletes empty the links that only set something up rather than be refused by them, as it chose
 * (ConfigurationLinkSettings). Apart from the declaration, which the catalog builds and so cannot read it.
 */
final readonly class ConfigurationLinks
{
    public function __construct(private ReadSetting $settings)
    {
    }

    public function cleared(Company $company): bool
    {
        return ConfigurationLinkSettings::CLEAR === $this->settings->text(new SettingContext($company), ConfigurationLinkSettings::LINKS);
    }

    /**
     * What makes a record used for this company: a reference that uses it, and one that only sets something up unless
     * the company empties those.
     *
     * @param list<RecordReference> $naming
     *
     * @return list<RecordReference>
     */
    public function using(Company $company, array $naming): array
    {
        $cleared = $this->cleared($company);

        return array_values(array_filter($naming, static fn (RecordReference $reference): bool => RecordUse::Uses === $reference->onDelete || (RecordUse::Cleared === $reference->onDelete && !$cleared)));
    }
}
