<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Architecture;

use ApiPlatform\Metadata\Resource\Factory\ResourceNameCollectionFactoryInterface;
use App\Settings\Application\SettingCatalog;
use App\Settings\Domain\ValueObject\SettingChain;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\Constraints\Composite;
use Symfony\Component\Validator\Constraints\Count;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\Range;
use Symfony\Component\Validator\Mapping\ClassMetadata;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * A resource constraint's payload is what a screen shows when it refuses a field: its reason must be one the screens
 * translate, every placeholder its text names among its params, and a length's maximum the one the constraint holds,
 * or the form would say one limit while the API kept another.
 */
final class RefusalReasonsTest extends KernelTestCase
{
    public function testEveryReasonIsTranslatedAndEveryMaximumIsTheConstraints(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $validator = $container->get(ValidatorInterface::class);
        $translated = self::translated('fr', self::FORM_REFUSALS);

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
                        // A placeholder the payload does not fill is shown as written, « {{max}} », to the person refused.
                        $said = \is_string($payload['reason']) ? $translated[$payload['reason']] ?? null : null;
                        preg_match_all('/\{\{\s*(\w+)\s*\}\}/', \is_string($said) ? $said : '', $named);
                        foreach (array_diff($named[1], \is_array($params) ? array_keys($params) : []) as $unfilled) {
                            $wrong[] = \sprintf('%s: its text names {{%s}}, which the payload does not send', $where, $unfilled);
                        }
                        $max = \is_array($params) ? $params['max'] ?? null : 'params that are not a map';
                        if (null !== $max && (!($constraint instanceof Length || $constraint instanceof Range || $constraint instanceof Count) || $constraint->max !== $max)) {
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
     * A domain refusal of any field (a customer's, a document's, a stock location's, the company's own, a schedule's)
     * reaches the form the same way, through `DomainRule::refused`: every reason one is written with has its screen text. A reason is the snake_case literal of the refusal's statement (fields, parameters and
     * messages are not snake_case).
     */
    public function testEveryReasonADomainRefusesAFieldWithIsTranslated(): void
    {
        $root = \dirname(__DIR__, 2);
        $translated = self::translated('fr', self::FORM_REFUSALS);
        $statements = 0;
        $untranslated = [];
        foreach (['src'] as $directory) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator("$root/$directory", \FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                \assert($file instanceof \SplFileInfo);
                $source = (string) file_get_contents($file->getPathname());
                preg_match_all('/new (?:InvalidCustomer|InvalidVendor|IdentifierRefusal|InvalidCustomFieldValue|InvalidCompanyProfile|InvalidProduct|InvalidExpense|InvalidStockMovement|InvalidReorderPoint|InvalidSchedule|InvalidRecurringInvoice|InvalidRecurringExpense|InvalidStockLocation|InvalidProductCategory|InvalidInvoice|InvalidDeliveryNote|InvalidQuote|InvalidPurchaseOrder|InvalidVenue|InvalidFiscalValue|QuoteInvoicingRefused|InvalidPriceList|InvalidCustomerGroup|InvalidCustomFieldDefinition|InvalidContact|InvalidNumbering|InvalidEstablishment|InvalidDocument)\((.*?)\);/s', $source, $found);
                // A custom field's refusals go through its helper, one per arm of a match.
                preg_match_all('/self::refuse\((.*?)\),$/m', $source, $helped);
                // A record no new document may name says why as a reason of its own, which the documents' refusals spread;
                // so does what keeps a document from being delivered or taking a deposit, which several paths refuse with.
                preg_match_all('/function (?:switchedOffReason|refusal|refusalReason|billedOtherwise)\([^)]*\): \??array\s*\{(.*?)\n    \}/s', $source, $switchedOff);
                foreach ([...$found[1], ...$helped[1], ...$switchedOff[1]] as $arguments) {
                    ++$statements;
                    preg_match_all("/'([a-z]+(?:_[a-z]+)+)'/", $arguments, $reasons);
                    foreach ($reasons[1] ?: ['invalid_value'] as $reason) {
                        if (!\array_key_exists($reason, $translated)) {
                            $untranslated[] = "$reason in {$file->getFilename()}";
                        }
                    }
                }
            }
        }

        self::assertGreaterThan(90, $statements, 'the domain refusals are found');
        self::assertSame([], $untranslated);
    }

    /**
     * A document's or a stock movement's refusal names its reason, so the screen says why in the reader's words rather than « Valeur refusée. »:
     * a reason written in the call, spread from a helper that gives one, or carried from the refusal it re-throws.
     */
    public function testEveryRefusalOfADocumentNamesItsReason(): void
    {
        $root = \dirname(__DIR__, 2).'/src';
        $statements = 0;
        $reasonless = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            \assert($file instanceof \SplFileInfo);
            $source = (string) file_get_contents($file->getPathname());
            preg_match_all('/new (?:Invalid(?:Invoice|Quote|DeliveryNote|PurchaseOrder|StockMovement)|QuoteInvoicingRefused)(\(((?:[^()\'"]++|\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*"|(?1))*)\))/s', $source, $found, \PREG_OFFSET_CAPTURE);
            foreach ($found[2] as [$arguments, $offset]) {
                ++$statements;
                if (1 === preg_match("/'[a-z]+(?:_[a-z]+)+'|\\.\\.\\.|->reason\\b/", $arguments)) {
                    continue;
                }
                $reasonless[] = \sprintf('%s:%d', substr($file->getPathname(), \strlen($root) + 1), substr_count($source, "\n", 0, $offset) + 1);
            }
        }
        self::assertGreaterThan(230, $statements, 'the documents\' refusals are found');
        self::assertSame([], $reasonless);
    }

