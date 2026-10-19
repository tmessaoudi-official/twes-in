<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Fiscal\Domain\ValueObject;

use App\Fiscal\Domain\Model\TaxComponent;
use App\Fiscal\Domain\Model\Unit;

/**
 * What a document calls its units and taxes on paper, in its own language. An issued document always keeps them, so a
 * copy can print exactly what was sent though a unit or a tax was renamed since; whether a copy reads them or today's
 * names is the company's choice, made when it prints, never when it issues.
 */
final readonly class PrintedNames
{
    /**
     * @param array<string, string> $units each unit on the document's lines, by its code
     * @param array<string, string> $taxes every tax of the company, by its code: a charge or a withholding the totals add
     *                                     is named from here as surely as a line's tax
     */
    public function __construct(public array $units, public array $taxes)
    {
    }

    /**
     * @param iterable<Unit>         $units
     * @param iterable<TaxComponent> $taxes
     */
    public static function in(string $language, iterable $units, iterable $taxes): self
    {
        $unitNames = [];
        foreach ($units as $unit) {
            $unitNames[$unit->getCode()] = $unit->nameIn($language);
        }
        $taxNames = [];
        foreach ($taxes as $tax) {
            $taxNames[$tax->getCode()] = $tax->nameIn($language);
        }

        return new self($unitNames, $taxNames);
    }

    /** @throws \LogicException when the unit is not on the document, whose lines never change once it is issued */
    public function unit(Unit $unit): string
    {
        return $this->units[$unit->getCode()] ?? throw new \LogicException(\sprintf('The document keeps no name for its unit %s.', $unit->getCode()));
    }

    /** @return array{units: array<string, string>, taxes: array<string, string>} */
    public function toArray(): array
    {
        return ['units' => $this->units, 'taxes' => $this->taxes];
    }

    /**
     * @param array<array-key, mixed> $data what toArray() gave
     *
     * @throws \UnexpectedValueException when it is not
     */
    public static function fromArray(array $data): self
    {
        return new self(self::names($data, 'units'), self::names($data, 'taxes'));
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @return array<string, string>
     */
    private static function names(array $data, string $key): array
    {
        $names = $data[$key] ?? null;
        // A list is names without their codes: no code is a single character, so none reads back as position 0.
        if (!\is_array($names) || ([] !== $names && array_is_list($names))) {
            throw new \UnexpectedValueException(\sprintf('A document\'s kept names hold no "%s" by code.', $key));
        }
        $read = [];
        foreach ($names as $code => $name) {
            if (!\is_string($name)) {
                throw new \UnexpectedValueException(\sprintf('A document\'s kept "%s" are each named by a text.', $key));
            }
            // A code of digits alone, « 10 », comes back from JSON as an integer key; PHP keys it so either way.
            $read[(string) $code] = $name;
        }

        return $read;
    }
}
