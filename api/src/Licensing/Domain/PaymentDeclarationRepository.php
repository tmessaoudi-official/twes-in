<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Licensing\Domain;

use Symfony\Component\Uid\Uuid;

interface PaymentDeclarationRepository
{
    public function ofId(Uuid $id): ?PaymentDeclaration;

    /** The company's declaration waiting for a decision, if it has one: only ever one at a time. */
    public function openOfCompany(Uuid $companyId): ?PaymentDeclaration;

    /**
     * The company's declarations, newest first.
     *
     * @return list<PaymentDeclaration>
     */
    public function ofCompany(Uuid $companyId, int $limit): array;

    /**
     * Every declaration waiting for a decision, oldest first: the operator answers them in the order they came.
     *
     * @return list<PaymentDeclaration>
     */
    public function waiting(): array;

    /**
     * When each of the named companies declared the payment it is waiting on, by company id.
     *
     * @param list<Uuid> $companyIds
     *
     * @return array<string, \DateTimeImmutable>
     */
    public function openDeclaredAt(array $companyIds): array;

    public function save(PaymentDeclaration $declaration): void;
}
