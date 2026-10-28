<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Architecture;

use App\Module\Invoices\Application\Reminder\LateFeeSettings;
use App\Settings\Application\SupportSettings;
use App\Settings\Domain\ValueObject\SettingDefinition;
use App\Shared\Domain\Service\ContactRules;
use App\Shared\Domain\Service\TypedText;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Constraints\Iban;
use Symfony\Component\Validator\Validation;

/**
 * An example a hint or a refusal offers is what a person copies into the field it is shown under, so it passes that
 * field's own rule as copied: the texts keep a figure whole with a no-break space between its groups (« +216 20 123
 * 456 »), and the rule reads every such space as the space it is. Every text showing a figure split by a space other
 * than the keyboard's, or a list of figures between semicolons, is named in web/src/app/shared/form/hint-examples.json,
 * either with the field it is an example for or with why it is none, which the web's specs read too.
 */
final class HintExamplesTest extends TestCase
{
    private const string TEXTS = __DIR__.'/../../../web/public/i18n';
    private const string CLASSES = __DIR__.'/../../../web/src/app/shared/form/hint-examples.json';
    /** A figure split by a space, or figures listed between semicolons: what may be an example to copy. */
    private const array FIGURES = ['/\d\p{Zs}\d/u', '/\d\p{Zs}*;\p{Zs}*\d/u'];
    /** What an example of each kind looks like in its sentence, marks a copy brings before it included. */
    private const array EXAMPLE = [
        'phone' => '/\p{Cf}*\+[0-9][0-9\p{Zs}().\-]*[0-9]/u',
        'support_phone' => '/\p{Cf}*\+[0-9][0-9\p{Zs}().\-]*[0-9]/u',
        'whatsapp' => '/\p{Cf}*\+[0-9][0-9\p{Zs}().\-]*[0-9]/u',
        'iban' => '/[A-Z]{2}[0-9]{2}(?:\p{Zs}?[A-Z0-9])+/u',
        'late_fee_tiers' => '/[0-9]+(?:[.,][0-9]+)?(?:\s*;\s*[0-9]+(?:[.,][0-9]+)?)+(?:\s*%)?/u',
    ];

    public function testEveryTextShowingASplitFigureIsNamedAndEveryNameIsStillShown(): void
    {
        $classes = self::classes();
        $named = [...array_keys($classes['examples']), ...array_keys($classes['notExamples'])];
        $found = [];
        foreach (self::texts() as $where => $texts) {
            foreach ($texts as $key => $text) {
                foreach (self::FIGURES as $figure) {
                    if (1 === preg_match($figure, $text)) {
                        self::assertContains($key, $named, "$where $key shows a figure with a space or a semicolon inside: name it in hint-examples.json, with its field or why it is none");
                        $found[$key] = true;
                    }
                }
            }
        }

        self::assertSame([], array_values(array_diff($named, array_keys($found))), 'a name in hint-examples.json no text shows any more');
        self::assertGreaterThanOrEqual(14, \count($found), 'fewer texts found than were named on 2026-10-28: the texts moved');
    }

    /** The patterns the web's specs run the examples through are the ones the API sends with each setting. */
    public function testThePatternsTheWebIsHandedAreTheSettingsOwn(): void
    {
        foreach (self::classes()['apiPatterns'] as $kind => $pattern) {
            self::assertSame(self::setting($kind)->pattern, $pattern, $kind);
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function examples(): iterable
    {
        $classes = self::classes();
        foreach (self::texts() as $where => $texts) {
            foreach ($classes['examples'] as $key => $kind) {
                if (!isset($texts[$key])) {
                    continue;
                }
                $count = preg_match_all(self::EXAMPLE[$kind], $texts[$key], $offered);
                self::assertGreaterThan(0, $count, "$where $key offers no $kind example");
                foreach ($offered[0] as $example) {
                    yield "$where $key « $example »" => [$kind, $example];
                }
            }
        }
    }

    #[DataProvider('examples')]
    public function testAnExampleAsCopiedPassesItsFieldsOwnRule(string $kind, string $example): void
    {
        $typed = TypedText::trimmed($example);
        switch ($kind) {
            case 'phone':
                self::assertNull(ContactRules::phone($typed));
                break;
            case 'iban':
                $account = ContactRules::account($typed);
                self::assertNull(ContactRules::iban($account));
                self::assertCount(0, Validation::createValidator()->validate($account, new Iban()), 'its country\'s length');
                self::assertMatchesRegularExpression('/^[A-Z0-9]+$/D', $account, 'kept without its spaces');
                break;
            case 'late_fee_tiers':
                self::assertNull(self::setting($kind)->refusal($example));
                self::assertSame('5.000', LateFeeSettings::feeFor($example, 1, '100.000', 3), 'its first tier');
                self::assertSame('2.000', LateFeeSettings::feeFor($example, 3, '100.000', 3), 'its share');
                break;
            default:
                self::assertNull(self::setting($kind)->refusal($example));
        }
    }

    private static function setting(string $kind): SettingDefinition
    {
        $key = match ($kind) {
            'support_phone' => SupportSettings::PHONE,
            'whatsapp' => SupportSettings::WHATSAPP,
            'late_fee_tiers' => LateFeeSettings::TIERS,
            default => self::fail("$kind is no setting"),
        };
        foreach ([...(new SupportSettings())->settings(), ...(new LateFeeSettings())->settings()] as $definition) {
            if ($definition->key === $key) {
                return $definition;
            }
        }
        self::fail("$key is not declared");
    }

    /** @return array{examples: array<string, string>, notExamples: array<string, string>, apiPatterns: array<string, string>} */
    private static function classes(): array
    {
        /** @var array{examples: array<string, string>, notExamples: array<string, string>, apiPatterns: array<string, string>} $classes */
        $classes = json_decode((string) file_get_contents(self::CLASSES), true, 512, \JSON_THROW_ON_ERROR);

        return $classes;
    }

    /**
     * Every text file, its texts by dotted key.
     *
     * @return array<string, array<string, string>>
     */
    private static function texts(): array
    {
        $files = [...(glob(self::TEXTS.'/*.json') ?: []), ...(glob(self::TEXTS.'/*/*.json') ?: [])];
        self::assertNotSame([], $files);
        $texts = [];
        foreach ($files as $file) {
            $texts[substr($file, \strlen(self::TEXTS) + 1)] = self::flattened(json_decode((string) file_get_contents($file), true, 512, \JSON_THROW_ON_ERROR));
        }

        return $texts;
    }

    /** @return array<string, string> */
    private static function flattened(mixed $node, string $prefix = ''): array
    {
        if (\is_string($node)) {
            return [$prefix => $node];
        }
        if (!\is_array($node)) {
            return [];
        }
        $flat = [];
        foreach ($node as $key => $child) {
            $flat = [...$flat, ...self::flattened($child, '' === $prefix ? (string) $key : "$prefix.$key")];
        }

        return $flat;
    }
}
