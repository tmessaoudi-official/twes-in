<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Expenses\Application;

use App\Audit\Application\AuditEntry;
use App\Audit\Application\AuditTrail;
use App\Files\Application\AttachmentRefused;
use App\Files\Application\Attachments;
use App\Files\Application\StoredFileCorrupted;
use App\Files\Application\StoredFileMissing;
use App\Files\Domain\Attachment;
use App\Fiscal\Application\CurrencyScales;
use App\Fiscal\Domain\TaxComponent;
use App\Fiscal\Domain\TaxComponentRepository;
use App\Module\Expenses\Domain\Expense;
use App\Module\Expenses\Domain\ExpenseCategory;
use App\Module\Expenses\Domain\ExpenseCategoryRepository;
use App\Module\Expenses\Domain\ExpenseRepository;
use App\Module\Expenses\Domain\ExpenseSearch;
use App\Module\Expenses\Domain\ExpenseTransitionRefused;
use App\Module\Expenses\Domain\InvalidExpense;
use App\Module\Vendors\Domain\Vendor;
use App\Module\Vendors\Domain\VendorRepository;
use App\Shared\Application\Transactions;
use App\Shared\Domain\Page;
use App\Shared\Domain\PageRequest;
use App\Shared\Domain\PaymentMethod;
use App\Tenancy\Domain\Company;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * A company's expenses and the files they rest on. The vendor, category and tax an expense names are looked up in its
 * company; the figures are the expense's own. A receipt is attached at any status, since one often arrives after the
 * expense is recorded, but only a draft loses one. Audited with field names, never values. A file attached or detached
 * runs in the same transaction as its audit row, so a refused row takes the attachment row back with it.
 */
