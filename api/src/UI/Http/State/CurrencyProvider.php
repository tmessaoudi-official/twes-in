<?php

/*
 * This file is part of twes-in.
 *
 * (c) Takieddine MESSAOUDI <takieddine.messaoudi.official@gmail.com>
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Twes\UI\Http\State;

use ApiPlatform\Metadata\CollectionOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use Twes\Domain\Money\Currency;
use Twes\Domain\Money\Exception\UnknownCurrency;
use Twes\UI\Http\ApiResource\CurrencyResource;

/**
 * Translates the domain's `Currency` into its HTTP representation.
 *
 * **This class is the reason the domain stays clean.** § Architecture forbids a framework dependency in `Domain/`,
 * so `Twes\Domain\Money\Currency` cannot carry an `#[ApiResource]` attribute and the UI cannot serialise it
 * directly. A provider is the ecosystem's answer — the same shape as the Doctrine repository translating an
 * aggregate to and from rows, applied to the transport boundary instead of the persistence one. Dependencies point
 * inward: this knows about `Domain/`, and `Domain/` knows nothing about this.
 *
 * @implements ProviderInterface<CurrencyResource>
 */
final readonly class CurrencyProvider implements ProviderInterface
{
    /**
     * @param array<string, mixed> $uriVariables
     * @param array<string, mixed> $context
     *
     * @return list<CurrencyResource>|CurrencyResource|null
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): iterable|object|null
    {
        if ($operation instanceof CollectionOperationInterface) {
            return array_map(
                static fn(string $code): CurrencyResource => self::represent($code),
                Currency::all(),
            );
        }

        $code = $uriVariables['code'] ?? null;

        if (!\is_string($code)) {
            return null;
        }

        try {
            return self::represent($code);
        } catch (UnknownCurrency) {
            // NULL rather than a rethrow: API Platform turns it into a 404, which is the honest answer for an
            // unknown identifier. Letting the domain exception escape would render a 500 for a client typo — and
            // `CLAUDE.md` § "Translation keys" is explicit that our own faults map to `error.internal` while a
            // user-fixable mistake gets a message they can act on. A wrong currency code is the second kind.
            return null;
        }
    }

    private static function represent(string $code): CurrencyResource
    {
        $currency = Currency::of($code);

        return new CurrencyResource($currency->code(), $currency->scale());
    }
}
