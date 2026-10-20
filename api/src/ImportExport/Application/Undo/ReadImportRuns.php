<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\ImportExport\Application\Undo;

use App\ImportExport\Domain\Model\ImportRun;
use App\ImportExport\Domain\Repository\ImportRunRepository;
use App\Tenancy\Domain\Model\Company;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;

/**
 * « Imports récents »: the company's imports of the day a delete may be undone in, into the subjects the person may
 * write, the latest first, each saying until when « Annuler cet import » takes it back.
 */
final readonly class ReadImportRuns
{
    public function __construct(
        private ImportRunRepository $runs,
        private ImporterNames $names,
        private ClockInterface $clock,
        #[Autowire(param: 'app.records.undoable_for')]
        private string $undoableFor,
    ) {
    }

    /**
     * @param list<string> $subjects the subjects the person may write
     *
     * @return list<array{run: ImportRun, by: ?string, undoableUntil: \DateTimeImmutable}> `by` is null for an account gone since
     */
    public function recent(Company $company, array $subjects): array
    {
        $window = new \DateInterval($this->undoableFor);
        $runs = $this->runs->since($company->getId(), $subjects, $this->clock->now()->sub($window));
        $names = $this->names->namesOf(array_values(array_filter(array_map(static fn (ImportRun $run): ?Uuid => $run->getImportedBy(), $runs))));

        return array_map(
            static fn (ImportRun $run): array => [
                'run' => $run,
                'by' => null === $run->getImportedBy() ? null : $names[$run->getImportedBy()->toRfc4122()] ?? null,
                'undoableUntil' => $run->getAt()->add($window),
            ],
            $runs,
        );
    }

    /** @throws UnknownImportRun */
    public function subjectOf(Company $company, Uuid $runId): string
    {
        return ($this->runs->ofId($company->getId(), $runId) ?? throw new UnknownImportRun('The company has no such import.'))->getSubject();
    }
}
