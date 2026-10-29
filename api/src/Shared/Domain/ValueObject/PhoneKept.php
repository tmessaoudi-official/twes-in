<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Domain\ValueObject;

/**
 * What a record keeps of a phone it was sent: the value to store, or the refusal its field says; and, for a number of
 * the right length outside the ones its country hands out, the « À vérifier » line a record with a mark carries.
 */
final readonly class PhoneKept
{
    public function __construct(public ?string $phone, public ?Refusal $refusal = null, public ?string $doubt = null)
    {
    }

    public static function refused(Refusal $refusal): self
    {
        return new self(null, $refusal);
    }

    /**
     * The « À vérifier » a record with a mark is written with for this phone's doubt, its line after the note the mark
     * already carries, so the person who looks reads what to check; null when the phone raises none.
     *
     * @param CheckMark|null $held the mark as written, else as the record holds it
     */
    public function markOn(?CheckMark $held): ?CheckMark
    {
        if (null === $this->doubt) {
            return null;
        }
        $before = true === $held?->marked ? $held->note : null;

        return new CheckMark(true, mb_substr(null === $before ? $this->doubt : $before."\n".$this->doubt, 0, CheckMark::NOTE_MAX));
    }
}
