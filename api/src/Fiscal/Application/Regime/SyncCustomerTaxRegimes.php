<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Fiscal\Application\Regime;

use App\Fiscal\Application\Preset\FiscalPresets;
use App\Fiscal\Domain\CustomerTaxRegime;
use App\Fiscal\Domain\CustomerTaxRegimeRepository;
use Psr\Clock\ClockInterface;

/**
 * Writes every preset's customer tax regimes into the database, the way the seed writes the built-in roles: the
 * release defines them, and a second run converges on the same rows.
 */
final readonly class SyncCustomerTaxRegimes
{
    public function __construct(
        private FiscalPresets $presets,
        private CustomerTaxRegimeRepository $regimes,
        private ClockInterface $clock,
    ) {
    }

    /** @return list<string> one line per preset whose regimes were created or brought up to date */
    public function handle(): array
    {
        $now = $this->clock->now();
        $changed = [];
        foreach ($this->presets->keys() as $key) {
            $created = false;
            $updated = false;
            foreach ($this->presets->get($key)->customerTaxRegimes as $regime) {
                $existing = $this->regimes->ofPresetAndCode($key, $regime->code);
                if (null === $existing) {
                    $this->regimes->save(new CustomerTaxRegime($key, $regime->code, $regime->labelKey, $regime->excludedFamilies, $regime->mentionKey, $regime->sortOrder, $now));
                    $created = true;
                } elseif ($existing->redefine($regime->labelKey, $regime->excludedFamilies, $regime->mentionKey, $regime->sortOrder, $now)) {
                    $this->regimes->save($existing);
                    $updated = true;
                }
            }
            if ($created) {
                $changed[] = "customer tax regimes of $key";
            } elseif ($updated) {
                $changed[] = "customer tax regimes of $key updated";
            }
        }

        return $changed;
    }
}
