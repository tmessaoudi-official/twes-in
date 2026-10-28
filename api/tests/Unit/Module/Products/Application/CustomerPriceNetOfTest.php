<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Module\Products\Application;

use App\Fiscal\Application\Company\ProvisionCompany;
use App\Fiscal\Application\Regime\ExcludedTaxFamilies;
use App\Fiscal\Domain\Calculation\Decimal;
use App\Module\Products\Application\CustomerPrice;
use App\Module\Products\Application\GrossUnreachable;
use App\Tenancy\Domain\Model\Company;
use App\Tests\Support\InMemoryEstablishments;
use App\Tests\Support\InMemoryNumberingSeries;
use App\Tests\Support\InMemoryTaxComponents;
use App\Tests\Support\InMemoryUnits;
use App\Tests\Support\ShippedFiscalPresets;
use BcMath\Number;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * The net price a price taxes included is kept at is the one a search over every net price finds: of the net prices
 * whose one unit comes to that very figure, the one nearest the exact quotient, or the two prices around it when none
 * does. The search a products file runs on each of its rows starts beside the answer, from the exact quotient, rather
 * than from nothing; this holds it to what the whole search answers, on every amount of the pricing vectors and on a
 * spread of others, in each currency and with each tax a line starts with.
 */
final class CustomerPriceNetOfTest extends TestCase
{
    private const string VECTORS = __DIR__.'/../../../../../../docs/spec/pricing-vectors.json';
    /** The line taxes a product may start with, by the company's country. */
    private const array COMBINATIONS = [
        'TN' => [[], ['TVA19'], ['TVA13'], ['TVA7'], ['FODEC', 'TVA19'], ['FODEC', 'TVA7']],
        'FR' => [[], ['TVA20'], ['TVA10'], ['TVA5_5'], ['TVA2_1']],
    ];

    private InMemoryTaxComponents $taxes;
    private CustomerPrice $prices;

    protected function setUp(): void
    {
        $this->taxes = new InMemoryTaxComponents();
        $this->prices = new CustomerPrice($this->taxes, ShippedFiscalPresets::presets(), ShippedFiscalPresets::scales(), new ExcludedTaxFamilies(ShippedFiscalPresets::presets()));
    }

    public function testTheNetPriceIsTheOneTheWholeSearchFinds(): void
    {
        $provision = new ProvisionCompany(ShippedFiscalPresets::presets(), $this->taxes, new InMemoryUnits(), new InMemoryEstablishments(), new InMemoryNumberingSeries(), ShippedFiscalPresets::scales(), new MockClock('2026-10-28 20:00:00', 'UTC'));
        $companies = ['TN' => new Company('Acme', 'TN', 'TND', 'fr', 'Africa/Tunis'), 'FR' => new Company('Durand', 'FR', 'EUR', 'fr', 'Europe/Paris')];
        foreach ($companies as $company) {
            $provision->handle($company);
        }
        $amounts = self::amounts();

        $checked = 0;
        foreach ($companies as $country => $company) {
            $scale = ShippedFiscalPresets::scales()->of($company->getCurrency());
            $combinations = self::COMBINATIONS[$country];
            foreach ($combinations as $turn => $codes) {
                // Each amount of the vectors with one set of taxes in turn, as the whole search costs a hundred counts.
                $mine = array_values(array_filter($amounts[$scale], static fn (int $at): bool => $turn === $at % \count($combinations), \ARRAY_FILTER_USE_KEY));
                $ids = array_map(fn (string $code): string => $this->taxes->ofCodeInCompany($code, $company->getId())?->getId()->toRfc4122() ?? self::fail("$country has no $code"), $codes);
                foreach ([...$mine, ...self::spread($scale)] as $gross) {
                    $label = \sprintf('%s %s at %s', $country, implode('+', $codes) ?: 'no tax', $gross);
                    self::assertSame(self::wholeSearch($this->prices, $company, $ids, $gross), self::answer($this->prices, $company, $ids, $gross), $label);
                    ++$checked;
                }
            }
        }

        self::assertGreaterThan(300, $checked);
    }

