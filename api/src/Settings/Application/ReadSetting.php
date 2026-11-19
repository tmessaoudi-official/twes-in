<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Settings\Application;

/**
 * How code reads a business value that belongs in a setting: by a declared key, for a context. A key literal
 * passed here is checked against the catalogue by tests/Architecture/RegisteredSettingsTest.
 */
final readonly class ReadSetting
{
    public function __construct(private ResolveSettings $resolve)
    {
    }

    /** @throws UnknownSetting */
    public function value(SettingContext $context, string $key): mixed
    {
        return $this->resolve->one($context, $key)->value;
    }

    /**
     * A setting declared as a whole number, which always holds one: its declared default when nobody chose one.
     *
     * @throws UnknownSetting
     * @throws \UnexpectedValueException when the key is not a whole-number setting, which is a bug in the caller
     */
    public function integer(SettingContext $context, string $key): int
    {
        $value = $this->value($context, $key);
        if (!\is_int($value)) {
            throw new \UnexpectedValueException(\sprintf('%s holds %s, not a whole number.', $key, get_debug_type($value)));
        }

        return $value;
    }

    /**
     * A setting declared as text, which always holds text: its declared default when nobody chose one.
     *
     * @throws UnknownSetting
     * @throws \UnexpectedValueException when the key is not a text setting, which is a bug in the caller
     */
    public function text(SettingContext $context, string $key): string
    {
        $value = $this->value($context, $key);
        if (!\is_string($value)) {
            throw new \UnexpectedValueException(\sprintf('%s holds %s, not text.', $key, get_debug_type($value)));
        }

        return $value;
    }

    /**
     * A setting declared as several choices, which always holds a list of them, none included: its declared default
     * when nobody chose.
     *
     * @return list<string>
     *
     * @throws UnknownSetting
     * @throws \UnexpectedValueException when the key is not a setting of several choices, which is a bug in the caller
     */
    public function choices(SettingContext $context, string $key): array
    {
        $value = $this->value($context, $key);
        if (!\is_array($value)) {
            throw new \UnexpectedValueException(\sprintf('%s holds %s, not a list of choices.', $key, get_debug_type($value)));
        }
        $choices = [];
        foreach ($value as $one) {
            if (!\is_string($one)) {
                throw new \UnexpectedValueException(\sprintf('%s holds %s among its choices.', $key, get_debug_type($one)));
            }
            $choices[] = $one;
        }

        return $choices;
    }
}
