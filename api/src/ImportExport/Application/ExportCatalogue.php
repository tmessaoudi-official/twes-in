<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\ImportExport\Application;

use App\ModuleRegistry\Application\ModuleStates;
use App\Tenancy\Domain\Company;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/** Every module's export declaration, collected once, the same shape as the imports. */
final readonly class ExportCatalogue
{
    /** @var array<string, DeclaresExport> */
    private array $declarations;

    /** @param iterable<DeclaresExport> $declarations */
    public function __construct(#[AutowireIterator('app.export.declarations')] iterable $declarations, private ModuleStates $modules)
    {
        $byKey = [];
        foreach ($declarations as $declaration) {
            $key = $declaration->key();
            if (isset($byKey[$key])) {
                throw new \LogicException(\sprintf('The export subject %s is declared twice.', $key));
            }
            $byKey[$key] = $declaration;
        }

        $this->declarations = $byKey;
    }

    /**
     * The declaration alone, before any company is known: it names the permission to check first.
     *
     * @throws UnknownExportSubject
     */
    public function declaration(string $key): DeclaresExport
    {
        return $this->declarations[$key] ?? throw new UnknownExportSubject(\sprintf('Nothing named "%s" can be exported.', $key));
    }

    /**
     * A list whose module the company switched off is as unknown as one nobody declares: the same 404 the module's own
     * resources answer.
     *
     * @throws UnknownExportSubject
     */
    public function declarationFor(string $key, Company $company): DeclaresExport
    {
        $declaration = $this->declaration($key);
        $module = $declaration->module();
        if (null !== $module && !$this->modules->isEnabled($company->getId(), $module)) {
            throw new UnknownExportSubject(\sprintf('Nothing named "%s" can be exported.', $key));
        }

        return $declaration;
    }
}
