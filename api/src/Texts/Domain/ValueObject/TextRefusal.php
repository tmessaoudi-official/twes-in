<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Texts\Domain\ValueObject;

/**
 * Why a changed text cannot replace the one shipped. The value is the reason the API answers and the screen
 * translates, never a sentence of its own.
 */
enum TextRefusal: string
{
    case Empty = 'empty';
    case TooLong = 'too_long';
    /** A `{{name}}` or `%name%` the code fills in is lost, renamed or added. */
    case Placeholders = 'placeholders';
    /** A plural choice is lost, made on another count, without « other », with a form no language has, or unclosed. */
    case Plural = 'plural';
}
