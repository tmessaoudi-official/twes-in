<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Domain;

use App\Fiscal\Domain\Calculation\Decimal;
use App\Fiscal\Domain\TaxComponent;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * A tax an invoice line carries, with the code, rate and VAT base behaviour the component had when the line was
 * written, taken again at issue: a company editing its tax later changes no issued invoice.
 */
#[ORM\Entity]
#[ORM\Table(name: 'invoice_line_tax')]
#[ORM\Index(name: 'idx_invoice_line_tax_line', columns: ['line_id'])]
#[ORM\Index(name: 'idx_invoice_line_tax_component', columns: ['tax_component_id'])]
class InvoiceLineTax
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: InvoiceLine::class, inversedBy: 'taxes')]
    #[ORM\JoinColumn(name: 'line_id', nullable: false, onDelete: 'CASCADE')]
    private InvoiceLine $line;

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
    public function __construct(InvoiceLine $line, int $position, TaxComponent $taxComponent)
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

    /** @internal the code, rate and VAT base behaviour another line tax charged; with none, its component's now */
    public function retakeFrom(?self $charged): void
    {
        if (null === $charged) {
            $this->retake();

            return;
        }
        $this->code = $charged->code;
        $this->rate = $charged->rate;
        $this->entersVatBase = $charged->entersVatBase;
    }

    public function getLine(): InvoiceLine
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

    /** A percentage with three decimals. */
    public function getRate(): string
    {
        return $this->rate;
    }

    public function entersVatBase(): bool
    {
        return $this->entersVatBase;
    }
}
