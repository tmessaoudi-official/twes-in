<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Infrastructure\Validation;

use App\Shared\Domain\Service\ContactRules;
use App\Shared\Domain\Service\TypedText;
use App\Shared\Infrastructure\ApiPlatform\Output\DomainRule;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

/**
 * Asks the domain's rule and, when it refuses, adds the violation the domain's refusal makes: its message, and in its
 * payload the reason a screen and a file's report translate and that text's parameters. An empty value is no value.
 */
final class ContactRuleValidator extends ConstraintValidator
{
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof ContactRule) {
            throw new UnexpectedTypeException($constraint, ContactRule::class);
        }
        if (null === $value) {
            return;
        }
        if (!\is_string($value)) {
            throw new UnexpectedValueException($value, 'string');
        }
        if ('' === TypedText::trimmed($value)) {
            return;
        }

        // The domain keeps a value without its surrounding spaces, so it judges it so.
        $value = TypedText::trimmed($value);
        $refusal = match ($constraint->rule) {
            ContactRule::EMAIL => ContactRules::email($value),
            ContactRule::PHONE => ContactRules::phone($value),
            ContactRule::WEBSITE => ContactRules::website($value),
            ContactRule::COUNTRY => ContactRules::country($value),
            ContactRule::BIC => ContactRules::bic($value),
        };
        if (null !== $refusal) {
            $this->context->getViolations()->add(DomainRule::violation($this->context->getPropertyPath(), $refusal->message, $refusal->reason, $refusal->params, $this->context->getRoot(), $value));
        }
    }
}
