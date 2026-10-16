<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Infrastructure\ApiPlatform\Output;

use ApiPlatform\Validator\Exception\ValidationException;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;

/**
 * A rule the domain applied, standing as the constraint of the field it refused. It is never validated: a domain refusal
 * answers through it so a screen reads it exactly as it reads a resource constraint's — one violation naming the field,
 * the English message, and in its payload the reason the screen translates and that text's parameters.
 */
final class DomainRule extends Constraint
{
    /** @param array<string, string|int> $params */
    public static function refused(string $field, string $message, string $reason, array $params = [], ?\Throwable $previous = null): ValidationException
    {
        return new ValidationException(new ConstraintViolationList([self::violation($field, $message, $reason, $params)]), previous: $previous);
    }

    /**
     * The violation a domain refusal makes of a field, as a form's own validation adds it.
     *
     * @param array<string, string|int> $params
     */
    public static function violation(string $field, string $message, string $reason, array $params = [], mixed $root = null, mixed $invalidValue = null): ConstraintViolation
    {
        $rule = new self(payload: [] === $params ? ['reason' => $reason] : ['reason' => $reason, 'params' => $params]);

        return new ConstraintViolation($message, null, [], $root, $field, $invalidValue, null, $reason, $rule);
    }
}
