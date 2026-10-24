<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Texts\Domain\ValueObject;

/**
 * Where a shipped text lives: its language, its file and its dotted key. A screen text's file is `screen` (the main
 * one) or `screen:<part>` (a page's own, whose top-level names repeat the main file's); an API text's is its domain.
 */
final readonly class TextAddress
{
    public const string SCREEN = 'screen';

    public function __construct(public string $language, public string $domain, public string $key)
    {
    }

    public function syntax(): TextSyntax
    {
        return self::SCREEN === $this->domain || str_starts_with($this->domain, self::SCREEN.':') ? TextSyntax::Screen : TextSyntax::Api;
    }
}
