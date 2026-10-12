<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\DataHealth\Application;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * A context's checks of its own data, declared in its own `Infrastructure/DataHealth/`: what no constraint of the
 * database states and every use case keeps, so that only a direct write, a bug or a failed restore breaks it. Each
 * check reads the whole database, every company at once, and must stay read-only.
 */
#[AutoconfigureTag('app.data.checks')]
interface DeclaresDataChecks
{
    /**
     * The checks' codes, `<context>.<what>`, which the operators are told and the console prints.
     *
     * @return list<string>
     */
    public function dataChecks(): array;

    /**
     * What one of them finds, nothing when the data holds.
     *
     * @return iterable<DataFinding>
     */
    public function findings(string $check): iterable;
}
