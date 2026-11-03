<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Concurrency\Support\Steps;

use App\Module\DeliveryNotes\Application\DeliveryNoteWorkflow;
use App\Module\Invoices\Application\InvoiceWorkflow;
use App\Shared\Application\Transactions;
use App\Shared\Infrastructure\Doctrine\Rows;
use App\Tests\Concurrency\Support\RacerContext;
use App\Tests\Concurrency\Support\RaceStep;
use Symfony\Bundle\FrameworkBundle\Test\TestContainer;
use Symfony\Component\Uid\Uuid;

/**
 * Racer 0 issues the credit note `creditNote`, which takes an invoice's goods back, in a unit of work that stays open
 * `lingerMs` after the issue returns, and says so by taking the advisory lock `signal` inside it; racer 1 validates the
 * delivery note `note` drawn from that invoice only once PostgreSQL shows that lock held, so the credit note holds the
 * invoice when the note asks for it, whatever the machine's load. Each takes a series of its own, so no number's lock
 * lines them up: only the invoice's own lock, under which the note reads what is left to deliver, makes it wait.
 */
final class CreditOrDeliverInvoice implements RaceStep
{
    /** The class id of the lock racer 0 holds once its credit note is issued; the race draws the object id. */
    private const int ISSUED = 717702;

    public function run(TestContainer $container, int $index, array $arguments): mixed
    {
        $company = RacerContext::company($container, $arguments);
        $actor = Uuid::fromString(Rows::text($arguments['actor'] ?? null));
        $signal = Rows::int($arguments['signal'] ?? null);
        $connection = $container->get('doctrine.dbal.default_connection');
        if (0 === $index) {
            $creditNote = Uuid::fromString(Rows::text($arguments['creditNote'] ?? null));
            $linger = $arguments['lingerMs'] ?? null;

            return $container->get(Transactions::class)->run(static function () use ($container, $connection, $company, $creditNote, $actor, $linger, $signal): ?string {
                $number = $container->get(InvoiceWorkflow::class)->issue($company, $creditNote, $actor)->getNumber();
                $connection->executeQuery('SELECT pg_advisory_xact_lock(?, ?)', [self::ISSUED, $signal]);
                usleep(1000 * (\is_int($linger) ? $linger : 0));

                return $number;
            });
        }
        $deadline = microtime(true) + 30.0;
        while (0 === Rows::int($connection->fetchOne(
            "SELECT count(*) FROM pg_locks WHERE locktype = 'advisory' AND classid = ? AND objid = ? AND objsubid = 2 AND granted
               AND database = (SELECT oid FROM pg_database WHERE datname = current_database())",
            [self::ISSUED, $signal],
        ))) {
            if (microtime(true) > $deadline) {
                throw new \RuntimeException('The credit note never said it was issued.');
            }
            usleep(10_000);
        }

        return $container->get(DeliveryNoteWorkflow::class)->validate($company, Uuid::fromString(Rows::text($arguments['note'] ?? null)), $actor)->getNumber();
    }
}
