<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Legal\Application;

use App\Legal\Domain\ValueObject\LegalLanguage;
use App\Shared\Application\Languages;

/** The languages the legal pages are written in, as the language catalogue marks them. */
final readonly class LegalLanguages
{
    public function __construct(private Languages $languages)
    {
    }

    /** @return list<LegalLanguage> in the catalogue's order */
    public function all(): array
    {
        return array_map(LegalLanguage::of(...), $this->languages->legal());
    }

    /** The language a tag names, or null for anything the pages are not written in. */
    public function known(mixed $tag): ?LegalLanguage
    {
        return \is_string($tag) && \in_array($tag, $this->languages->legal(), true) ? LegalLanguage::of($tag) : null;
    }

    /**
     * @return list<LegalLanguage> the language itself, then what a page missing in it is read in instead: the
     *                             interface's languages the pages are written in, the default first
     */
    public function readIn(LegalLanguage $language): array
    {
        $instead = array_values(array_filter(
            $this->languages->interface(),
            fn (string $tag): bool => $tag !== $language->value && \in_array($tag, $this->languages->legal(), true),
        ));

        return [$language, ...array_map(LegalLanguage::of(...), $instead)];
    }
}
