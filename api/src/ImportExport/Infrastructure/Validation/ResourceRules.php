<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\ImportExport\Infrastructure\Validation;

use App\ImportExport\Application\RowRejected;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Holds a file's cells to the rules a resource's own form is held to: the domain's own, which a property names through
 * `ContactRule`, and what only the form adds, such as an IBAN's length in its country. A cell is checked against the
 * constraints of the property it fills, in the group a write is validated in, so a file can never store what the form
 * refuses, nor reach a column that cannot hold it.
 */
final readonly class ResourceRules
{
    public function __construct(private ValidatorInterface $validator)
    {
    }

    /**
     * @param class-string                                         $resource
     * @param array<string, array{column: string, value: ?string}> $cells    by the property each fills
     *
     * @throws RowRejected naming every cell refused, each under the reason its constraint gives a screen and that
     *                     text's parameters, so a file and a form say the same refusal
     */
    public function hold(string $resource, string $group, array $cells): void
    {
        $refused = [];
        foreach ($cells as $property => ['column' => $column, 'value' => $value]) {
            if (null === $value) {
                continue;
            }
            $violations = $this->validator->validatePropertyValue($resource, $property, $value, [$group]);
            if (0 === \count($violations)) {
                continue;
            }
            $violation = $violations->get(0);
            $payload = $violation->getConstraint()?->payload;
            $payload = \is_array($payload) ? $payload : [];
            $reason = $payload['reason'] ?? null;

            $refused[] = new RowRejected($column, (string) $violation->getMessage(), \is_string($reason) ? $reason : 'invalid_value', self::params($payload['params'] ?? null));
        }
        if ([] !== $refused) {
            throw RowRejected::together($refused);
        }
    }

    /** @return array<string, string|int> */
    private static function params(mixed $params): array
    {
        $kept = [];
        foreach (\is_array($params) ? $params : [] as $name => $value) {
            if (\is_string($name) && (\is_string($value) || \is_int($value))) {
                $kept[$name] = $value;
            }
        }

        return $kept;
    }
}
