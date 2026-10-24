<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Texts\Application\Catalogue;

use App\Shared\Domain\Service\TypedText;
use App\Tenancy\Domain\Model\Company;
use App\Texts\Application\ShippedTexts;
use App\Texts\Domain\Model\TextOverride;
use App\Texts\Domain\Repository\TextOverrideRepository;
use App\Texts\Domain\Service\TextLocks;
use App\Texts\Domain\ValueObject\TextAddress;

/**
 * Every text a writer may word otherwise, in a language: the installation's writer (no company) or a company's, which
 * sees the installation's wording beside its own. A locked text is never listed. The texts are the release's files,
 * a few thousand a language, so a search folds and reads them all and pages what it found.
 */
final readonly class TextCatalogue
{
    public function __construct(private ShippedTexts $shipped, private TextLocks $locks, private TextOverrideRepository $overrides)
    {
    }

    /** @return array{items: list<TextCatalogueEntry>, total: int} */
    public function page(?Company $company, string $language, TextCatalogueQuery $query): array
    {
        $installation = self::byAddress($this->overrides->of(null, $language));
        $own = null === $company ? $installation : self::byAddress($this->overrides->of($company, $language));
        $wanted = null === $query->search ? '' : TypedText::folded($query->search);

        $found = [];
        foreach ($this->shipped->domains() as $domain) {
            if (null !== $query->domain && !self::within($domain, $query->domain)) {
                continue;
            }
            foreach ($this->shipped->texts($language, $domain) as $key => $original) {
                if ($this->locks->locked(new TextAddress($language, $domain, $key))) {
                    continue;
                }
                $address = $domain.'|'.$key;
                $mine = $own[$address] ?? null;
                $entry = new TextCatalogueEntry(
                    $domain,
                    $key,
                    $original,
                    ($installation[$address] ?? null)?->getValue(),
                    null === $company ? null : $mine?->getValue(),
                    $mine?->getVersion() ?? 0,
                    null !== $mine && $mine->getOriginal() !== $original,
                );
                if (($query->originalChanged && !$entry->originalChanged) || !self::says($entry, $wanted)) {
                    continue;
                }
                $found[] = $entry;
            }
        }

        return ['items' => \array_slice($found, ($query->page - 1) * $query->perPage, $query->perPage), 'total' => \count($found)];
    }

    /** The domain asked for, or one of its pages: `screen` is the language's file and every page's own, `screen:<page>`. */
    private static function within(string $domain, string $wanted): bool
    {
        return $domain === $wanted || str_starts_with($domain, $wanted.':');
    }

    /** Whether the key or any wording of the text holds what was looked for, its case and accents aside. */
    private static function says(TextCatalogueEntry $entry, string $wanted): bool
    {
        if ('' === $wanted) {
            return true;
        }
        foreach ([$entry->key, $entry->original, $entry->installation, $entry->company] as $said) {
            if (null !== $said && str_contains(TypedText::folded($said), $wanted)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<TextOverride> $overrides
     *
     * @return array<string, TextOverride> by `<domain>|<key>`
     */
    private static function byAddress(array $overrides): array
    {
        $by = [];
        foreach ($overrides as $override) {
            $address = $override->address();
            $by[$address->domain.'|'.$address->key] = $override;
        }

        return $by;
    }
}
