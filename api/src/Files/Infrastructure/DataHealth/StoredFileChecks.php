<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Files\Infrastructure\DataHealth;

use App\DataHealth\Application\DataFinding;
use App\DataHealth\Application\DeclaresDataChecks;
use App\Files\Infrastructure\Doctrine\StoredBytes;
use App\Files\Infrastructure\Scheduler\RemoveUnnamedBytesEveryHour;
use App\Files\Infrastructure\Scheduler\UnnamedBytesBounds;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Each company's file rows and the bytes in its folder, both ways: a row whose bytes are gone shows a document or a
 * photo nobody can open, and bytes no row names are a write cut short between the two. The hourly task removes those
 * (RemoveUnnamedBytesEveryHour), so they are reported only once it should have taken them: a task that stopped, not a
 * write on its way. Only the folders of companies that exist are read: what a removed company left is its erasure's to take.
 */
final readonly class StoredFileChecks implements DeclaresDataChecks
{
    public const string STORED = 'files.stored';

    public function __construct(
        private StoredBytes $bytes,
        private ClockInterface $clock,
        #[Autowire(param: UnnamedBytesBounds::REMOVED_AFTER)] private string $removedAfter,
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
        $overdue = $this->clock->now()->sub(new \DateInterval($this->removedAfter))->modify('-'.RemoveUnnamedBytesEveryHour::EVERY);
        foreach ($this->bytes->companies() as $company) {
            ['missing' => $missing, 'unnamed' => $unnamed] = $this->bytes->of($company);
            if ([] !== $missing) {
                yield new DataFinding(self::STORED, $company, \sprintf('%d file rows whose bytes are gone', \count($missing)));
            }
            $left = \count(array_filter($unnamed, static fn (\DateTimeImmutable $written): bool => $written < $overdue));
            if ($left > 0) {
                yield new DataFinding(self::STORED, $company, \sprintf('%d stored files no row names', $left));
            }
        }
    }
}
