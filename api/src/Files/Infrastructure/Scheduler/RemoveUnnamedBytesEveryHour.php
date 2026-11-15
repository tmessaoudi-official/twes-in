<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Files\Infrastructure\Scheduler;

use App\Files\Application\FileStorage;
use App\Files\Infrastructure\Doctrine\StoredBytes;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Scheduler\Attribute\AsPeriodicTask;

/**
 * Removes the bytes under each company's folder that no file row names, once written longer ago than
 * app.files.unnamed_removed_after: a write cut short between its bytes and its row (a process killed, a disk that
 * filled) leaves them, nobody can open them, and nothing else would ever remove them. Bytes a row names stay whatever
 * their age, a deleted record's included while it may be undone. One company failing leaves the others to be read.
 */
#[AsPeriodicTask(frequency: self::EVERY)]
final readonly class RemoveUnnamedBytesEveryHour
{
    /** How often it runs: the data check counts it in, reporting only what it should already have removed. */
    public const string EVERY = '1 hour';

    public function __construct(
        private StoredBytes $bytes,
        private FileStorage $storage,
        private ClockInterface $clock,
        private LoggerInterface $logger,
        #[Autowire(param: UnnamedBytesBounds::REMOVED_AFTER)] private string $removedAfter,
    ) {
    }

    public function __invoke(): void
    {
        $before = $this->clock->now()->sub(new \DateInterval($this->removedAfter));
        foreach ($this->bytes->companies() as $company) {
            try {
                $removed = 0;
                foreach ($this->bytes->of($company)['unnamed'] as $key => $written) {
                    if ($written < $before) {
                        $this->storage->delete($key);
                        ++$removed;
                    }
                }
                if ($removed > 0) {
                    $this->logger->info('Removed {count} stored files no file row names in company {company}', ['count' => $removed, 'company' => $company->toRfc4122()]);
                }
            } catch (\Throwable $failure) {
                $this->logger->error('Stored files no file row names could not be removed in company {company}: {reason}', ['company' => $company->toRfc4122(), 'reason' => $failure->getMessage(), 'exception' => $failure]);
            }
        }
    }
}
