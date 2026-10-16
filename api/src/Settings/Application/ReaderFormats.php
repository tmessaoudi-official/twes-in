<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Settings\Application;

use App\Tenancy\Domain\Repository\CompanyRepository;
use App\Tenancy\Domain\Repository\MembershipRepository;
use Symfony\Component\Uid\Uuid;

/**
 * How a person reads days and figures in what is written to them, a mail: their own `presentation.date-format` and
 * `presentation.number-format`, through the company, their role there and themselves, as their screen reads them. A
 * printed document is the company's instead (`DocumentFormats`). `auto` leaves both to the language.
 */
final readonly class ReaderFormats
{
    public function __construct(
        private ReadSetting $settings,
        private CompanyRepository $companies,
        private MembershipRepository $memberships,
    ) {
    }

    /** @return array{dateFormat: string, numberFormat: string} */
    public function of(Uuid $userId, ?Uuid $companyId): array
    {
        $company = null === $companyId ? null : $this->companies->ofId($companyId);
        $roleId = null === $company ? null : $this->memberships->ofUserInCompany($userId, $company->getId())?->getRole()->getId();
        $context = new SettingContext($company, $roleId, $userId);
        $date = $this->settings->value($context, 'presentation.date-format');
        $number = $this->settings->value($context, 'presentation.number-format');

        return ['dateFormat' => \is_string($date) ? $date : 'auto', 'numberFormat' => \is_string($number) ? $number : 'auto'];
    }
}
