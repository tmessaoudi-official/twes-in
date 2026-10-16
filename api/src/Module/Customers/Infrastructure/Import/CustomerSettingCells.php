<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Customers\Infrastructure\Import;

use App\Fiscal\Application\CurrencyScales;
use App\ImportExport\Application\ImportCells;
use App\ImportExport\Application\ImportColumn;
use App\ImportExport\Application\ImportRecord;
use App\ImportExport\Application\RowFaults;
use App\ImportExport\Application\RowRejected;
use App\Settings\Application\ChangeSettings;
use App\Settings\Application\SettingCatalog;
use App\Settings\Application\SettingContext;
use App\Settings\Domain\Repository\SettingRepository;
use App\Settings\Domain\ValueObject\SettingAddress;
use App\Settings\Domain\ValueObject\SettingDefinition;
use App\Settings\Domain\ValueObject\SettingLevel;
use App\Tenancy\Domain\Company;
use Symfony\Component\Uid\Uuid;

/**
 * What a customer's own settings a file may fill in beside the customer (docs/SPEC.md § 7, 2026-10-09 10:40, ruling 11):
 * its payment terms, its credit limit, the language its documents are printed in and the provision it is exempt
 * under. Each is stored at the customer's level of the parties chain, as the customer's settings tab stores it, and is
 * held to the setting's own declaration, so the file allows exactly what the tab does. A blank cell keeps what is set.
 */
final readonly class CustomerSettingCells
{
    private const string TERMS = 'payment_terms_days';
    private const string CREDIT = 'credit_limit';
    private const string LANGUAGE = 'document_language';
    private const string EXEMPTION = 'exemption_reference';

    /** The setting each column fills in. */
    private const array SETTING_OF = [
        self::TERMS => 'document.payment_terms_days',
        self::CREDIT => 'credit.limit',
        self::LANGUAGE => 'document.language',
        self::EXEMPTION => 'document.exemption_reference',
    ];

    /** A language by its code or as a person names it, in French and in English. */
    private const array LANGUAGES = [
        'français' => 'fr', 'francais' => 'fr', 'french' => 'fr',
        'anglais' => 'en', 'english' => 'en',
    ];

    public function __construct(
        private SettingCatalog $catalog,
        private ChangeSettings $settings,
        private CurrencyScales $scales,
        private SettingRepository $stored,
    ) {
    }

    /** Whether the column is one of a customer's own settings. */
    public static function covers(string $column): bool
    {
        return \array_key_exists($column, self::SETTING_OF);
    }

    /** @return list<ImportColumn> */
    public function columns(): array
    {
        return [
            new ImportColumn(self::TERMS, 'import.customers.payment_terms_days', false, '30', 'import.customers.payment_terms_days_note'),
            new ImportColumn(self::CREDIT, 'import.customers.credit_limit', false, '5000', 'import.customers.credit_limit_note'),
            new ImportColumn(self::LANGUAGE, 'import.customers.document_language', false, 'fr', 'import.customers.document_language_note'),
            new ImportColumn(self::EXEMPTION, 'import.customers.exemption_reference', false, null, 'import.customers.exemption_reference_note'),
        ];
    }

    /**
     * The row's settings, read and held to their declarations before anything is written; a refused one is kept
     * among the row's faults and left out.
     *
     * @return array<string, int|string> by setting key
     */
    public function read(Company $company, ImportRecord $record, RowFaults $faults): array
    {
        $values = [];
        foreach (self::SETTING_OF as $column => $key) {
            $cell = $record->value($column);
            if (null === $cell) {
                continue;
            }
            $definition = $this->catalog->definitionOf($key) ?? throw new \LogicException(\sprintf('The setting %s is not declared.', $key));
            $value = $faults->read(fn (): int|string => $this->value($company, $record, $column, $cell, $definition));
            if (null !== $value) {
                $values[$key] = $value;
            }
        }

        return $values;
    }

    /**
     * @throws RowRejected
     */
    private function value(Company $company, ImportRecord $record, string $column, string $cell, SettingDefinition $definition): int|string
    {
        $value = match ($column) {
            self::TERMS => 1 === preg_match('/^\d+$/D', trim($cell)) ? (int) trim($cell) : null,
            self::CREDIT => ImportCells::decimal($record, $column, $this->scales->of($company->getCurrency())),
            self::LANGUAGE => self::LANGUAGES[mb_strtolower(trim($cell))] ?? mb_strtolower(trim($cell)),
            default => $cell,
        };
        if (null === $value || null !== $definition->refusal($value)) {
            throw self::refused($column, $definition);
        }

        return $value;
    }

    /**
     * @param array<string, int|string> $values as `read` answered them
     */
    public function write(Company $company, Uuid $customerId, array $values, ?Uuid $actorUserId): void
    {
        $context = new SettingContext(company: $company, customerId: $customerId);
        foreach ($values as $key => $value) {
            $this->settings->change($context, $key, SettingLevel::Customer, $value, $actorUserId);
        }
    }

    /**
     * What each customer holds of its own, written as a file writes it, so an export brought back changes nothing: a
     * value the customer inherits is left out, as a blank cell keeps it. One read for the whole batch.
     *
     * @param list<Uuid> $customerIds
     *
     * @return array<string, array<string, string>> by customer id, then column
     */
    public function shown(Company $company, array $customerIds): array
    {
        $columnOf = array_flip(self::SETTING_OF);
        $addresses = array_map(static fn (Uuid $id): SettingAddress => SettingAddress::customer($company, $id), $customerIds);
        $shown = [];
        foreach ($this->stored->at($addresses) as $setting) {
            $column = $columnOf[$setting->getKey()] ?? null;
            $value = $setting->getValue();
            if (null === $column || !\is_scalar($value)) {
                continue;
            }
            foreach ($customerIds as $index => $id) {
                if ($setting->isAt($addresses[$index])) {
                    $shown[$id->toRfc4122()][$column] = (string) $value;

                    break;
                }
            }
        }

        return $shown;
    }

    /** Why a cell is refused, in the words the column's own refusals use, with the bounds its setting declares. */
    private static function refused(string $column, SettingDefinition $definition): RowRejected
    {
        return match ($column) {
            self::TERMS => new RowRejected($column, \sprintf('Payment terms run from %s to %s days.', $definition->min, $definition->max), 'invalid_payment_terms', ['max' => (int) $definition->max]),
            self::CREDIT => new RowRejected($column, \sprintf('A credit limit is an amount of %s or more, %1$s setting none.', $definition->min), 'invalid_credit_limit', ['min' => (string) $definition->min]),
            self::LANGUAGE => new RowRejected($column, \sprintf('Expected one of %s.', implode(', ', $definition->choices)), 'not_one_of', ['choices' => implode(', ', $definition->choices)]),
            default => new RowRejected($column, \sprintf('At most %d characters.', $length = $definition->maxLength ?? SettingDefinition::TEXT_MAX_LENGTH), 'invalid_text', ['max' => $length]),
        };
    }
}
