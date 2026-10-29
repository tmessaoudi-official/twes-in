<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Infrastructure\ApiPlatform\Output;

use ApiPlatform\Validator\Exception\ValidationException;
use App\Shared\Application\ShortSales\ShortLine;
use App\Shared\Application\ShortSales\ShortOfStock;
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
     * A refusal of what another record already holds (a code another product has, the last owner), answered 409 and
     * read exactly as a 422's: the screen takes its reason and parameters, never a word of its English detail.
     *
     * @param array<string, string|int> $params
     */
    public static function conflict(string $field, string $message, string $reason, array $params = [], ?\Throwable $previous = null): ValidationException
    {
        $conflict = self::refused($field, $message, $reason, $params, $previous);
        $conflict->setStatus(409);

        return $conflict;
    }

    /**
     * A sale refused for the stock it would take, as one violation per short line, on that line's quantity: what it
     * wants, what its places hold and where, so the screen lists them and offers to count or receive there.
     */
    public static function shortOfStock(ShortOfStock $refused): ValidationException
    {
        $violations = array_map(
            static fn (ShortLine $line): ConstraintViolation => self::violation(\sprintf('lines[%d].quantity', $line->index), \sprintf('%s: %s wanted, %s in stock.', $line->reference, $line->wanted, $line->onHand), $refused->reason(), $line->params()),
            $refused->lines,
        );

        return new ValidationException(new ConstraintViolationList($violations), previous: $refused);
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
