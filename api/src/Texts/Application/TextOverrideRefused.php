<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Texts\Application;

use App\Texts\Domain\ValueObject\TextAddress;
use App\Texts\Domain\ValueObject\TextRefusal;

/**
 * A text not stored, and why, as a stable `reason` the screen words: `unknown_text` (no text at that address),
 * `language` (a language the screens do not speak), `locked` (a text the law fixes), or one of {@see TextRefusal}.
 */
final class TextOverrideRefused extends \DomainException
{
    public const string UNKNOWN_TEXT = 'unknown_text';
    public const string LANGUAGE = 'language';
    public const string LOCKED = 'locked';

    public function __construct(public readonly string $reason, TextAddress $address)
    {
        parent::__construct(\sprintf('The text %s of %s in %s is refused: %s.', $address->key, $address->domain, $address->language, $reason));
    }

    public static function because(TextRefusal $refusal, TextAddress $address): self
    {
        return new self($refusal->value, $address);
    }
}
