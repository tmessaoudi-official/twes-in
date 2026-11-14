<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Application;

/**
 * The Factur-X file was made but its maker could not say it conforms: violations of the Factur-X or PDF/A-3 rules are left,
 * or it gave no count. Such a file is never handed out as an e-invoice; unlike a renderer that could not be reached, trying
 * again makes the same file, so the export says it as a refusal of its own.
 */
final class FacturXNotConformant extends \RuntimeException
{
    /** @param int|null $residual the violations left, or null when the maker said no count */
    public function __construct(public readonly ?int $residual)
    {
        parent::__construct(null === $residual
            ? 'The Factur-X PDF came back without a count of the violations left.'
            : \sprintf('The Factur-X PDF came back with %d violation(s) left.', $residual));
    }
}
