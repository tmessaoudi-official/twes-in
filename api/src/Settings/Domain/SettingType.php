<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Settings\Domain;

/**
 * What a setting's value is, which is also how the settings page renders it. Decimals and money travel as strings,
 * like every amount in the API. A file arrives with file storage at G9.
 */
enum SettingType: string
{
    case Bool = 'bool';
    case Int = 'int';
    case Decimal = 'decimal';
    case Text = 'text';
    case Enum = 'enum';
    case Money = 'money';
    case Colour = 'colour';
    case Json = 'json';
}
