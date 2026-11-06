<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Infrastructure\Watch;

use App\Settings\Application\ChangeSettings;
use App\Shared\Application\StoredContacts;
use App\Shared\Domain\ValueObject\Page;
use App\Shared\Domain\ValueObject\PageRequest;
use App\Tenancy\Application\Company\CreateCompany;
use App\Tenancy\Application\Establishment\ManageEstablishments;
use App\Tenancy\Domain\Model\Company;
use App\Tenancy\Infrastructure\ApiPlatform\Resource\CompanyProfileResource;
use App\Watch\Application\DeclaresWatch;
use App\Watch\Application\WatchItem;

/**
 * The company's own contact and each establishment's, as their pages say « À vérifier »: a value today's rules refuse,
 * kept from before them, or a phone the platform keeps outside its country's ranges. Neither record carries a mark of
 * its own that a person could take off, so the row stays until the value changes, as the page's line does (one canon:
 * `StoredContacts::toCheck`). A subject of the core, shown to whoever may correct them. A company has a handful of
 * establishments, so the rows are worked out here rather than in SQL, which could not judge a phone.
 */
final readonly class ContactsToCheckWatch implements DeclaresWatch
{
    public const string TO_CHECK = 'company.contacts_to_check';

    public function __construct(private ManageEstablishments $establishments, private StoredContacts $contacts)
    {
    }

    public function key(): string
    {
        return 'company';
    }

    public function module(): ?string
    {
        return null;
    }

    public function permission(): string
    {
        return CompanyProfileResource::WRITE_PERMISSION;
    }

    public function kinds(): array
    {
        return [self::TO_CHECK];
    }

    /** The profile's save, an establishment's, and the platform's phone setting, which turns a kept phone into a doubt. */
    public function changedBy(): array
    {
        return [CreateCompany::ENTITY_TYPE, ManageEstablishments::ENTITY_TYPE, ChangeSettings::ENTITY_TYPE];
    }

    public function count(string $kind, Company $company, \DateTimeImmutable $today): int
    {
        return \count($this->rows($kind, $company));
    }

    public function page(string $kind, Company $company, \DateTimeImmutable $today, PageRequest $request): Page
    {
        $rows = $this->rows($kind, $company);

        return new Page(\array_slice($rows, $request->offset(), $request->size), \count($rows), $request);
    }

    /** @return list<WatchItem> the profile first, then each establishment in the order its list shows them */
    private function rows(string $kind, Company $company): array
    {
        if (self::TO_CHECK !== $kind) {
            throw new \LogicException(\sprintf('The contacts watch has no %s.', $kind));
        }
        $rows = [];
        $profile = $company->getProfile();
        $fields = $this->contacts->toCheck($profile->email, $profile->phone, $company->getCountryCode());
        if ([] !== $fields) {
            $rows[] = new WatchItem(self::TO_CHECK, $company->getId()->toRfc4122(), ['record' => 'profile', 'name' => $company->getName(), 'contacts' => implode(',', $fields)]);
        }
        foreach ($this->establishments->list($company) as $establishment) {
            $fields = $this->establishments->contactsToCheck($company, $establishment);
            if ([] !== $fields) {
                $rows[] = new WatchItem(self::TO_CHECK, $establishment->getId()->toRfc4122(), [
                    'record' => 'establishment',
                    'name' => $establishment->getName(),
                    'code' => $establishment->getCode(),
                    'contacts' => implode(',', $fields),
                ]);
            }
        }

        return $rows;
    }
}
