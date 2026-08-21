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

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use Twes\Application\Shared\TransactionalScope;
use Twes\Domain\Settings\CompanySettingsRepository;
use Twes\UI\Http\ApiResource\CompanySettingsResource;

/**
 * `GET /api/settings` — the calling company's settings, or the documented defaults.
 *
 * **IT CAN NEVER ANSWER NULL, and that is the difference between this and {@see InvoiceProvider}.** A document
 * either exists for this tenant or it does not, and "belongs to somebody else" is deliberately indistinguishable
 * from "does not exist". Settings are not like that: every tenant has them from the moment it exists, so a missing
 * row means "has chosen nothing" and the answer is {@see \Twes\Domain\Settings\CompanySettings::defaults()}. There
 * is no 404 to return.
 *
 * **THE READ IS WRAPPED IN A TRANSACTION, and it reads like ceremony while being the opposite.** The tenant
 * binding row-level security compares against is written on `beginTransaction()` and is transaction-local
 * (`set_config(..., true)`), so a read outside one is issued UNBOUND and sees nothing — which this class could
 * then not distinguish from a tenant that has configured nothing, and would answer with defaults it had no way to
 * know were wrong. `CompanySettingsRepository` refuses that outright rather than trusting a caller to remember, so
 * the transaction here is what makes the refusal never fire. `InvoiceProvider` wraps its own lookup for exactly
 * this reason (`CLAUDE.md` § Gotchas 2026-08-07).
 *
 * @implements ProviderInterface<CompanySettingsResource>
 */
final readonly class CompanySettingsProvider implements ProviderInterface
{
    public function __construct(
        private CompanySettingsRepository $settings,
        private TransactionalScope $transaction,
    ) {}

    /**
     * @param array<string, mixed> $uriVariables
     * @param array<string, mixed> $context
     *
     * @throws \RuntimeException if no tenant is bound — a tenant-less read has no company to answer about, and
     *                           answering with defaults would turn a tenancy refusal into a plausible-looking lie
     */
    public function provide(
        Operation $operation,
        array $uriVariables = [],
        array $context = [],
    ): CompanySettingsResource {
        $settings = $this->transaction->transactional(
            fn(): \Twes\Domain\Settings\CompanySettings => $this->settings->forCurrentTenant(),
        );

        return new CompanySettingsResource(
            $settings->numberPattern()->width(),
            $settings->defaultVatRoundingPoint()->value,
        );
    }
}
