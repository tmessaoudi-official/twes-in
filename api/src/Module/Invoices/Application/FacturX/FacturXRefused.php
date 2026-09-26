<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Application\FacturX;

/**
 * A document that is not written as Factur-X: not issued yet (`not_issued`), of a country the product writes no Factur-X
 * for (`preset_not_supported`), or lacking what EN 16931 asks for (`incomplete_document`, each gap named by its own code
 * and parameters for a screen to translate). A file a validator would refuse is never written instead.
 */
final class FacturXRefused extends \DomainException
{
    public const string NOT_ISSUED = 'not_issued';

    /**
     * @param array<string, string|int>                                                 $params
     * @param list<array{code: string, params: array<string, string|int|list<string>>}> $gaps
     */
    public function __construct(
        string $message,
        public readonly string $reason,
        public readonly array $params = [],
        public readonly array $gaps = [],
    ) {
        parent::__construct($message);
    }
}
