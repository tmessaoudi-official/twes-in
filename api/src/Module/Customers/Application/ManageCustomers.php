<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Customers\Application;

use App\Audit\Application\AuditEntry;
use App\Audit\Application\AuditTrail;
use App\Audit\Application\DeclaresAuditActions;
use App\Audit\Domain\ValueObject\AuditScope;
use App\Audit\Domain\ValueObject\Recorded;
use App\CustomFields\Domain\Exception\InvalidCustomFieldValue;
use App\CustomFields\Domain\Model\CustomFieldDefinition;
use App\CustomFields\Domain\Repository\CustomFieldDefinitionRepository;
use App\CustomFields\Domain\Service\CustomFieldValues;
use App\CustomFields\Domain\ValueObject\CustomFieldEntity;
use App\Fiscal\Application\Preset\FiscalPresets;
use App\Fiscal\Application\Preset\IdentifierRules;
use App\Fiscal\Domain\Model\CustomerTaxRegime;
use App\Fiscal\Domain\Repository\CustomerTaxRegimeRepository;
use App\Fiscal\Domain\Repository\TaxComponentRepository;
use App\Module\Customers\Domain\Exception\InvalidCustomer;
use App\Module\Customers\Domain\Model\Customer;
use App\Module\Customers\Domain\Model\CustomerGroup;
use App\Module\Customers\Domain\Repository\CustomerGroupRepository;
use App\Module\Customers\Domain\Repository\CustomerRepository;
use App\Module\Customers\Domain\ValueObject\CustomerSearch;
use App\Shared\Application\RecordChanged;
use App\Shared\Application\RecordVersions;
use App\Shared\Application\Transactions;
use App\Shared\Application\TypedPhones;
use App\Shared\Domain\Service\TypedText;
use App\Shared\Domain\ValueObject\CheckMark;
use App\Shared\Domain\ValueObject\Page;
use App\Shared\Domain\ValueObject\PageRequest;
use App\Tenancy\Domain\Model\Company;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * A company's customers. The company's fiscal preset decides the regimes a customer may be given and the registration
 * numbers a domestic business customer must carry; a customer billed abroad carries none of them. Default taxes are
 * the company's active ones that the customer's regime charges. Custom field values are checked against the company's
 * fields for customers. Audited with the names of the fields a revision
 * changed, never their values: a customer may be a private person.
 */
