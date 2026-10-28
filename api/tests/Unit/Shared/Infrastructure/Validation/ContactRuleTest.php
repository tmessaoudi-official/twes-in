<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Validation;

use ApiPlatform\Metadata\ApiProperty;
use App\Shared\Domain\Service\ContactRules;
use App\Shared\Infrastructure\Validation\ContactRule;
use App\Shared\Infrastructure\Validation\ContactRuleSchema;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\ConstraintValidatorFactory;
use Symfony\Component\Validator\Validation;

/**
 * A form field held to the domain's contact rule refuses under the domain's reason, and the API document still says what
 * the field takes, as the built-in constraints it replaced said it.
 */
final class ContactRuleTest extends TestCase
{
    public function testAFieldIsRefusedUnderTheReasonTheDomainGives(): void
    {
        $validator = Validation::createValidatorBuilder()->setConstraintValidatorFactory(new ConstraintValidatorFactory())->getValidator();

        $violations = $validator->validate('nobody@nowhere', new ContactRule(ContactRule::EMAIL));

        self::assertCount(1, $violations);
        self::assertSame(['reason' => 'invalid_email'], $violations->get(0)->getConstraint()?->payload);
        self::assertSame('invalid_email', $violations->get(0)->getCode());
        self::assertCount(0, $validator->validate('', new ContactRule(ContactRule::EMAIL)), 'an empty value is no value');
        self::assertCount(0, $validator->validate(null, new ContactRule(ContactRule::COUNTRY)));
        self::assertCount(1, $validator->validate('XX', new ContactRule(ContactRule::COUNTRY)));
        self::assertCount(0, $validator->validate(' TN ', new ContactRule(ContactRule::COUNTRY)), 'judged as the domain keeps it, without its spaces');
        self::assertCount(0, $validator->validate('  ', new ContactRule(ContactRule::PHONE)), 'spaces alone are no value');
    }

    public function testTheApiDocumentSaysWhatAFieldTakes(): void
    {
        $schema = new ContactRuleSchema();
        $property = new ApiProperty();

        self::assertSame(['format' => 'email', 'maxLength' => 254], $schema->create(new ContactRule(ContactRule::EMAIL), $property));
        self::assertSame(['format' => 'uri', 'maxLength' => 200], $schema->create(new ContactRule(ContactRule::WEBSITE), $property));
        self::assertSame(['pattern' => '^('.ContactRules::PHONE_PATTERN.')$'], $schema->create(new ContactRule(ContactRule::PHONE), $property));
        self::assertSame(['pattern' => '^[A-Z]{2}$'], $schema->create(new ContactRule(ContactRule::COUNTRY), $property));
        self::assertTrue($schema->supports(new ContactRule(ContactRule::BIC), $property));
        self::assertFalse($schema->supports(new Assert\Email(), $property));
        self::assertSame(['maxLength' => 254], $schema->create(new ContactRule(ContactRule::EMAIL), new ApiProperty(schema: ['format' => 'idn-email'])), 'a format the property declares itself stands');
    }
}
