<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Architecture;

use ApiPlatform\Metadata\Resource\Factory\ResourceNameCollectionFactoryInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\Constraints\Composite;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Mapping\ClassMetadata;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * A resource constraint's payload is what a screen shows when it refuses a field: its reason must be one the screens
 * translate, and a length's maximum the one the constraint holds, or the form would say one limit while the API kept
 * another.
 */
final class RefusalReasonsTest extends KernelTestCase
{
    public function testEveryReasonIsTranslatedAndEveryMaximumIsTheConstraints(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $validator = $container->get(ValidatorInterface::class);
        $translated = self::translated();

        $wrong = [];
        $reasons = 0;
        foreach ($container->get(ResourceNameCollectionFactoryInterface::class)->create() as $class) {
            $metadata = $validator->getMetadataFor($class);
            self::assertInstanceOf(ClassMetadata::class, $metadata);
            foreach ($metadata->getConstrainedProperties() as $property) {
                foreach ($metadata->getPropertyMetadata($property) as $member) {
                    foreach (self::flattened($member->getConstraints()) as $constraint) {
                        $payload = $constraint->payload;
                        if (!\is_array($payload) || !isset($payload['reason'])) {
                            continue;
                        }
                        ++$reasons;
                        $where = \sprintf('%s::$%s %s', $class, $property, $constraint::class);
                        if (!\is_string($payload['reason']) || !\array_key_exists($payload['reason'], $translated)) {
                            $wrong[] = "$where: no screen text for the reason";
                        }
                        $params = $payload['params'] ?? [];
                        $max = \is_array($params) ? $params['max'] ?? null : 'params that are not a map';
                        if (null !== $max && (!$constraint instanceof Length || $constraint->max !== $max)) {
                            $wrong[] = \sprintf('%s: a maximum of %s said, not the constraint\'s', $where, json_encode($max));
                        }
                    }
                }
            }
        }

        self::assertGreaterThan(30, $reasons, 'the resources give their refusals a reason');
        self::assertSame([], $wrong);
    }

    /**
     * A domain refusal of a customer's or a vendor's field reaches the form the same way: every reason one is written
     * with has its screen text. A reason is the snake_case literal of the refusal's statement (fields, parameters and
     * messages are not snake_case).
     */
    public function testEveryReasonADomainRefusesAFieldWithIsTranslated(): void
    {
        // No form reaches it: a form offers only the identifiers its preset declares, and its words are not ruled yet.
        $unreachable = ['unknown_identifier'];
        $root = \dirname(__DIR__, 2);
        $translated = self::translated();
        $statements = 0;
        $untranslated = [];
        foreach (['src/Module/Customers', 'src/Module/Vendors', 'src/Fiscal/Application/Preset'] as $directory) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator("$root/$directory", \FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                \assert($file instanceof \SplFileInfo);
                $source = (string) file_get_contents($file->getPathname());
                preg_match_all('/new (?:InvalidCustomer|InvalidVendor|IdentifierRefusal)\((.*?)\);/s', $source, $found);
                foreach ($found[1] as $arguments) {
                    ++$statements;
                    preg_match_all("/'([a-z]+(?:_[a-z]+)+)'/", $arguments, $reasons);
                    foreach ($reasons[1] ?: ['invalid_value'] as $reason) {
                        if (!\array_key_exists($reason, $translated) && !\in_array($reason, $unreachable, true)) {
                            $untranslated[] = "$reason in {$file->getFilename()}";
                        }
                    }
                }
            }
        }

        self::assertGreaterThan(15, $statements, 'the domain refusals are found');
        self::assertSame([], $untranslated);
    }

    /** @return array<mixed> the screens' texts for a refusal, by reason */
    private static function translated(): array
    {
        $texts = json_decode((string) file_get_contents(\dirname(__DIR__, 3).'/web/public/i18n/fr.json'), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($texts);
        self::assertIsArray($texts['import'] ?? null);
        $translated = $texts['import']['rejections'] ?? null;
        self::assertIsArray($translated);

        return $translated;
    }

    /**
     * @param array<Constraint> $constraints
     *
     * @return list<Constraint>
     */
    private static function flattened(array $constraints): array
    {
        $flat = [];
        foreach ($constraints as $constraint) {
            $flat[] = $constraint;
            if ($constraint instanceof Composite) {
                $flat = [...$flat, ...self::flattened($constraint->getNestedConstraints())];
            }
        }

        return $flat;
    }
}
