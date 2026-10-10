<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Erasure\Application;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * What a module lets « Effacer des données » take of its own tables, and what its columns naming another part's rows
 * mean to an erasure. Declared in the module's own `Infrastructure/Erasure/`, each about the tables it owns.
 */
#[AutoconfigureTag('app.erasure.declarations')]
interface DeclaresErasure
{
    /** @return list<ErasedRows> the rows taken, those above before those naming them */
    public function steps(): array;

    /** @return list<ErasureReference> */
    public function references(): array;

    /** @return list<NamedFile> */
    public function files(): array;
}