    /**
     * What netOf answers: the net price, or the two prices around a figure no net price reaches.
     *
     * @param list<string> $ids
     */
    private static function answer(CustomerPrice $prices, Company $company, array $ids, string $gross): string
    {
        try {
            return $prices->netOf($company, $ids, $gross);
        } catch (GrossUnreachable $none) {
            return "unreachable between {$none->below} and {$none->above}";
        }
    }

    /**
     * The search over every net price, as netOf ran it before it started beside the answer: the first net price coming
     * to the figure or more, found from nothing by doubling then halving, the last coming to it at most the same way,
     * and the exact quotient held between the two.
     *
     * @param list<string> $ids
     */
    private static function wholeSearch(CustomerPrice $prices, Company $company, array $ids, string $gross): string
    {
        $total = static fn (int $net): Number => Decimal::of($prices->preview($company, $ids, self::price($net), ['1'])[0]->total);
        $target = Decimal::of($gross);
        $first = self::first($total, static fn (Number $sum): bool => $sum->compare($target) >= 0);
        if (0 !== $total($first)->compare($target)) {
            return \sprintf('unreachable between %s and %s', $prices->preview($company, $ids, self::price($first - 1), ['1'])[0]->total, $prices->preview($company, $ids, self::price($first), ['1'])[0]->total);
        }
        $last = self::first($total, static fn (Number $sum): bool => $sum->compare($target) > 0) - 1;
        $factor = $total(10_000_000_000)->div(1_000_000, Decimal::WORKING_SCALE);
        $exact = (int) Decimal::format($target->div($factor, Decimal::WORKING_SCALE)->mul(10_000), 0);

        return self::price(max($first, min($last, $exact)));
    }

    /**
     * @param \Closure(int): Number  $total
     * @param \Closure(Number): bool $passes
     */
    private static function first(\Closure $total, \Closure $passes): int
    {
        if ($passes($total(0))) {
            return 0;
        }
        [$low, $high] = [0, 1];
        while (!$passes($total($high))) {
            [$low, $high] = [$high, $high * 2];
        }
        while ($high - $low > 1) {
            $middle = intdiv($low + $high, 2);
            if ($passes($total($middle))) {
                $high = $middle;
            } else {
                $low = $middle;
            }
        }

        return $high;
    }

    private static function price(int $tenThousandths): string
    {
        return \sprintf('%d.%04d', intdiv($tenThousandths, 10_000), $tenThousandths % 10_000);
    }

    /**
     * Every amount the pricing vectors write, by its number of decimals (a currency's scale).
     *
     * @return array<int, list<string>>
     */
    private static function amounts(): array
    {
        preg_match_all('/"([0-9]{1,7}\.[0-9]{2,3})"/', (string) file_get_contents(self::VECTORS), $found);
        $amounts = [2 => [], 3 => []];
        foreach (array_unique($found[1]) as $amount) {
            $amounts[\strlen($amount) - strpos($amount, '.') - 1][] = $amount;
        }
        self::assertGreaterThan(50, \count($amounts[3]), 'the vectors\' amounts in millimes');
        self::assertGreaterThan(10, \count($amounts[2]), 'the vectors\' amounts in cents');

        return $amounts;
    }

    /**
     * Small figures, where a unit's rounding weighs most, and a spread up to a hundred thousand.
     *
     * @return list<string>
     */
    private static function spread(int $scale): array
    {
        $unit = 10 ** $scale;
        $spread = [0, 1, 2, 3, 5, 7, 10, 11, 99, 100, 101, 999, 1_000, 1_001];
        for ($seed = 7, $i = 0; $i < 4; ++$i) {
            $seed = ($seed * 7_919 + 104_729) % 100_000_003;
            $spread[] = $seed % (100_000 * $unit);
        }

        return array_map(static fn (int $minor): string => \sprintf('%d.%0'.$scale.'d', intdiv($minor, $unit), $minor % $unit), $spread);
    }
}
