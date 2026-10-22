<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\ImportExport\Infrastructure\Import;

use App\ImportExport\Application\SetAside;
use App\Shared\Domain\ValueObject\CheckMark;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The « À vérifier » a row's set-aside cells leave on its record, in the importing person's language: one line per cell
 * naming the column, the value as the file wrote it and why it was left out, after the note the row or the record
 * already had, so the person correcting the record reads what to copy again without the file at hand.
 */
final readonly class SetAsideMarks
{
    public const string DOMAIN = 'imports';

    public function __construct(private TranslatorInterface $translator)
    {
    }

    /**
     * The mark the record is written with: the row's own, else the one it holds, with a line for each cell set aside.
     *
     * @param ?CheckMark            $given  the row's « À vérifier », or null when it leaves the mark as it is
     * @param ?CheckMark            $held   the record's, when it exists
     * @param array<string, string> $labels each column's label, as the person reads it
     */
    public function marked(SetAside $aside, ?CheckMark $given, ?CheckMark $held, array $labels): ?CheckMark
    {
        if (!$aside->any()) {
            return $given;
        }
        $lines = [];
        $before = null !== $given ? $given->note : (true === $held?->marked ? $held->note : null);
        if (null !== $before) {
            $lines[] = $before;
        }
        foreach ($aside->cells() as $column => $cell) {
            $why = $this->translator->trans('set_aside.why.'.$cell['reason'], [], self::DOMAIN);
            $example = $cell['params']['example'] ?? null;
            if (null !== $example) {
                $why = $this->translator->trans('set_aside.with_example', ['%why%' => $why, '%example%' => (string) $example], self::DOMAIN);
            }
            $lines[] = $this->translator->trans('set_aside.line', ['%label%' => $labels[$column] ?? $column, '%value%' => $cell['value'], '%why%' => $why], self::DOMAIN);
        }

        return new CheckMark(true, mb_substr(implode("\n", $lines), 0, CheckMark::NOTE_MAX));
    }

    /** A column's label from this domain, for a column the API itself names. */
    public function label(string $column): string
    {
        return $this->translator->trans('column.'.$column, [], self::DOMAIN);
    }

    /** A label another domain holds, such as a registration number's from its preset. */
    public function labelIn(string $key, string $domain): string
    {
        return $this->translator->trans($key, [], $domain);
    }
}
