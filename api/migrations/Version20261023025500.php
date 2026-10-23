<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * A rectangle on a plan is bounded by the body the plan shows, not by its unturned box: a 6 m wall standing at 90°
 * reaches 2.9 m left of its anchor, so it stands flush with the floor's left edge at x = -2.9. A negative anchor is
 * taken while the turned body stays on the floor, as `PlanRect` takes it; an anchor of zero or more is taken as
 * before, so every plan the release before writes is still accepted.
 */
final class Version20261023025500 extends AbstractMigration
{
    private const array TABLES = ['venue_spot', 'venue_structure'];

    public function getDescription(): string
    {
        return 'A turned rectangle may stand flush with the floor\'s left and top edges.';
    }

    public function up(Schema $schema): void
    {
        foreach (self::TABLES as $table) {
            $this->addSql(\sprintf('ALTER TABLE %1$s DROP CONSTRAINT ck_%1$s_on_the_plan', $table));
            $this->addSql(\sprintf(
                'ALTER TABLE %1$s ADD CONSTRAINT ck_%1$s_on_the_plan CHECK (plan_height >= 0'
                .' AND (plan_x >= 0 OR plan_x >= (abs(plan_width * cosd(plan_rotation)) + abs(plan_depth * sind(plan_rotation))) / 2 - plan_width / 2 - 0.0005)'
                .' AND (plan_y >= 0 OR plan_y >= (abs(plan_width * sind(plan_rotation)) + abs(plan_depth * cosd(plan_rotation))) / 2 - plan_depth / 2 - 0.0005))',
                $table,
            ));
        }
    }

    public function down(Schema $schema): void
    {
        foreach (self::TABLES as $table) {
            $this->addSql(\sprintf('ALTER TABLE %1$s DROP CONSTRAINT ck_%1$s_on_the_plan', $table));
            $this->addSql(\sprintf('ALTER TABLE %1$s ADD CONSTRAINT ck_%1$s_on_the_plan CHECK (plan_x >= 0 AND plan_y >= 0 AND plan_height >= 0)', $table));
        }
    }
}
