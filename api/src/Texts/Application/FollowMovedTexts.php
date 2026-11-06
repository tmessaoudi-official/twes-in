<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Texts\Application;

use App\Shared\Application\Transactions;
use App\Tenancy\Domain\Model\Company;
use App\Texts\Domain\Model\TextOverride;
use App\Texts\Domain\Repository\TextOverrideRepository;
use App\Texts\Domain\ValueObject\TextAddress;
use App\Texts\Domain\ValueObject\TextSyntax;

/**
 * Brings a screen text's wording to the file that ships the text now, at each start of a release. A wording is stored
 * under the file that shipped its text when it was written, and every reader matches that file: a text a release moved
 * into a page's own file would otherwise lose the company's or the installation's words without a word said. A screen
 * key is in one file only (the web's parity spec refuses a key in two), so the key names where its wording goes.
 *
 * Nothing is deleted and no wording changes: one already at the new place stays in force there, the one written last
 * follows when two were left behind, and one whose text no screen file ships any more stays where it is.
 */
final readonly class FollowMovedTexts
{
    public function __construct(
        private ShippedTexts $shipped,
        private TextOverrideRepository $overrides,
        private Transactions $transactions,
    ) {
    }

    /** @return int how many wordings followed their text */
    public function follow(): int
    {
        return $this->transactions->run(function (): int {
            $wordings = $this->overrides->lockedScreenTexts();
            $taken = [];
            foreach ($wordings as $wording) {
                $taken[self::place($wording->getCompany(), $wording->address())] = true;
            }
            $files = [];
            $moved = 0;
            // The latest written first, so of two wordings left behind for one text, that one takes its place.
            foreach ($wordings as $wording) {
                $address = $wording->address();
                $files[$address->language] ??= $this->screenFileOfEachKey($address->language);
                $file = $files[$address->language][$address->key] ?? null;
                if (null === $file) {
                    continue;
                }
                // Taken where it already is, or by another wording of that text there: nothing moves.
                $there = self::place($wording->getCompany(), new TextAddress($address->language, $file, $address->key));
                if (isset($taken[$there])) {
                    continue;
                }
                $this->move($wording, $file);
                $taken[$there] = true;
                ++$moved;
            }

            return $moved;
        });
    }

    private function move(TextOverride $wording, string $file): void
    {
        $wording->followInto($file);
        $this->overrides->save($wording);
    }

    /** @return array<string, string> the screen file shipping each key in that language */
    private function screenFileOfEachKey(string $language): array
    {
        $files = [];
        foreach ($this->shipped->domains() as $domain) {
            if (TextSyntax::Screen !== new TextAddress($language, $domain, '')->syntax()) {
                continue;
            }
            foreach (array_keys($this->shipped->texts($language, $domain)) as $key) {
                $files[$key] = $domain;
            }
        }

        return $files;
    }

    private static function place(?Company $company, TextAddress $address): string
    {
        return ($company?->getId()->toRfc4122() ?? '').'|'.$address->language.'|'.$address->domain.'|'.$address->key;
    }
}
