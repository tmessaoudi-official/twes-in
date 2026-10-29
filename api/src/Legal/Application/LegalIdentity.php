<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Legal\Application;

use App\Settings\Application\ReadSetting;
use App\Settings\Application\SettingContext;
use App\Shared\Application\Phones\PhoneNumbers;
use App\Shared\Domain\Service\TypedText;

/**
 * The publisher's and host's identity as the legal pages show it (`LegalSettings`): each filled-in fact by the
 * placeholder a text names it with, `{{publisher.name}}` for `legal.publisher.name`. What is not filled in is not
 * there, and the page shows « à compléter » in its place, so a missing fact is visible rather than silently absent.
 */
final readonly class LegalIdentity
{
    public function __construct(private ReadSetting $settings, private PhoneNumbers $phones)
    {
    }

    /** @return array<string, string> a phone grouped as its country writes it, never the E.164 it is kept as */
    public function values(): array
    {
        $values = [];
        foreach (LegalSettings::PLACEHOLDERS as $placeholder) {
            $value = $this->settings->value(new SettingContext(), LegalSettings::PREFIX.$placeholder);
            if (\is_string($value) && '' !== TypedText::trimmed($value)) {
                $value = TypedText::trimmed($value);
                $values[$placeholder] = str_ends_with($placeholder, '.phone') ? $this->phones->grouped($value) : $value;
            }
        }

        return $values;
    }
}
