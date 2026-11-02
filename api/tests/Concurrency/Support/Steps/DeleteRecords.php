<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Concurrency\Support\Steps;

use App\Records\Application\RecordCatalogue;
use App\Records\Application\RemoveRecords;
use App\Shared\Infrastructure\Doctrine\Rows;
use App\Tests\Concurrency\Support\RacerContext;
use App\Tests\Concurrency\Support\RaceStep;
use Symfony\Bundle\FrameworkBundle\Test\TestContainer;

/** The records `ids` of the table `subject` deleted, as a list's « Supprimer » deletes them; the removal's id. */
final class DeleteRecords implements RaceStep
{
    public function run(TestContainer $container, int $index, array $arguments): mixed
    {
        // Every other racer names them in the other order: the lock reads them in the ids' order either way.
        $ids = $arguments['ids'] ?? null;

        return $container->get(RemoveRecords::class)->handle(
            RacerContext::company($container, $arguments),
            $container->get(RecordCatalogue::class)->subject(Rows::text($arguments['subject'] ?? null)),
            \is_array($ids) && 1 === $index % 2 ? array_reverse($ids) : $ids,
            null,
        )->getId()->toRfc4122();
    }
}
