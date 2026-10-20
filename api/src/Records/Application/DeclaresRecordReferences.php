<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Records\Application;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * What a module says about the tables it owns: which are subjects a person may delete, restore and merge, and what its
 * columns naming a subject's records mean to those acts. Declared in the module's own `Infrastructure/Records/`.
 */
#[AutoconfigureTag('app.record.references')]
interface DeclaresRecordReferences
{
    /** @return list<RecordSubject> */
    public function subjects(): array;

    /** @return list<RecordReference> */
    public function references(): array;
}
