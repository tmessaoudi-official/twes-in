<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Legal\Domain\ValueObject;

/**
 * A language a legal page is written in: a canonical BCP 47 tag. Which tags the pages are written in, and what a page
 * missing in one is read in instead, is the language catalogue's to say (`Application\LegalLanguages`), never this
 * type's. Compare two with `equals`: two of the same tag are not the same object.
 */
final readonly class LegalLanguage
{
    /** Language in lower case, then a script in title case, then a region in capitals or digits; a route's requirement too. */
    public const string PATTERN = '[a-z]{2,3}(?:-[A-Z][a-z]{3})?(?:-(?:[A-Z]{2}|[0-9]{3}))?';

    private function __construct(public string $value)
    {
    }

    public static function of(string $tag): self
    {
        if (1 !== preg_match('/^'.self::PATTERN.'$/D', $tag)) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not a canonical BCP 47 language tag.', $tag));
        }

        return new self($tag);
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
