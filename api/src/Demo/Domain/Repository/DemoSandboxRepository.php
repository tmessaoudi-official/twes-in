<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Demo\Domain\Repository;

use App\Demo\Domain\Model\DemoSandbox;
use Symfony\Component\Uid\Uuid;

interface DemoSandboxRepository
{
    public function save(DemoSandbox $sandbox): void;

    public function remove(DemoSandbox $sandbox): void;

    public function ofId(Uuid $id): ?DemoSandbox;

    /** Held until the transaction ends. */
    public function lockedOfId(Uuid $id): ?DemoSandbox;

    /**
     * The oldest ready sandbox of the country, held until the transaction ends, skipping any another claim holds: two
     * visitors at once never take the same one, and neither waits for the other.
     */
    public function lockedReadyOf(string $country): ?DemoSandbox;

    /**
     * Holds the whole pool until the transaction ends, so two runs topping it up at once count the same sandboxes and
     * never both queue the one place left.
     */
    public function lockPool(): void;

    /** @return array<string, int> how many sandboxes each country has queued, being built or ready */
    public function waitingByCountry(): array;

    /** @return list<Uuid> the sandboxes being built since before that moment */
    public function buildingSince(\DateTimeImmutable $before): array;

    /** Whether the account is the owner of a sandbox, whatever its state. */
    public function ownsOne(Uuid $userId): bool;

    /** The sandbox whose owner has that address, as `Email` writes it, whatever its state. */
    public function ownedByAddress(string $address): ?DemoSandbox;

    /** The sandbox the company is, whatever its state; null for any company that is no sandbox. */
    public function ofCompany(Uuid $companyId): ?DemoSandbox;

    /** How many sandboxes there are, whatever their state. */
    public function count(): int;
}
