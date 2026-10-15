<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\ImportExport\Infrastructure\Validation;

use App\ImportExport\Application\RowRejected;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Holds a file's cells to the rules a resource's own form is held to. What the domain does not check itself — an
 * address, an e-mail, an IBAN, a country, a length — is declared once, on the resource's properties; a cell is
 * checked against the constraints of the property it fills, in the group a write is validated in, so a file can never
 * store what the form refuses, nor reach a column that cannot hold it.
 */
final readonly class ResourceRules
{
    public function __construct(private ValidatorInterface $validator)
    {
    }

    /**
     * @param class-string                                                         $resource
     * @param array<string, array{column: string, value: ?string, reason: string}> $cells    by the property each fills
     *
     * @throws RowRejected naming the first cell refused, under its column's code and, for a length, its maximum
     */
    public function hold(string $resource, string $group, array $cells): void
    {
        foreach ($cells as $property => ['column' => $column, 'value' => $value, 'reason' => $reason]) {
            if (null === $value) {
                continue;
            }
            $violations = $this->validator->validatePropertyValue($resource, $property, $value, [$group]);
            if (0 === \count($violations)) {
                continue;
            }
            $violation = $violations->get(0);
            $constraint = $violation->getConstraint();
            $params = $constraint instanceof Length && null !== $constraint->max ? ['max' => $constraint->max] : [];

            throw new RowRejected($column, (string) $violation->getMessage(), $reason, $params);
        }
    }
}
