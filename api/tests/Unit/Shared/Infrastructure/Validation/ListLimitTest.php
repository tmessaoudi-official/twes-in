<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Validation;

use App\Shared\Application\ListLimits;
use App\Shared\Infrastructure\Validation\ListLimit;
use App\Shared\Infrastructure\Validation\ListLimitValidator;
use App\Shared\Infrastructure\Validation\ParameterListLimits;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\ConstraintValidatorFactory;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * A list a request hands in holds at most what the operator's parameter says (`app.list_limits`), so one request cannot
 * make a worker build and price ten thousand lines: refused under its own reason, the most it holds in the message.
 */
final class ListLimitTest extends TestCase
{
    public function testAListUpToItsLimitPassesAndOneMoreIsRefused(): void
    {
        $validator = $this->validator(['document.lines' => 3]);

        self::assertCount(0, $validator->validate([1, 2, 3], new ListLimit(ListLimits::DOCUMENT_LINES)));

        $violations = $validator->validate([1, 2, 3, 4], new ListLimit(ListLimits::DOCUMENT_LINES));
        self::assertCount(1, $violations);
        self::assertSame(['reason' => 'too_many_entries'], $violations->get(0)->getConstraint()?->payload);
        self::assertSame(ListLimit::TOO_MANY, $violations->get(0)->getCode());
        self::assertStringContainsString('at most 3', (string) $violations->get(0)->getMessage());
    }

    /** What is no list is left to the constraint that says what the property takes (`Type('list')`). */
    public function testWhatIsNoListIsLeftToItsTypeConstraint(): void
    {
        $validator = $this->validator(['document.lines' => 1]);

        self::assertCount(0, $validator->validate(null, new ListLimit(ListLimits::DOCUMENT_LINES)));
        self::assertCount(0, $validator->validate('a, b', new ListLimit(ListLimits::DOCUMENT_LINES)));
    }

    /** A limit the parameter does not name is a mistake in the code, not a request to refuse. */
    public function testALimitTheParameterDoesNotNameFailsLoudly(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('document.lines');

        (new ParameterListLimits([]))->most(ListLimits::DOCUMENT_LINES);
    }

    public function testALimitBelowOneIsRefusedAtStart(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('app.list_limits gives document.lines a limit of 0: a list holds at least one entry.');

        new ParameterListLimits(['document.lines' => 0]);
    }

    /** Every list family the code names is one the operator's `app.list_limits` ships a limit for, and no other. */
    public function testEveryListFamilyIsShippedWithALimit(): void
    {
        $file = Yaml::parseFile(\dirname(__DIR__, 5).'/config/services.yaml', Yaml::PARSE_CONSTANT | Yaml::PARSE_CUSTOM_TAGS);
        self::assertIsArray($file);
        self::assertIsArray($file['parameters'] ?? null);
        self::assertIsArray($file['parameters']['app.list_limits'] ?? null);
        $shipped = $file['parameters']['app.list_limits'];

        $families = array_values((new \ReflectionClass(ListLimits::class))->getConstants());
        sort($families);
        $names = array_keys($shipped);
        sort($names);
        self::assertSame($families, $names);
        foreach ($shipped as $list => $most) {
            self::assertIsInt($most, $list);
            self::assertGreaterThanOrEqual(1, $most, $list);
        }
    }

    /** The lists row 83 found unbounded each carry a limit now, or the reason they need none. */
    public function testNoWrittenListIsLeftNotBoundedYet(): void
    {
        $left = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(\dirname(__DIR__, 5).'/src', \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file instanceof \SplFileInfo && 'php' === $file->getExtension() && str_contains((string) file_get_contents($file->getPathname()), 'count: not bounded yet')) {
                $left[] = $file->getFilename();
            }
        }

        self::assertSame([], $left);
    }

    /** @param array<string, int> $limits */
    private function validator(array $limits): ValidatorInterface
    {
        $factory = new ConstraintValidatorFactory([ListLimitValidator::class => new ListLimitValidator(new ParameterListLimits($limits))]);

        return Validation::createValidatorBuilder()->setConstraintValidatorFactory($factory)->getValidator();
    }
}
