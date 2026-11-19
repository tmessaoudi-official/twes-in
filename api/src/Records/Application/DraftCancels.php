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
use Symfony\Component\Uid\Uuid;

/**
 * Which drafts do not offer their cancel, as the company chose (DraftCancelSettings): none by default; where it offers a
 * draft's cancel only once its delete would be refused, those nothing names. Read once for a whole page. Apart from the
 * declaration, which the catalog builds and so cannot read it.
 */
final readonly class DraftCancels
{
    public function __construct(private ReadSetting $settings, private RecordUses $uses)
    {
    }

    /**
     * @param list<Uuid> $drafts one company's drafts of the subject
     *
     * @return array<string, true> by id, the drafts whose cancel is not offered
     */
    public function notOffered(Company $company, string $subject, array $drafts): array
    {
        // The setting first, whatever the page holds: a page costs the same statements with or without a draft on it.
        if (DraftCancelSettings::WHEN_DELETE_REFUSED !== $this->settings->text(new SettingContext($company), DraftCancelSettings::OFFER) || [] === $drafts) {
            return [];
        }
        $used = $this->uses->of($company->getId(), $subject, $drafts);
        $alone = [];
        foreach ($drafts as $draft) {
            if (!isset($used[$draft->toRfc4122()])) {
                $alone[$draft->toRfc4122()] = true;
            }
        }

        return $alone;
    }
}
