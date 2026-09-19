<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\ImportExport\Application;

use App\ModuleRegistry\Application\ModuleStates;
use App\Tenancy\Domain\Company;

/** Every module's import declaration, collected once (docs/SPEC.md § 3, the same shape as settings and modules). */
final readonly class ImportCatalogue
{
    /** @var array<string, DeclaresImport> */
    private array $declarations;

    /** @param iterable<DeclaresImport> $declarations */
    public function __construct(iterable $declarations, private ModuleStates $modules)
    {
        $byKey = [];
        foreach ($declarations as $declaration) {
            $key = $declaration->key();
            if (isset($byKey[$key])) {
                throw new \LogicException(\sprintf('The import subject %s is declared twice.', $key));
            }
            $byKey[$key] = $declaration;
        }

        $this->declarations = $byKey;
    }

    /** @return list<string> what can be imported, in a stable order so a screen does not reshuffle between requests */
    public function keys(): array
    {
        $keys = array_keys($this->declarations);
        sort($keys);

        return $keys;
    }

    /**
     * The declaration alone, before any company is known: it names the permission to check first.
     *
     * @throws UnknownImportSubject
     */
    public function declaration(string $key): DeclaresImport
    {
        return $this->declarations[$key] ?? throw new UnknownImportSubject(\sprintf('Nothing named "%s" can be imported.', $key));
    }

    /**
     * What the subject looks like for this company. A subject whose module the company switched off is as unknown as
     * one nobody declares: the same 404 the module's own resources answer.
     *
     * @throws UnknownImportSubject
     */
    public function subject(string $key, Company $company): ImportSubject
    {
        $declaration = $this->declaration($key);
        if (!$this->modules->isEnabled($company->getId(), $declaration->module())) {
            throw new UnknownImportSubject(\sprintf('Nothing named "%s" can be imported.', $key));
        }

        return $declaration->subjectFor($company);
    }
}
