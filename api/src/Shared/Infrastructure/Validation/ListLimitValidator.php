<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Infrastructure\Validation;

use App\Shared\Application\ListLimits;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

/** Counts a list; what is no list is left to `Type('list')`. */
final class ListLimitValidator extends ConstraintValidator
{
    public function __construct(private readonly ListLimits $limits)
    {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof ListLimit) {
            throw new UnexpectedTypeException($constraint, ListLimit::class);
        }
        if (!\is_array($value)) {
            return;
        }
        $most = $this->limits->most($constraint->list);
        if (\count($value) > $most) {
            $this->context->buildViolation($constraint->message)
                ->setParameter('{{ limit }}', (string) $most)
                ->setCode(ListLimit::TOO_MANY)
                ->addViolation();
        }
    }
}
