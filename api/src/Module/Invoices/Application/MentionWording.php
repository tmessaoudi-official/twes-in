<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Application;

/**
 * What a printed mention's wording waits for: the names of the `%name%` placeholders it holds in one language, which
 * issuing must fill or refuse (docs/SPEC.md § 7, 2026-09-21 18:30).
 */
interface MentionWording
{
    /** @return list<string> the placeholder names, without their percent signs, in the order the wording holds them */
    public function placeholders(string $key, string $language): array;
}
