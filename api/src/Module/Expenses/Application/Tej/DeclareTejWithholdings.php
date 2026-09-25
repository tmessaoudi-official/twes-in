<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Expenses\Application\Tej;

use App\Fiscal\Domain\Calculation\Decimal;
use App\Module\Expenses\Domain\Expense;
use App\Module\Expenses\Domain\ExpenseRepository;
use App\Module\Expenses\Domain\TejOperationCode;
use App\Module\Vendors\Domain\Vendor;
use App\Tenancy\Domain\Company;

/**
 * The withholdings a Tunisian company operated on its suppliers in one month, as the TEJ platform takes them
 * (docs/research/tax-data-tunisia.md § 2.2; cahier des charges TEJ, September 2026): one certificate per expense paid
 * that month with a withholding or a TEJ operation code (a supplier exempt from withholding is still declared, at
 * 0 %), in the order they were paid.
 *
 * Nothing is written while any of those payments lacks what the platform asks for: its operation code, or its
 * supplier's matricule, address, email or phone. Each such payment is named with what it lacks instead, since a
 * month's initial filing is made once and anything left out has to be rectified on the platform afterwards.
 */
final readonly class DeclareTejWithholdings
{
    /** The TEJ platform's own pattern for a beneficiary's email (`TypeAdresseContact/AdresseMail`), anchored. */
    private const string EMAIL = '/^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/D';

    /** The currency every TEJ amount is in, counted in millimes. */
    private const string CURRENCY = 'TND';

    public function __construct(private ExpenseRepository $expenses)
    {
    }

    /**
     * @param int $month 1 to 12
     *
     * @throws TejDeclarationRefused
     */
    public function declare(Company $company, int $year, int $month): TejDeclaration
    {
        if (TejOperationCode::PRESET !== $company->getFiscalPreset() || self::CURRENCY !== $company->getCurrency()) {
            throw new TejDeclarationRefused('Only a company under the Tunisian preset, keeping its books in dinars, declares its withholdings to TEJ.', 'not_declared_to_tej', ['preset' => $company->getFiscalPreset()]);
        }
        $matricule = $company->getProfile()->identifiers[TejMatricule::KEY] ?? null;
        if (null === $matricule) {
            throw new TejDeclarationRefused('The company\'s matricule fiscal is not in its profile: the declaration is filed under it.', 'company_matricule_missing');
        }
        $declarant = TejMatricule::parse($matricule);
        if (null === $declarant || null === $declarant->category) {
            throw new TejDeclarationRefused('The company\'s matricule fiscal does not say whether it is a legal or a natural person.', 'company_matricule_unreadable');
        }

        $from = new \DateTimeImmutable(\sprintf('%04d-%02d-01', $year, $month), new \DateTimeZone('UTC'));
        $declared = array_values(array_filter(
            $this->expenses->paidBetween($company->getId(), $from, $from->modify('first day of next month')),
            static fn (Expense $expense): bool => null !== $expense->getWithholdingAmount() || null !== $expense->getWithholdingOperationCode(),
        ));
        if ([] === $declared) {
            throw new TejDeclarationRefused(\sprintf('No payment of %04d-%02d withheld anything or names a TEJ operation.', $year, $month), 'nothing_to_declare', ['year' => $year, 'month' => $month]);
        }

        $certificates = [];
        $incomplete = [];
        foreach ($declared as $expense) {
            $problems = self::problems($expense);
            if ([] !== $problems) {
                $incomplete[] = [
                    'expenseId' => $expense->getId()->toRfc4122(),
                    'paidOn' => (string) $expense->getPaidOn()?->format('Y-m-d'),
                    'description' => $expense->getDescription(),
                    'reference' => $expense->getReference(),
                    'vendorName' => $expense->getVendor()?->getProfile()->name,
                    'problems' => $problems,
                ];
                continue;
            }
            $certificates[] = self::certificate($expense);
        }
        if ([] !== $incomplete) {
            throw new TejDeclarationRefused(\sprintf('%d payment(s) of the month lack what the TEJ platform asks for.', \count($incomplete)), 'incomplete_expenses', ['count' => \count($incomplete)], $incomplete);
        }

        return new TejDeclaration($declarant->identifier, $declarant->category, $year, $month, $certificates);
    }

    /** @return list<string> what the payment lacks, in the order a certificate reads */
    private static function problems(Expense $expense): array
    {
        $problems = [];
        $vendor = $expense->getVendor();
        if (null === $vendor) {
            $problems[] = 'vendor_missing';
        } else {
            $profile = $vendor->getProfile();
            $matricule = $profile->identifiers[TejMatricule::KEY] ?? null;
            $parsed = null === $matricule ? null : TejMatricule::parse($matricule);
            $problems[] = match (true) {
                null === $matricule => 'vendor_matricule_missing',
                null === $parsed => 'vendor_matricule_unreadable',
                null === $parsed->category => 'vendor_category_unknown',
                default => null,
            };
            $problems[] = '' === self::address($vendor) ? 'vendor_address_missing' : null;
            $problems[] = match (true) {
                null === $profile->email => 'vendor_email_missing',
                1 !== preg_match(self::EMAIL, $profile->email) => 'vendor_email_unaccepted',
                default => null,
            };
            $problems[] = null === $profile->phone ? 'vendor_phone_missing' : null;
        }
        $problems[] = null === $expense->getWithholdingOperationCode() ? 'operation_code_missing' : null;
        $problems[] = null === self::rate($expense->getWithholdingRate()) ? 'withholding_rate_too_precise' : null;
        $problems[] = null === self::rate($expense->getTaxRate()) ? 'tax_rate_too_precise' : null;

        return array_values(array_filter($problems, static fn (?string $problem): bool => null !== $problem));
    }

    private static function certificate(Expense $expense): TejCertificate
    {
        // problems() found all of these present.
        $vendor = $expense->getVendor() ?? throw new \LogicException('A declared payment names its vendor.');
        $profile = $vendor->getProfile();
        $matricule = TejMatricule::parse($profile->identifiers[TejMatricule::KEY] ?? '') ?? throw new \LogicException('A declared vendor has its matricule.');
        $paidOn = $expense->getPaidOn() ?? throw new \LogicException('A declared expense is paid.');
        $withheld = $expense->getWithholdingAmount() ?? '0';

        $operation = new TejOperation(
            $expense->getWithholdingOperationCode() ?? throw new \LogicException('A declared payment names its operation.'),
            (int) $expense->getDate()->format('Y'),
            self::millimes($expense->getAmountNet()),
            (string) self::rate($expense->getWithholdingRate()),
            (string) self::rate($expense->getTaxRate()),
            self::millimes($expense->getTaxAmount()),
            self::millimes($expense->getAmountGross()),
            self::millimes($withheld),
            self::millimes($expense->getAmountPaid()),
        );

        return new TejCertificate(
            $matricule->identifier,
            (string) $matricule->category,
            $profile->legalName ?? $profile->name,
            self::address($vendor),
            (string) $profile->email,
            (string) $profile->phone,
            $paidOn,
            $expense->getId()->toRfc4122(),
            [$operation],
        );
    }

    /** A dinar amount of at most three decimals, as whole millimes. */
    private static function millimes(string $dinars): int
    {
        $millimes = Decimal::of($dinars)->mul(1000);
        if (0 !== Decimal::round($millimes, 0)->compare($millimes)) {
            throw new \LogicException(\sprintf('%s TND is not a whole number of millimes.', $dinars));
        }

        return (int) Decimal::format($millimes, 0);
    }

    /**
     * A percentage as TEJ writes it, at most two decimals and no trailing zero (`1.500` is `1.5`); "0" for none; null
     * when it carries a third decimal the platform cannot take.
     */
    private static function rate(?string $percent): ?string
    {
        $value = Decimal::of($percent ?? '0');
        if (0 !== Decimal::round($value, 2)->compare($value)) {
            return null;
        }
        $written = Decimal::format($value, 2);

        return str_contains($written, '.') ? rtrim(rtrim($written, '0'), '.') : $written;
    }

    /** The lines, then the postal code and the city, on one line. */
    private static function address(Vendor $vendor): string
    {
        $address = $vendor->getProfile()->address;
        $city = trim(($address->postalCode ?? '').' '.($address->city ?? ''));

        return implode(', ', array_filter([$address->line1, $address->line2, $city], static fn (?string $part): bool => null !== $part && '' !== $part));
    }
}
