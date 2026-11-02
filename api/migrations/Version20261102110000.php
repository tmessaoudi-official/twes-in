<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261102110000 extends AbstractMigration
{
    /**
     * The swatches that sat on a status colour, each with the shade it moved to: red was the danger fill to the eye,
     * pink and orange near the danger and warning tones, green near the success label.
     */
    private const array MOVED = [
        '#b3261e' => '#760906',
        '#c2185b' => '#bc1d6f',
        '#c25400' => '#ac5102',
        '#1b7f3b' => '#058329',
    ];

    public function getDescription(): string
    {
        return 'A screen or document coloured with a swatch that moved off a status colour keeps its swatch, in its new shade.';
    }

    public function up(Schema $schema): void
    {
        foreach (self::MOVED as $old => $new) {
            $this->recolour($old, $new);
        }
    }

    public function down(Schema $schema): void
    {
        foreach (self::MOVED as $old => $new) {
            $this->recolour($new, $old);
        }
    }

    /**
     * Every level holding the swatch, whatever its case, as a colour field writes it, at every level a key is set:
     * the platform's, a company's, a person's. A colour typed by hand is another value and stays as it is. The row's
     * version is left: the choice is the same swatch, so an editor open on it has nothing to merge.
     */
    private function recolour(string $from, string $to): void
    {
        $this->addSql(\sprintf(
            <<<'SQL'
                UPDATE setting SET value = '"%s"'::jsonb
                WHERE key IN ('presentation.accent', 'document.accent')
                  AND jsonb_typeof(value) = 'string'
                  AND lower(value #>> '{}') = '%s'
                SQL,
            $to,
            $from,
        ));
    }
}
