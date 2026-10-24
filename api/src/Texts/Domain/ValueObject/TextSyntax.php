<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Texts\Domain\ValueObject;

/**
 * How a text's code-filled parts are written: the screens' `{{name}}` and `{count, plural, …}` choices, which
 * ngx-translate and the web's PluralCompiler read, or the API's `%name%`, which Symfony's translator fills.
 */
enum TextSyntax: string
{
    case Screen = 'screen';
    case Api = 'api';
}