final readonly class ManageExpenses
{
    public const string ENTITY_TYPE = 'expense';
    public const string CREATED = 'expense.created';
    public const string REVISED = 'expense.revised';
    public const string RECORDED = 'expense.recorded';
    public const string PAID = 'expense.paid';
    public const string DELETED = 'expense.deleted';
    public const string ATTACHMENT_ADDED = 'expense.attachment_added';
    public const string ATTACHMENT_REMOVED = 'expense.attachment_removed';

    public function __construct(
        private ExpenseRepository $expenses,
        private ExpenseCategoryRepository $categories,
        private VendorRepository $vendors,
        private TaxComponentRepository $taxes,
        private CurrencyScales $scales,
        private Attachments $attachments,
        private AuditTrail $audit,
        private ClockInterface $clock,
        private Transactions $transactions,
    ) {
    }

    /** @return list<Expense> */
    public function list(Company $company): array
    {
        return $this->expenses->ofCompany($company->getId());
    }

    /**
     * One page of the company's expenses, searched, narrowed and sorted by the database.
     *
     * @return Page<Expense>
     */
    public function search(Company $company, ExpenseSearch $search, PageRequest $page): Page
    {
        return $this->expenses->search($company->getId(), $search, $page);
    }

    /** @throws ExpenseNotFound */
    public function get(Company $company, Uuid $id): Expense
    {
        return $this->expenses->ofIdInCompany($id, $company->getId()) ?? throw new ExpenseNotFound();
    }

    /** @throws InvalidExpense */
    public function create(Company $company, ExpenseInput $input, ?Uuid $actorUserId): Expense
    {
        return $this->transactions->run(function () use ($company, $input, $actorUserId): Expense {
            $expense = Expense::create($company, $input->details, $this->vendor($company, $input->vendorId), $this->category($company, $input->categoryId), $this->tax($company, $input->taxComponentId), $this->scales->of($company->getCurrency()), $this->clock->now());
            $this->expenses->save($expense);
            $this->record($company, $expense->getId(), self::CREATED, [], $actorUserId);

            return $expense;
        });
    }

    /**
     * @throws ExpenseNotFound
     * @throws ExpenseTransitionRefused
     * @throws InvalidExpense
     */
    public function revise(Company $company, Uuid $id, ExpenseInput $input, ?Uuid $actorUserId): Expense
    {
        return $this->transactions->run(function () use ($company, $id, $input, $actorUserId): Expense {
            $expense = $this->get($company, $id);
            $expense->assertDraft('revised');
            $changed = $expense->revise($input->details, $this->vendor($company, $input->vendorId), $this->category($company, $input->categoryId), $this->tax($company, $input->taxComponentId), $this->scales->of($company->getCurrency()), $this->clock->now());
            if ([] !== $changed) {
                $this->expenses->save($expense);
                $this->record($company, $expense->getId(), self::REVISED, ['fields' => $changed], $actorUserId);
            }

            return $expense;
        });
    }

    /**
     * @throws ExpenseNotFound
     * @throws ExpenseTransitionRefused
     * @throws InvalidExpense
     */
    public function recordInBooks(Company $company, Uuid $id, ?Uuid $actorUserId): Expense
    {
        return $this->transactions->run(function () use ($company, $id, $actorUserId): Expense {
            $expense = $this->get($company, $id);
            $expense->record($this->clock->now());
            $this->expenses->save($expense);
            $this->record($company, $expense->getId(), self::RECORDED, [], $actorUserId);

            return $expense;
        });
    }

    /**
     * @throws ExpenseNotFound
     * @throws ExpenseTransitionRefused
     * @throws InvalidExpense
     */
    public function pay(Company $company, Uuid $id, PaymentMethod $method, \DateTimeImmutable $paidOn, ?Uuid $actorUserId): Expense
    {
        return $this->transactions->run(function () use ($company, $id, $method, $paidOn, $actorUserId): Expense {
            $expense = $this->get($company, $id);
            $now = $this->clock->now();
            $expense->pay($method, $paidOn, $now->setTimezone(new \DateTimeZone($company->getTimezone())), $now);
            $this->expenses->save($expense);
            $this->record($company, $expense->getId(), self::PAID, ['fields' => ['paymentMethod', 'paidOn']], $actorUserId);

            return $expense;
        });
    }

    /**
     * @throws ExpenseNotFound
     * @throws ExpenseTransitionRefused
     */
    public function delete(Company $company, Uuid $id, ?Uuid $actorUserId): void
    {
        $this->transactions->run(function () use ($company, $id, $actorUserId): void {
            $expense = $this->get($company, $id);
            $expense->assertDraft('deleted');
            $this->attachments->detachAll($company, self::ENTITY_TYPE, $id);
            $this->expenses->remove($expense);
            $this->record($company, $id, self::DELETED, [], $actorUserId);
        });
    }

    /**
     * @return list<Attachment>
     *
     * @throws ExpenseNotFound
     */
    public function attachments(Company $company, Uuid $id): array
    {
        return $this->attachments->of($company, self::ENTITY_TYPE, $this->get($company, $id)->getId());
    }

    public function attachmentCount(Expense $expense): int
    {
        return \count($this->attachments->of($expense->getCompany(), self::ENTITY_TYPE, $expense->getId()));
    }

    /**
     * @throws ExpenseNotFound
     * @throws AttachmentRefused
     */
    public function attach(Company $company, Uuid $id, string $name, string $contents, ?Uuid $actorUserId): Attachment
    {
        return $this->transactions->run(function () use ($company, $id, $name, $contents, $actorUserId): Attachment {
            $expense = $this->get($company, $id);
            $attachment = $this->attachments->attach($company, self::ENTITY_TYPE, $expense->getId(), $name, $contents, $actorUserId);
            $this->record($company, $expense->getId(), self::ATTACHMENT_ADDED, ['attachmentId' => $attachment->getId()->toRfc4122()], $actorUserId);

            return $attachment;
        });
    }

    /**
     * @return array{Attachment, string} the attachment and its bytes
     *
     * @throws ExpenseNotFound
     * @throws AttachmentNotFound
     * @throws StoredFileMissing
     * @throws StoredFileCorrupted
     */
    public function attachmentContents(Company $company, Uuid $id, Uuid $attachmentId): array
    {
        $attachment = $this->attachment($company, $id, $attachmentId);

        return [$attachment, $this->attachments->contents($attachment)];
    }

    /**
     * @throws ExpenseNotFound
     * @throws AttachmentNotFound
     * @throws ExpenseTransitionRefused
     */
    public function detach(Company $company, Uuid $id, Uuid $attachmentId, ?Uuid $actorUserId): void
    {
        $this->transactions->run(function () use ($company, $id, $attachmentId, $actorUserId): void {
            $attachment = $this->attachment($company, $id, $attachmentId);
            $this->get($company, $id)->assertDraft('stripped of a file');
            $this->attachments->detach($attachment);
            $this->record($company, $id, self::ATTACHMENT_REMOVED, ['attachmentId' => $attachmentId->toRfc4122()], $actorUserId);
        });
    }

    private function attachment(Company $company, Uuid $id, Uuid $attachmentId): Attachment
    {
        return $this->attachments->find($company, self::ENTITY_TYPE, $this->get($company, $id)->getId(), $attachmentId) ?? throw new AttachmentNotFound();
    }

    private function vendor(Company $company, ?Uuid $id): ?Vendor
    {
        return null === $id ? null : ($this->vendors->ofIdInCompany($id, $company->getId()) ?? throw new InvalidExpense('vendorId', 'No vendor of this company has this id.'));
    }

    private function category(Company $company, ?Uuid $id): ?ExpenseCategory
    {
        return null === $id ? null : ($this->categories->ofIdInCompany($id, $company->getId()) ?? throw new InvalidExpense('categoryId', 'No expense category of this company has this id.'));
    }

    private function tax(Company $company, ?Uuid $id): ?TaxComponent
    {
        return null === $id ? null : ($this->taxes->ofIdInCompany($id, $company->getId()) ?? throw new InvalidExpense('taxComponentId', 'No tax of this company has this id.'));
    }

    /** @param array<string, mixed> $changes */
    private function record(Company $company, Uuid $expenseId, string $action, array $changes, ?Uuid $actorUserId): void
    {
        $this->audit->record(new AuditEntry(self::ENTITY_TYPE, $expenseId, $action, $actorUserId, $changes, $company->getId()));
    }
}