final readonly class ManageCustomers implements DeclaresAuditActions
{
    public const string ENTITY_TYPE = 'customer';
    public const string CREATED = 'customer.created';
    public const string REVISED = 'customer.revised';

    public function __construct(
        private CustomerRepository $customers,
        private CustomerGroupRepository $groups,
        private CustomerTaxRegimeRepository $regimes,
        private TaxComponentRepository $taxes,
        private FiscalPresets $presets,
        private AuditTrail $audit,
        private ClockInterface $clock,
        private CustomFieldDefinitionRepository $customFields,
        private Transactions $transactions,
        private RecordVersions $versions,
        private CustomerCheckScopes $checkScopes,
        private TypedPhones $phones,
    ) {
    }

    /** @return list<Customer> */
    public function list(Company $company): array
    {
        return $this->customers->ofCompany($company->getId());
    }

    /** @return Page<Customer> */
    public function search(Company $company, CustomerSearch $search, PageRequest $page): Page
    {
        return $this->customers->search($company->getId(), $search->withCheckScope($this->checkScopes->of($company)), $page);
    }

    /** @throws CustomerNotFound */
    public function get(Company $company, Uuid $id): Customer
    {
        return $this->customers->ofIdInCompany($id, $company->getId()) ?? throw new CustomerNotFound();
    }

    /**
     * @throws CustomerNumberTaken
     * @throws InvalidCustomer
     */
    public function create(Company $company, CustomerInput $input, ?Uuid $actorUserId): Customer
    {
        $input = $this->withNumbersAsStored($company, $input);

        return $this->transactions->run(function () use ($company, $input, $actorUserId): Customer {
            if (null !== $this->customers->ofNumberInCompany(TypedText::trimmed($input->number), $company->getId())) {
                throw new CustomerNumberTaken();
            }
            $input = $this->withPhoneAsKept($company, $input, null);
            [$group, $regime] = $this->checked($company, $input, null);
            $values = $this->customFieldValues($company, $input, null);
            $customer = Customer::create($company, $input->number, $input->profile, $group, $regime, $input->defaultTaxComponentIds, $this->clock->now());
            $customer->reviseCustomFields($values, $this->clock->now());
            if (!$input->isActive) {
                $customer->revise($input->number, $input->profile, $group, $regime, $input->defaultTaxComponentIds, false, $this->clock->now());
            }
            if (null !== $input->mark) {
                $customer->reviseCheck($input->mark, $this->clock->now());
            }
            $this->customers->save($customer);
            $this->record($company, $customer->getId(), self::CREATED, [], $actorUserId);

            return $customer;
        });
    }

    /**
     * `$version` is the one the editor opened; an import, which revises what it finds, names none.
     *
     * @throws CustomerNotFound
     * @throws CustomerNumberTaken
     * @throws CustomerMergedAway
     * @throws InvalidCustomer
     * @throws RecordChanged
     */
    public function revise(Company $company, Uuid $id, CustomerInput $input, ?Uuid $actorUserId, ?int $version = null): Customer
    {
        $input = $this->withNumbersAsStored($company, $input);

        return $this->transactions->run(function () use ($company, $id, $input, $actorUserId, $version): Customer {
            $customer = $this->get($company, $id);
            if (null !== $version) {
                $this->versions->expect($customer, $version);
            }
            if ($customer->isMergedAway()) {
                throw new CustomerMergedAway(\sprintf('The customer %s was merged into %s: it is no longer revised.', $customer->getNumber(), $customer->standingFor()->getNumber()));
            }
            $holder = $this->customers->ofNumberInCompany(TypedText::trimmed($input->number), $company->getId());
            if (null !== $holder && !$holder->getId()->equals($customer->getId())) {
                throw new CustomerNumberTaken();
            }
            $input = $this->withPhoneAsKept($company, $input, $customer);
            [$group, $regime] = $this->checked($company, $input, $customer);
            $values = $this->customFieldValues($company, $input, $customer);

            $changed = $customer->revise($input->number, $input->profile, $group, $regime, $input->defaultTaxComponentIds, $input->isActive, $this->clock->now());
            $changed = [...$changed, ...$customer->reviseCustomFields($values, $this->clock->now())];
            if (null !== $input->mark) {
                $changed = [...$changed, ...$customer->reviseCheck($input->mark, $this->clock->now())];
            }
            if ([] !== $changed) {
                $this->customers->save($customer);
                $this->record($company, $customer->getId(), self::REVISED, ['fields' => $changed], $actorUserId);
            }

            return $customer;
        });
    }

    /**
     * Marks the customer « À vérifier » with this note, for what the night found it keeps that today's rules refuse
     * (row 208). One already marked, for this or any reason, or merged away is left as it is, so the night marks a
     * record once and a person who takes the mark off without correcting the value finds it again the next night.
     * No one acts: the journal names no person.
     *
     * @return bool whether the customer was marked
     *
     * @throws CustomerNotFound
     */
    public function markToCheck(Company $company, Uuid $id, string $note): bool
    {
        return $this->transactions->run(function () use ($company, $id, $note): bool {
            $customer = $this->get($company, $id);
            if ($customer->getCheckMark()->marked || $customer->isMergedAway()) {
                return false;
            }
            $changed = $customer->reviseCheck(new CheckMark(true, mb_substr($note, 0, CheckMark::NOTE_MAX)), $this->clock->now());
            $this->customers->save($customer);
            $this->record($company, $customer->getId(), self::REVISED, ['fields' => $changed], null);

            return true;
        });
    }

    /** The input with its registration numbers as the preset stores them, however they were written. */
    private function withNumbersAsStored(Company $company, CustomerInput $input): CustomerInput
    {
        $preset = $this->presets->get($company->getFiscalPreset());

        return $input->withProfile($input->profile->withIdentifiers(IdentifierRules::normalised($preset, $input->profile->identifiers, IdentifierRules::BUSINESS_CUSTOMER)));
    }

    /**
     * The input with its phone as kept (`TypedPhones`), read by the billing address's country, else the company's. A
     * number of the right length outside the ones handed out marks the customer « À vérifier », its line after the note
     * the mark already carries, so the person who looks reads what to check.
     *
     * @throws InvalidCustomer
     */
    private function withPhoneAsKept(Company $company, CustomerInput $input, ?Customer $current): CustomerInput
    {
        $profile = $input->profile;
        $kept = $this->phones->kept($profile->phone, $current?->getProfile()->phone, $profile->billingAddress->countryCode ?? $company->getCountryCode());
        if (null !== $kept->refusal) {
            throw new InvalidCustomer('phone', $kept->refusal->message, $kept->refusal->reason, $kept->refusal->params);
        }
        $input = $input->withProfile($profile->withPhone($kept->phone));
        $mark = $kept->markOn($input->mark ?? $current?->getCheckMark());

        return null === $mark ? $input : $input->withMark($mark);
    }

    /**
     * What the input names, found and checked against the company and its preset.
     *
     * @return array{CustomerGroup|null, CustomerTaxRegime}
     */
    private function checked(Company $company, CustomerInput $input, ?Customer $current): array
    {
        $preset = $this->presets->get($company->getFiscalPreset());

        $code = TypedText::trimmed($input->taxRegimeCode);
        $offered = \in_array($code, array_map(static fn ($regime): string => $regime->code, $preset->customerTaxRegimes), true);
        // A regime the preset stopped listing stays with the customers who already have it.
        $kept = null !== $current && $current->getTaxRegime()->getCode() === $code;
        $regime = $offered || $kept ? $this->regimes->ofPresetAndCode($company->getFiscalPreset(), $code) : null;
        if (null === $regime) {
            throw new InvalidCustomer('taxRegime', \sprintf('The %s preset offers customers no regime "%s".', $preset->country, $code), 'unknown_tax_regime', ['code' => $code]);
        }

        $group = null;
        if (null !== $input->customerGroupId) {
            $group = $this->groups->ofIdInCompany($input->customerGroupId, $company->getId())
                ?? throw new InvalidCustomer('customerGroupId', 'No customer group of this company has this id.', 'unknown_group_id');
        }

        foreach ($input->defaultTaxComponentIds as $taxId) {
            $tax = $this->taxes->ofIdInCompany($taxId, $company->getId());
            if (null === $tax || !$tax->isActive()) {
                throw new InvalidCustomer('defaultTaxComponentIds', \sprintf('No active tax of this company has the id %s.', $taxId->toRfc4122()), 'unknown_tax', ['id' => $taxId->toRfc4122()]);
            }
            if (\in_array($tax->getFamily(), $regime->getExcludedFamilies(), true)) {
                throw new InvalidCustomer('defaultTaxComponentIds', \sprintf('The %s regime does not charge %s.', $regime->getCode(), $tax->getCode()), 'regime_excludes_tax', ['tax' => $tax->getName()]);
            }
        }

        $domesticBusiness = $input->profile->isDomesticBusiness($company->getCountryCode());
        $requiredOf = $domesticBusiness && !$input->identifiersMayFollow ? IdentifierRules::BUSINESS_CUSTOMER : '';
        // A number the customer already lacked is « À vérifier », not a reason to refuse every other change: the
        // invoice is where the law asks for it. One it held may not be taken away.
        $excused = $current?->missingIdentifiers($this->checkScopes->of($company)) ?? [];
        $refusal = IdentifierRules::refusal($preset, $input->profile->identifiers, $requiredOf, $excused);
        if (null !== $refusal) {
            throw new InvalidCustomer($refusal->field, $refusal->message, $refusal->reason, $refusal->params);
        }

        return [$group, $regime];
    }

    /** @return array<string, string|int|float|bool> */
    private function customFieldValues(Company $company, CustomerInput $input, ?Customer $current): array
    {
        $rules = array_map(static fn (CustomFieldDefinition $field) => $field->rule(), $this->customFields->ofCompanyAndEntity($company->getId(), CustomFieldEntity::Customer));
        try {
            return CustomFieldValues::checked($rules, $input->customFields, $current?->getCustomFields() ?? []);
        } catch (InvalidCustomFieldValue $refused) {
            throw new InvalidCustomer($refused->field, $refused->getMessage(), $refused->reason, $refused->params);
        }
    }

    /** @param array<string, mixed> $changes */
    private function record(Company $company, Uuid $customerId, string $action, array $changes, ?Uuid $actorUserId): void
    {
        $this->audit->record(new AuditEntry(self::ENTITY_TYPE, $customerId, $action, $actorUserId, $changes, $company->getId()));
    }

    public function auditActions(): array
    {
        return [
            self::CREATED => new Recorded(self::ENTITY_TYPE, AuditScope::Company),
            self::REVISED => new Recorded(self::ENTITY_TYPE, AuditScope::Company),
        ];
    }
}
