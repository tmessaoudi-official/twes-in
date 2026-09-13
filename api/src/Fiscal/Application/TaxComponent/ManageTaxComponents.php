<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Fiscal\Application\TaxComponent;

use App\Audit\Application\AuditEntry;
use App\Audit\Application\AuditTrail;
use App\Fiscal\Application\CurrencyScales;
use App\Fiscal\Domain\InvalidFiscalValue;
use App\Fiscal\Domain\TaxComponent;
use App\Fiscal\Domain\TaxComponentRepository;
use App\Fiscal\Domain\TaxFamily;
use App\Tenancy\Domain\Company;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/** A company's own tax components: listed, added and revised, each change audited. */
final readonly class ManageTaxComponents
{
    public const string ENTITY_TYPE = 'tax_component';
    public const string CREATED = 'tax_component.created';
    public const string REVISED = 'tax_component.revised';

    public function __construct(
        private TaxComponentRepository $components,
        private CurrencyScales $scales,
        private AuditTrail $audit,
        private ClockInterface $clock,
    ) {
    }

    /** @return list<TaxComponent> */
    public function list(Company $company): array
    {
        return $this->components->ofCompany($company->getId());
    }

    /**
     * @throws TaxComponentCodeTaken
     * @throws InvalidFiscalValue
     */
    public function create(Company $company, TaxComponentDraft $draft, ?Uuid $actorUserId): TaxComponent
    {
        $family = TaxFamily::tryFrom($draft->family) ?? throw new InvalidFiscalValue('family', \sprintf('"%s" is not a tax family.', $draft->family));
        if (null !== $this->components->ofCodeInCompany($draft->code, $company->getId())) {
            throw new TaxComponentCodeTaken(\sprintf('The company already has a tax coded %s.', $draft->code));
        }

        $component = TaxComponent::create(
            $company,
            $draft->code,
            $draft->name,
            $family,
            $draft->rate,
            $draft->amount,
            $draft->threshold,
            $draft->entersVatBase,
            $draft->isDefault,
            $draft->exemptionMention,
            $draft->sortOrder,
            $this->scales->of($company->getCurrency()),
            $this->clock->now(),
        );
        $this->components->save($component);
        $this->audit->record(new AuditEntry(self::ENTITY_TYPE, $component->getId(), self::CREATED, $actorUserId, self::snapshot($component), $company->getId()));

        return $component;
    }

    /**
     * @throws TaxComponentNotFound for a component that does not exist or belongs to another company
     * @throws InvalidFiscalValue
     */
    public function revise(Company $company, Uuid $componentId, TaxComponentChanges $changes, ?Uuid $actorUserId): TaxComponent
    {
        $component = $this->components->ofIdInCompany($componentId, $company->getId())
            ?? throw new TaxComponentNotFound('No such tax component.');

        $changed = $component->revise(
            $changes->name,
            $changes->rate,
            $changes->amount,
            $changes->threshold,
            $changes->entersVatBase,
            $changes->isDefault,
            $changes->isActive,
            $changes->exemptionMention,
            $changes->sortOrder,
            $this->scales->of($company->getCurrency()),
            $this->clock->now(),
        );
        if ($changed) {
            $this->components->save($component);
            $this->audit->record(new AuditEntry(self::ENTITY_TYPE, $component->getId(), self::REVISED, $actorUserId, self::snapshot($component), $company->getId()));
        }

        return $component;
    }

    /** @return array<string, mixed> */
    private static function snapshot(TaxComponent $component): array
    {
        return [
            'code' => $component->getCode(),
            'name' => $component->getName(),
            'family' => $component->getFamily()->value,
            'rate' => $component->getRate(),
            'amount' => $component->getAmount(),
            'threshold' => $component->getThreshold(),
            'enters_vat_base' => $component->entersVatBase(),
            'is_default' => $component->isDefault(),
            'is_active' => $component->isActive(),
        ];
    }
}
