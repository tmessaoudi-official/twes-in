<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Files\Infrastructure\DataHealth;

use App\DataHealth\Application\DataFinding;
use App\DataHealth\Application\DeclaresDataChecks;
use App\Files\Application\FileStorage;
use App\Shared\Infrastructure\Doctrine\Rows;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;

/**
 * Each company's file rows and the bytes in its folder, both ways: a row whose bytes are gone shows a document or a
 * photo nobody can open, and bytes no row names are a write cut short between the two, which nobody will ever remove.
 * Bytes written within the longest a request takes may still be waiting for their row, and are left for the next run.
 * Only the folders of companies that exist are read: what a removed company left is its erasure's to take.
 */
final readonly class StoredFileChecks implements DeclaresDataChecks
{
    public const string STORED = 'files.stored';

    public function __construct(
        private Connection $connection,
        private FileStorage $storage,
        private ClockInterface $clock,
        #[Autowire('%app.long_request.max_seconds%')] private int $longestRequestSeconds,
    ) {
    }

    public function dataChecks(): array
    {
        return [self::STORED];
    }

    public function findings(string $check): iterable
    {
        if (self::STORED !== $check) {
            throw new \LogicException(\sprintf('No file data check is named %s.', $check));
        }
        $settled = $this->clock->now()->modify(\sprintf('-%d seconds', $this->longestRequestSeconds));
        foreach ($this->connection->fetchFirstColumn('SELECT id FROM company ORDER BY id') as $id) {
            $company = Uuid::fromString(Rows::text($id));
            $rows = array_flip(array_map(Rows::text(...), $this->connection->fetchFirstColumn('SELECT storage_key FROM file WHERE company_id = ?', [$company->toRfc4122()])));
            $missing = $rows;
            $unnamed = 0;
            foreach ($this->storage->keysUnder('companies/'.$company->toRfc4122()) as $key => $written) {
                if (isset($rows[$key])) {
                    unset($missing[$key]);
                } elseif ($written < $settled) {
                    ++$unnamed;
                }
            }
            if ([] !== $missing) {
                yield new DataFinding(self::STORED, $company, \sprintf('%d file rows whose bytes are gone', \count($missing)));
            }
            if ($unnamed > 0) {
                yield new DataFinding(self::STORED, $company, \sprintf('%d stored files no row names', $unnamed));
            }
        }
    }
}
