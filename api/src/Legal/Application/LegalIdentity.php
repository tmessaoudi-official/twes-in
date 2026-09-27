<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Legal\Application;

use App\Settings\Application\ReadSetting;
use App\Settings\Application\SettingContext;

/**
 * The publisher's and host's identity as the legal pages show it (`LegalSettings`): each filled-in fact by the
 * placeholder a text names it with, `{{publisher.name}}` for `legal.publisher.name`. What is not filled in is not
 * there, and the page shows « à compléter » in its place, so a missing fact is visible rather than silently absent.
 */
final readonly class LegalIdentity
{
    public function __construct(private ReadSetting $settings)
    {
    }

    /** @return array<string, string> */
    public function values(): array
    {
        $values = [];
        foreach (LegalSettings::PLACEHOLDERS as $placeholder) {
            $value = $this->settings->value(new SettingContext(), LegalSettings::PREFIX.$placeholder);
            if (\is_string($value) && '' !== trim($value)) {
                $values[$placeholder] = trim($value);
            }
        }

        return $values;
    }
}
