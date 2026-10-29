<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Architecture;

use App\Legal\Application\LegalSettings;
use App\Settings\Application\SupportSettings;
use App\Settings\Domain\ValueObject\SettingDefinition;
use App\Shared\Domain\Service\ContactRules;
use App\Shared\Infrastructure\Validation\ContactRule;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints\Email;

/**
 * One rule for every e-mail address a person writes, new or changed: `ContactRules::email`, whose refusal says the
 * format with an example. Symfony's `Assert\Email` and PHP's `FILTER_VALIDATE_EMAIL` each take other addresses and say
 * nothing of the format; only the account's own `Email`, with which a person signs in with the address they have, keeps
 * its looser check (docs/SPEC.md § 7, 2026-10-27 17:51).
 */
final class EmailRuleTest extends TestCase
{
    private const string SOURCE = __DIR__.'/../../src';
    /** The account's own address: tightening it would lock out whoever signed up before the rule. */
    private const string ACCOUNT_EMAIL = 'Identity/Domain/ValueObject/Email.php';

    public function testNoOtherEmailCheckIsWrittenAnywhere(): void
    {
        $found = [];
        foreach (self::sources() as $relative => $source) {
            if (self::ACCOUNT_EMAIL !== $relative && 1 === preg_match('/Assert\\\\Email\b|Constraints\\\\Email\b|FILTER_VALIDATE_EMAIL/', $source)) {
                $found[] = $relative;
            }
        }

        self::assertSame([], $found, 'an e-mail is checked by ContactRules::email, through #[ContactRule(ContactRule::EMAIL)] on a resource');
    }

    /** The screen says an address is wrong before it is sent (shared/form/email-rule.ts): the same list holds both. */
    public function testTheScreenAndTheApiTakeAndRefuseTheSameAddresses(): void
    {
        $file = file_get_contents(\dirname(__DIR__, 3).'/web/src/app/shared/form/email-vectors.json');
        self::assertIsString($file);
        $vectors = json_decode($file, true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($vectors);
        self::assertSame(ContactRules::EMAIL_PATTERN, $vectors['pattern'] ?? null);
        foreach (['taken' => true, 'refused' => false] as $list => $taken) {
            $addresses = $vectors[$list] ?? null;
            self::assertIsArray($addresses);
            self::assertNotEmpty($addresses);
            foreach ($addresses as $address) {
                self::assertIsString($address);
                self::assertSame($taken, null === ContactRules::email($address), $address);
            }
        }
    }

    public function testEveryAddressAResourceTakesIsHeldToTheOneRule(): void
    {
        $held = [];
        $loose = [];
        foreach (self::sources() as $relative => $source) {
            if (!str_contains($relative, '/ApiPlatform/Resource/')) {
                continue;
            }
            $class = 'App\\'.str_replace('/', '\\', substr($relative, 0, -4));
            if (!class_exists($class)) {
                continue;
            }
            foreach ((new \ReflectionClass($class))->getProperties() as $property) {
                if (1 !== preg_match('/email$/i', $property->getName()) || !self::written($property)) {
                    continue;
                }
                $rules = array_map(static fn (\ReflectionAttribute $rule): string => $rule->newInstance()->rule, $property->getAttributes(ContactRule::class));
                $name = $class.'::$'.$property->getName();
                if (\in_array(ContactRule::EMAIL, $rules, true) && [] === $property->getAttributes(Email::class)) {
                    $held[] = $name;
                } else {
                    $loose[] = $name;
                }
            }
        }

        self::assertSame([], $loose, 'an address a request writes carries #[ContactRule(ContactRule::EMAIL)] and nothing else of the kind');
        // Customers, contacts, vendors, the company, its establishments, a member added, an owner invited, a sign-up.
        self::assertGreaterThanOrEqual(8, \count($held), 'the resources were read: '.implode(', ', $held));
    }

    public function testEveryAddressAPlatformSettingTakesIsHeldToTheOneRule(): void
    {
        $definitions = [...(new LegalSettings())->settings(), ...(new SupportSettings())->settings()];
        $addresses = array_filter($definitions, static fn (SettingDefinition $definition): bool => str_ends_with($definition->key, '.email'));

        foreach ($addresses as $definition) {
            self::assertSame([ContactRules::EMAIL_OR_EMPTY, 'invalid_email', ContactRules::EMAIL_MAX], [$definition->pattern, $definition->patternReason, $definition->maxLength], $definition->key);
        }
        // The publisher's, privacy's, security's and commercial addresses the legal pages name, and the support's.
        self::assertCount(5, $addresses);
    }

    /** What a request writes: a property in a group other than a read one, which is not read-only. */
    private static function written(\ReflectionProperty $property): bool
    {
        foreach ($property->getAttributes() as $attribute) {
            if (str_ends_with($attribute->getName(), 'ApiProperty') && false === ($attribute->getArguments()['writable'] ?? null)) {
                return false;
            }
        }
        foreach ($property->getAttributes(Groups::class) as $groups) {
            foreach ($groups->newInstance()->groups as $group) {
                if (1 !== preg_match('/(:read|read)$/', $group)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @return iterable<string, string> every PHP source, by its path under src */
    private static function sources(): iterable
    {
        $root = realpath(self::SOURCE);
        self::assertIsString($root);
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file instanceof \SplFileInfo && 'php' === $file->getExtension()) {
                $source = file_get_contents($file->getPathname());
                self::assertIsString($source);
                yield substr($file->getPathname(), \strlen($root) + 1) => $source;
            }
        }
    }
}
