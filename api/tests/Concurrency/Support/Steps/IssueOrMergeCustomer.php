<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Concurrency\Support\Steps;

use App\Module\Invoices\Application\InvoiceWorkflow;
use App\Records\Application\Merge\MergeActor;
use App\Records\Application\Merge\MergeRecords;
use App\Records\Application\RecordCatalogue;
use App\Shared\Infrastructure\Doctrine\Rows;
use App\Tests\Concurrency\Support\RacerContext;
use App\Tests\Concurrency\Support\RaceStep;
use Symfony\Bundle\FrameworkBundle\Test\TestContainer;
use Symfony\Component\Uid\Uuid;

/**
 * Racer 0 issues the draft `invoice`; racer 1 merges the customer `merged`, whom that draft bills, into `kept`, as
 * « Fusionner » does after the step-up. Racer 0 answers the number, racer 1 the merge's id.
 */
final class IssueOrMergeCustomer implements RaceStep
{
    public function run(TestContainer $container, int $index, array $arguments): mixed
    {
        $company = RacerContext::company($container, $arguments);
        $actor = Uuid::fromString(Rows::text($arguments['actor'] ?? null));
        if (0 === $index) {
            return $container->get(InvoiceWorkflow::class)->issue($company, Uuid::fromString(Rows::text($arguments['invoice'] ?? null)), $actor)->getNumber();
        }
        RacerContext::provedAgain($container, $actor);

        return $container->get(MergeRecords::class)->handle(
            $company,
            $container->get(RecordCatalogue::class)->subject('customer'),
            Rows::text($arguments['kept'] ?? null),
            [Rows::text($arguments['merged'] ?? null)],
            new MergeActor($actor, static fn (string $permission): bool => true),
        )->getId()->toRfc4122();
    }
}
