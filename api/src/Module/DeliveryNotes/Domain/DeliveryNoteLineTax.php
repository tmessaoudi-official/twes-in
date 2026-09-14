<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\DeliveryNotes\Domain;

use App\Fiscal\Domain\Calculation\Decimal;
use App\Fiscal\Domain\TaxComponent;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * A tax a line carries (docs/SPEC.md § 3 Fiscal presets: a line carries a set of components), with the code, rate and
 * VAT base behaviour the component had when the line was written: a company editing its tax later changes no note.
 */
#[ORM\Entity]
#[ORM\Table(name: 'delivery_note_line_tax')]
#[ORM\Index(name: 'idx_delivery_note_line_tax_line', columns: ['line_id'])]
#[ORM\Index(name: 'idx_delivery_note_line_tax_component', columns: ['tax_component_id'])]
class DeliveryNoteLineTax
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: DeliveryNoteLine::class, inversedBy: 'taxes')]
    #[ORM\JoinColumn(name: 'line_id', nullable: false, onDelete: 'CASCADE')]
    private DeliveryNoteLine $line;

    #[ORM\Column]
    private int $position;

    #[ORM\ManyToOne(targetEntity: TaxComponent::class)]
    #[ORM\JoinColumn(name: 'tax_component_id', nullable: false)]
    private TaxComponent $taxComponent;

    #[ORM\Column(length: 32)]
    private string $code;

    #[ORM\Column(type: Types::DECIMAL, precision: 6, scale: 3)]
    private string $rate;

    #[ORM\Column]
    private bool $entersVatBase;

    /** @internal a line's taxes are written by its line */
    public function __construct(DeliveryNoteLine $line, int $position, TaxComponent $taxComponent)
    {
        $this->id = Uuid::v7();
        $this->line = $line;
        $this->position = $position;
        $this->taxComponent = $taxComponent;
        $this->retake();
    }

    /** @internal the code, rate and VAT base behaviour its component has now */
    public function retake(): void
    {
        $rate = $this->taxComponent->getRate() ?? throw new \LogicException(\sprintf('The line tax %s has no rate.', $this->taxComponent->getCode()));
        $this->code = $this->taxComponent->getCode();
        $this->rate = Decimal::format(Decimal::of($rate), 3);
        $this->entersVatBase = $this->taxComponent->entersVatBase();
    }

    public function getLine(): DeliveryNoteLine
    {
        return $this->line;
    }

    public function getTaxComponent(): TaxComponent
    {
        return $this->taxComponent;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    /** A percentage with three decimals, as it stood when the line was written. */
    public function getRate(): string
    {
        return $this->rate;
    }

    public function entersVatBase(): bool
    {
        return $this->entersVatBase;
    }
}
