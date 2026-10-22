<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Domain\ValueObject;

use App\Shared\Domain\Service\TypedText;

/**
 * « À vérifier » as a person sets it on a record, with a note saying what to look at; written on a form or by an import
 * row. A doubt taken off leaves no note behind, so a note never outlives the doubt it was about. The reasons a record
 * gives on its own are its subject's, computed from what it lacks, never stored here.
 */
final readonly class CheckMark
{
    public const int NOTE_MAX = 500;

    public ?string $note;

    public function __construct(public bool $marked, ?string $note = null)
    {
        $note = TypedText::trimmed($note ?? '');
        $this->note = $marked && '' !== $note ? $note : null;
    }

    /** The refusal of a note too long to keep, or null for one that fits; the record writing it names the field. */
    public static function noteRefusal(?string $note): ?Refusal
    {
        return mb_strlen(TypedText::trimmed($note ?? '')) > self::NOTE_MAX
            ? new Refusal('invalid_text', \sprintf('A note to check is at most %d characters.', self::NOTE_MAX), ['max' => self::NOTE_MAX])
            : null;
    }
}
