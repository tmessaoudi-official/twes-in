<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Concurrency\Support\Steps;

use App\ImportExport\Application\Undo\UndoImport;
use App\Shared\Infrastructure\Doctrine\Rows;
use App\Tests\Concurrency\Support\RacerContext;
use App\Tests\Concurrency\Support\RaceStep;
use Symfony\Bundle\FrameworkBundle\Test\TestContainer;
use Symfony\Component\Uid\Uuid;

/** « Annuler cet import » on the run `run` by `actor`, who has just proved who they are; the delete it left in the bin. */
final class UndoImportRun implements RaceStep
{
    public function run(TestContainer $container, int $index, array $arguments): mixed
    {
        $actor = Uuid::fromString(Rows::text($arguments['actor'] ?? null));
        RacerContext::provedAgain($container, $actor);

        return $container->get(UndoImport::class)->handle(RacerContext::company($container, $arguments), Uuid::fromString(Rows::text($arguments['run'] ?? null)), $actor)->removal?->toRfc4122();
    }
}