    /**
     * What an import refuses a row with by itself, and what the domain's own rules refuse a value with, reaches the
     * report and the form as a reason: each has its screen text. A row's reason is an argument after its column, the
     * engine's a row's `code`, the domain's a `Refusal`'s first.
     */
    public function testEveryReasonAnImportOrADomainRuleRefusesWithIsTranslated(): void
    {
        $root = \dirname(__DIR__, 2).'/src';
        $translated = self::translated();
        $found = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            \assert($file instanceof \SplFileInfo);
            // The settings engine's refusals are said by the settings screens, which open their own texts beside these:
            // every one a declared setting can answer is held to them below.
            if (str_starts_with($file->getPathname(), "$root/Settings/")) {
                continue;
            }
            $source = (string) file_get_contents($file->getPathname());
            // The arguments up to the parenthesis that closes them, quoted text and nested calls included: a statement
            // ending `),` rather than `);` would otherwise run on into the next one's arguments.
            preg_match_all('/new (?:RowRejected|StockImportRefused)(\(((?:[^()\'"]++|\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*"|(?1))*)\))/s', $source, $statements);
            foreach ($statements[2] as $arguments) {
                preg_match_all("/, '([a-z]+(?:_[a-z]+)+)'(?=\\s*(?:[,)]|$))/", $arguments, $reasons);
                foreach ($reasons[1] as $reason) {
                    $found[$reason][] = $file->getFilename();
                }
            }
            preg_match_all("/'code' => '([a-z]+(?:_[a-z]+)+)'/", $source, $codes);
            preg_match_all("/new Refusal\\('([a-z]+(?:_[a-z]+)+)'/", $source, $refusals);
            foreach ([...$codes[1], ...$refusals[1]] as $reason) {
                $found[$reason][] = $file->getFilename();
            }
        }

