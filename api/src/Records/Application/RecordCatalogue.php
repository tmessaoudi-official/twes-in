<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Records\Application;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/** Every subject and every reference the modules declared, each column said once per subject. */
final readonly class RecordCatalogue
{
    /** @var array<string, RecordSubject> by table */
    private array $subjects;

    /** @var list<RecordReference> */
    private array $references;

    /** @var list<DeclaresRecordReferences> */
    private array $declarations;

    /** @param iterable<DeclaresRecordReferences> $declarations */
    public function __construct(#[AutowireIterator('app.record.references')] iterable $declarations)
    {
        $this->declarations = array_values([...$declarations]);
        $subjects = [];
        $references = [];
        foreach ($this->declarations as $declaration) {
            foreach ($declaration->subjects() as $subject) {
                if (isset($subjects[$subject->table])) {
                    throw new \LogicException(\sprintf('%s declares the subject %s, which another module already declared.', $declaration::class, $subject->table));
                }
                $subjects[$subject->table] = $subject;
            }
            $references = [...$references, ...$declaration->references()];
        }

        $said = [];
        foreach ($references as $reference) {
            if (null !== $reference->subject && !isset($subjects[$reference->subject])) {
                throw new \LogicException(\sprintf('%s.%s names %s, which no module declares a subject.', $reference->table, $reference->column, $reference->subject));
            }
            $key = "$reference->table.$reference->column>".($reference->subject ?? '*');
            if (isset($said[$key])) {
                throw new \LogicException(\sprintf('%s is declared twice.', $key));
            }
            $said[$key] = true;
        }

        $this->subjects = $subjects;
        $this->references = $references;
    }

    /** @return list<DeclaresRecordReferences> */
    public function declarations(): array
    {
        return $this->declarations;
    }

    /** @return array<string, RecordSubject> by table */
    public function subjects(): array
    {
        return $this->subjects;
    }

    /** @return list<RecordReference> */
    public function references(): array
    {
        return $this->references;
    }

    /**
     * The references naming one subject's records, those said of every subject left out.
     *
     * @return list<RecordReference>
     */
    public function naming(string $subject): array
    {
        return array_values(array_filter($this->references, static fn (RecordReference $reference): bool => $subject === $reference->subject));
    }
}
