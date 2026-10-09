<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Application;

use App\Fiscal\Application\CurrencyScales;
use App\Fiscal\Domain\Calculation\Decimal;
use App\Module\Customers\Domain\CustomerRepository;
use App\Module\Invoices\Domain\InvoiceReminder;
use App\Module\Invoices\Domain\InvoiceReminderRepository;
use App\Module\Invoices\Domain\InvoiceRepository;
use App\Settings\Application\ReadSetting;
use App\Settings\Application\SettingContext;
use App\Shared\Application\Notification;
use App\Shared\Application\Notifications;
use App\Tenancy\Domain\Company;
use App\Tenancy\Domain\MembershipRepository;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Staged reminders (DOC-20, MON-16). From the company's hour on its own day, every overdue invoice — by the overdue
 * chip's rule — that reached a stage of the calendar it had not reached yet records that stage and tells the people who
 * issue invoices it is time to remind the customer. An invoice already past several stages records only the highest,
 * so switching reminders on never floods anyone. Where the company charges a late fee at the stage, its draft is
 * written once the stage is recorded and the notice says so; a late fee's own lateness is reminded, never charged.
 * Nothing reaches the customer here: sending comes with the channels that will read these same stages.
 */
final readonly class RemindLateInvoices
{
    public const string PERMISSION = 'invoice.issue';
    public const string REMINDER_DUE = 'invoice.reminder_due';
    public const string LATE_FEE_DRAFTED = 'invoice.late_fee_drafted';

    public function __construct(
        private InvoiceRepository $invoices,
        private InvoiceReminderRepository $reminders,
        private CustomerRepository $customers,
        private ReadSetting $settings,
        private MembershipRepository $memberships,
        private Notifications $notifications,
        private CurrencyScales $scales,
        private ClockInterface $clock,
        private DraftLateFee $lateFees,
    ) {
    }

    /** @return int how many stages were recorded */
    public function handle(Company $company): int
    {
        $now = $this->clock->now()->setTimezone(new \DateTimeZone($company->getTimezone()));
        $context = new SettingContext($company);
        $hour = $this->settings->value($context, ReminderSettings::HOUR);
        if (!\is_int($hour) || (int) $now->format('G') < $hour) {
            return 0;
        }
        $stagesWritten = $this->settings->value($context, ReminderSettings::STAGES);
        $stages = ReminderSettings::stages(\is_string($stagesWritten) ? $stagesWritten : '');
        if ([] === $stages) {
            return 0;
        }

        $today = new \DateTimeImmutable($now->format('Y-m-d'));
        $late = $this->invoices->overdueRows($company->getId(), $today);
        if ([] === $late) {
            return 0;
        }
        $ids = array_map(static fn (array $row): Uuid => $row['invoiceId'], $late);
        $reached = $this->reminders->highestStages($company->getId(), $ids);
        $fees = array_flip($this->reminders->lateFeesAmong($company->getId(), $ids));
        $scale = $this->scales->of($company->getCurrency());
        $reminded = [];
        $recorded = 0;
        foreach ($late as $row) {
            $daysLate = (int) $row['dueDate']->diff($today)->days;
            $stage = self::stageFor($stages, $daysLate);
            if (0 === $stage || $stage <= ($reached[$row['invoiceId']->toRfc4122()] ?? 0)) {
                continue;
            }
            $customer = $row['customerId']->toRfc4122();
            $reminded[$customer] ??= $this->remindsCustomer($company, $row['customerId']);
            if (!$reminded[$customer]) {
                continue;
            }
            if (!$this->reminders->recordOnce(new InvoiceReminder($company, $row['invoiceId'], $stage, $daysLate, $today, $this->clock->now()))) {
                continue;
            }
            ++$recorded;
            $payload = [
                'invoice_id' => $row['invoiceId']->toRfc4122(),
                'number' => $row['number'],
                'customer_id' => $customer,
                'customer' => $row['customerName'],
                'stage' => $stage,
                'days_late' => $daysLate,
                'amount_due' => Decimal::format(Decimal::of($row['amountDue']), $scale),
                'currency' => $company->getCurrency(),
                'company' => $company->getName(),
            ];
            $fee = isset($fees[$row['invoiceId']->toRfc4122()]) ? null : $this->lateFees->handle($company, $row['invoiceId'], $stage, $daysLate, $row['amountDue']);
            if (null === $fee) {
                $this->tell($company, self::REMINDER_DUE, $payload);
                continue;
            }
            $this->reminders->recordLateFee($company->getId(), $row['invoiceId'], $stage, $fee['invoiceId']);
            $this->tell($company, self::LATE_FEE_DRAFTED, [...$payload, 'late_fee' => $fee['fee'], 'late_fee_invoice_id' => $fee['invoiceId']->toRfc4122()]);
        }

        return $recorded;
    }

    /**
     * The highest stage these days late reached, counting from one; zero before the first.
     *
     * @param list<int> $stages smallest first
     */
    public static function stageFor(array $stages, int $daysLate): int
    {
        $reached = 0;
        foreach ($stages as $index => $days) {
            if ($daysLate >= $days) {
                $reached = $index + 1;
            }
        }

        return $reached;
    }

    private function remindsCustomer(Company $company, Uuid $customerId): bool
    {
        $customer = $this->customers->ofIdInCompany($customerId, $company->getId());
        if (null === $customer) {
            return false;
        }

        return true === $this->settings->value(new SettingContext($company, customerGroupId: $customer->getGroup()?->getId(), customerId: $customerId), ReminderSettings::ENABLED);
    }

    /** @param array<string, string|int> $payload */
    private function tell(Company $company, string $type, array $payload): void
    {
        foreach ($this->memberships->ofCompany($company->getId()) as $membership) {
            if ($membership->getRole()->grants(self::PERMISSION)) {
                $this->notifications->publish(new Notification('user:'.$membership->getUser()->getId()->toRfc4122(), $type, $payload, $company->getId()->toRfc4122()));
            }
        }
    }
}