        self::assertArrayHasKey('too_many_cells', $found, 'the engine\'s own codes are found');
        self::assertArrayHasKey('invalid_iban', $found, 'the domain\'s rules are found');
        self::assertGreaterThan(34, \count($found), 'the import refusals are found');
        $untranslated = [];
        foreach ($found as $reason => $where) {
            if (!\array_key_exists($reason, $translated)) {
                $untranslated[] = $reason.' in '.implode(', ', array_unique($where));
            }
        }
        self::assertSame([], $untranslated);
    }

    /**
     * What an import points out about a row it takes, a note, reaches the report by its code alone: each has its text
     * in every language the import page speaks, or the person reads the key.
     */
    public function testEveryNoteAnImportWritesHasItsTextInEveryLanguage(): void
    {
        $root = \dirname(__DIR__, 2);
        $found = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator("$root/src", \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            \assert($file instanceof \SplFileInfo);
            preg_match_all("/->note\\([^,]+, (?:[^;]*?\\? )?'([a-z]+(?:_[a-z]+)+)'(?: : '([a-z]+(?:_[a-z]+)+)')?/", (string) file_get_contents($file->getPathname()), $notes);
            foreach ([...$notes[1], ...$notes[2]] as $code) {
                if ('' !== $code) {
                    $found[$code] = $file->getFilename();
                }
            }
        }

        self::assertArrayHasKey('name_shared', $found, 'a note chosen between two is found');
        self::assertArrayHasKey('no_taxes', $found, 'a product created without a tax is pointed out');
        $missing = [];
        foreach (glob(\dirname($root).'/web/public/i18n/import/*.json') ?: [] as $part) {
            $texts = json_decode((string) file_get_contents($part), true, 512, \JSON_THROW_ON_ERROR);
            self::assertIsArray($texts);
            $notes = \is_array($texts['import'] ?? null) && \is_array($texts['import']['notes'] ?? null) ? $texts['import']['notes'] : [];
            foreach ($found as $code => $where) {
                if (!\is_string($notes[$code] ?? null) || '' === $notes[$code]) {
                    $missing[] = \sprintf('%s in %s: no text in %s', $code, $where, basename($part));
                }
            }
        }

        self::assertSame([], $missing);
    }

    /**
     * A settings save refuses a key with a reason its field is told in: every refusal a declared setting can answer has
     * its text, in the screens' file or in the settings refusals' part the settings screens open, in every language,
     * and every placeholder of that text among the refusal's parameters.
     */
    public function testEveryRefusalASettingCanAnswerIsTranslated(): void
    {
        self::bootKernel();
        $catalog = static::getContainer()->get(SettingCatalog::class);
        $wrong = [];
        $refusals = 0;
        foreach (['fr', 'en', 'ar'] as $language) {
            $translated = self::translated($language, self::SETTINGS_REFUSALS);
            // A phone setting is typed in the phone field, whose refusals the platform's adapters open with the form refusals.
            $phones = self::translated($language, self::SETTINGS_REFUSALS, self::FORM_REFUSALS);
            // What a save refuses a key with beyond its value: a level it is not kept at, a key named twice.
            foreach (['level_refused', 'invalid_value'] as $ofTheSave) {
                if (!\is_string($translated[$ofTheSave] ?? null)) {
                    $wrong[] = \sprintf('a save: no %s text for %s', $language, $ofTheSave);
                }
            }
            foreach (SettingChain::cases() as $chain) {
                foreach ($catalog->ofChain($chain) as $definition) {
                    foreach ($definition->refusals() as $refusal) {
                        ++$refusals;
                        $said = ($definition->phone ? $phones : $translated)[$refusal->reason] ?? null;
                        if (!\is_string($said) || '' === $said) {
                            $wrong[] = \sprintf('%s: no %s text for %s', $definition->key, $language, $refusal->reason);
                            continue;
                        }
                        preg_match_all('/\{\{\s*(\w+)\s*\}\}/', $said, $named);
                        foreach (array_diff($named[1], array_keys($refusal->params)) as $unfilled) {
                            $wrong[] = \sprintf('%s: its %s text for %s names {{%s}}, which the refusal does not send', $definition->key, $language, $refusal->reason, $unfilled);
                        }
                    }
                }
            }
        }

        self::assertGreaterThan(300, $refusals, 'the declared settings are found');
        self::assertSame([], $wrong);
    }

    /** The texts the settings screens open before saying a settings refusal, beside the screens' own file. */
    private const string SETTINGS_REFUSALS = 'settings-refusals';

    /** The texts a form's adapter opens when a save comes back refused on its fields (`FORM_REFUSAL_TEXTS`). */
    private const string FORM_REFUSALS = 'form-refusals';

    /** @return array<mixed> the screens' texts for a refusal, by reason, with those of the texts parts named over them */
    private static function translated(string $language = 'fr', string ...$parts): array
    {
        $translated = self::rejections(\dirname(__DIR__, 3).'/web/public/i18n/'.$language.'.json');
        foreach ($parts as $part) {
            $translated = [...$translated, ...self::rejections(\dirname(__DIR__, 3).'/web/public/i18n/'.$part.'/'.$language.'.json')];
        }

        return $translated;
    }

    /** @return array<mixed> */
    private static function rejections(string $file): array
    {
        $texts = json_decode((string) file_get_contents($file), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($texts, $file);
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
