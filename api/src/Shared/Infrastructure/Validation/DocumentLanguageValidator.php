<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Infrastructure\Validation;

use App\Shared\Application\Languages;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

/** Asks the catalogue; an empty value is left to `NotBlank`. */
final class DocumentLanguageValidator extends ConstraintValidator
{
    public function __construct(private readonly Languages $languages)
    {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof DocumentLanguage) {
            throw new UnexpectedTypeException($constraint, DocumentLanguage::class);
        }
        if (null === $value || '' === $value) {
            return;
        }
        if (!\is_string($value) || !\in_array($value, $this->languages->documents(), true)) {
            $this->context->buildViolation($constraint->message)
                ->setParameter('{{ value }}', $this->formatValue($value))
                ->setCode(DocumentLanguage::NOT_PRINTED_IN)
                ->addViolation();
        }
    }
}
