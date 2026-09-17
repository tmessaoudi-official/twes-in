<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Licensing\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Licensing\Domain\PaymentDeclaration;
use App\Licensing\Domain\PaymentDeclarationRepository;

/**
 * Every payment waiting for a decision, oldest first: the operator's own queue, across all companies. It is short by
 * construction — one declaration per company at a time — so it is not paged.
 *
 * @implements ProviderInterface<PaymentDeclarationResource>
 */
final readonly class WaitingPaymentsProvider implements ProviderInterface
{
    public function __construct(private PaymentDeclarationRepository $declarations)
    {
    }

    /** @return list<PaymentDeclarationResource> */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        return array_map(
            static fn (PaymentDeclaration $declaration): PaymentDeclarationResource => PaymentDeclarationResource::of($declaration),
            $this->declarations->waiting(),
        );
    }
}
