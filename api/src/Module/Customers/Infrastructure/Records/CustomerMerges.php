<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Customers\Infrastructure\Records;

use App\Module\Customers\Application\CustomerMergeSettings;
use App\Records\Application\Merge\MergeActor;
use App\Records\Application\Merge\MergeChanges;
use App\Records\Application\Merge\MergeEffects;
use App\Records\Application\Merge\MergeRefused;
use App\Settings\Application\ReadSetting;
use App\Settings\Application\SettingContext;
use App\Tenancy\Domain\Repository\CompanyRepository;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * A customer has one main contact at most, so the contacts that move to the customer kept bring no second one: the
 * kept customer's own stays the main one, or, where it has none, the first merged away customer's, in the order chosen.
 * The others are noted under the merge, so undoing it makes each the main contact of its customer again. Where the
 * company chose `customer.merge_main_contact` « refuse », customers of which more than one has a main contact are not
 * merged at all, until one main contact is left among them.
 */
final readonly class CustomerMerges implements MergeEffects
{
    public function __construct(private Connection $connection, private MergeChanges $changes, private ReadSetting $settings, private CompanyRepository $companies)
    {
    }

    public function subject(): string
    {
        return 'customer';
    }

    public function merging(Uuid $companyId, Uuid $mergeId, Uuid $keptId, array $mergedIds, MergeActor $actor, \DateTimeImmutable $at): void
    {
        $main = $this->mainContacts($companyId, [$keptId, ...$mergedIds]);
        if (\count($main) > 1 && $this->refusedWhileBothHaveOne($companyId)) {
            throw new MergeRefused('main_contacts', 'More than one of these customers has a main contact, and the company chose not to merge them so: leave one main contact among them first.');
        }
        $staying = $main[$keptId->toRfc4122()] ?? null;
        $demoted = [];
        foreach ($mergedIds as $mergedId) {
            $contact = $main[$mergedId->toRfc4122()] ?? null;
            if (null === $contact) {
                continue;
            }
            if (null === $staying) {
                $staying = $contact;
                continue;
            }
            $demoted[] = Uuid::fromString($contact);
        }
        $this->changes->set($companyId, $mergeId, 'contact', 'is_primary', $demoted, 'false');
    }

    public function unmerging(Uuid $companyId, Uuid $mergeId, Uuid $keptId, array $mergedIds, ?Uuid $actorUserId, \DateTimeImmutable $at): void
    {
        // Undoing the merge puts each contact back, main or not, with every link it noted.
    }

    private function refusedWhileBothHaveOne(Uuid $companyId): bool
    {
        $company = $this->companies->ofId($companyId) ?? throw new \LogicException('A merge acts for a company that exists.');

        return CustomerMergeSettings::REFUSE_WHILE_BOTH_HAVE_ONE === $this->settings->text(new SettingContext($company), CustomerMergeSettings::MAIN_CONTACT);
    }

    /**
     * @param list<Uuid> $customerIds
     *
     * @return array<string, string> each customer's main contact, by customer
     */
    private function mainContacts(Uuid $companyId, array $customerIds): array
    {
        $main = [];
        foreach ($this->connection->fetchAllAssociative(
            'SELECT customer_id::text AS customer, id::text AS contact FROM contact WHERE company_id = :company AND customer_id = ANY(CAST(:customers AS uuid[])) AND is_primary',
            ['company' => $companyId->toRfc4122(), 'customers' => '{'.implode(',', array_map(static fn (Uuid $id): string => $id->toRfc4122(), $customerIds)).'}'],
        ) as $row) {
            if (!\is_string($row['customer']) || !\is_string($row['contact'])) {
                throw new \LogicException('A contact and its customer read as ids.');
            }
            $main[$row['customer']] = $row['contact'];
        }

        return $main;
    }
}
