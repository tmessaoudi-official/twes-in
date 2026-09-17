<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Licensing\Application;

use App\Audit\Application\AuditEntry;
use App\Audit\Application\AuditTrail;
use App\Identity\Domain\UserRepository;
use App\Licensing\Domain\DeclarationStatus;
use App\Licensing\Domain\DeclaredPayment;
use App\Licensing\Domain\InvalidPayment;
use App\Licensing\Domain\PaymentAlreadyDeclared;
use App\Licensing\Domain\PaymentDeclaration;
use App\Licensing\Domain\PaymentDeclarationRepository;
use App\Licensing\Domain\PaymentNotFound;
use App\Licensing\Domain\SubscriptionNotManaged;
use App\Licensing\Domain\SubscriptionRepository;
use App\Shared\Application\Notification;
use App\Shared\Application\Notifications;
use App\Shared\Application\Transactions;
use App\Tenancy\Domain\Company;
use App\Tenancy\Domain\MembershipRepository;
use App\Tenancy\Domain\Role;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Cash and transfers (docs/SPEC.md § 7, 2026-09-17): a company declares what it paid, the operator confirms or rejects
 * it, and only a confirmation carries the covered time forward. One declaration waits at a time, so a company cannot
 * keep itself open by declaring again and again; a declaration held the lock off for the hold days either way, and a
 * rejection ends that at once.
 *
 * The operator is told once the declaration is stored, and the company's owners once the decision is, never of one that
 * rolled back.
 */
final readonly class ManagePayments
{
    public const string ENTITY_TYPE = 'payment_declaration';
    public const string DECLARED = 'subscription.payment_declared';
    public const string CONFIRMED = 'subscription.payment_confirmed';
    public const string REJECTED = 'subscription.payment_rejected';
    /** What the operator and the company's owners see in their notification centre. */
    public const string NOTIFICATION_DECLARED = 'subscription.payment_declared';
    public const string NOTIFICATION_DECIDED = 'subscription.payment_decided';

    public function __construct(
        private PaymentDeclarationRepository $declarations,
        private SubscriptionRepository $subscriptions,
        private MembershipRepository $memberships,
        private UserRepository $users,
        private Notifications $notifications,
        private SubscriptionMailer $mailer,
        private AuditTrail $audit,
        private ClockInterface $clock,
        private Transactions $transactions,
        private string $loginUrl,
        private string $platformUrl,
    ) {
    }

    /**
     * @throws SubscriptionNotManaged when licensing does not manage the company: there is nothing to pay for here
     * @throws PaymentAlreadyDeclared when one already waits for a decision
     * @throws InvalidPayment         when the payment itself cannot be true
     */
    public function declare(Company $company, DeclaredPayment $payment, Uuid $userId): PaymentDeclaration
    {
        if (null === $this->subscriptions->ofCompany($company->getId())) {
            throw new SubscriptionNotManaged('This company has no subscription to pay for.');
        }
        if (null !== $this->declarations->openOfCompany($company->getId())) {
            throw new PaymentAlreadyDeclared('A payment is already waiting for a decision.');
        }

        $declaration = $this->transactions->run(function () use ($company, $payment, $userId): PaymentDeclaration {
            $declaration = new PaymentDeclaration($company, $payment, $userId, $this->clock->now());
            $this->declarations->save($declaration);
            $this->record($declaration, self::DECLARED, $userId);

            return $declaration;
        });

        $this->tellOperators($declaration);

        return $declaration;
    }

    /**
     * @param int $periods how many billing periods the payment covers; ignored when it is rejected
     *
     * @throws PaymentNotFound
     * @throws InvalidPayment  when it was already decided
     */
    public function decide(Uuid $declarationId, bool $confirmed, Uuid $operatorId, ?string $note = null, int $periods = 1): PaymentDeclaration
    {
        $declaration = $this->declarations->ofId($declarationId) ?? throw new PaymentNotFound('No such payment declaration.');

        $paidThrough = $this->transactions->run(function () use ($declaration, $confirmed, $operatorId, $note, $periods): ?\DateTimeImmutable {
            $now = $this->clock->now();
            $confirmed ? $declaration->confirm($operatorId, $now, $note) : $declaration->reject($operatorId, $now, $note);
            $this->declarations->save($declaration);
            $this->record($declaration, $confirmed ? self::CONFIRMED : self::REJECTED, $operatorId);
            if (!$confirmed) {
                return null;
            }
            // A company whose subscription was stopped between the declaration and the decision has nothing to extend.
            $subscription = $this->subscriptions->ofCompany($declaration->getCompany()->getId());
            if (null === $subscription) {
                return null;
            }
            $subscription->coverPeriods($periods, $now);
            $this->subscriptions->save($subscription);

            return $subscription->getTerms()->paidUntil;
        });

        $this->tellOwners($declaration, $paidThrough);

        return $declaration;
    }

    private function tellOperators(PaymentDeclaration $declaration): void
    {
        $company = $declaration->getCompany();
        foreach ($this->users->platformOperators() as $operator) {
            $this->notifications->publish(new Notification(
                'user:'.$operator->getId()->toRfc4122(),
                self::NOTIFICATION_DECLARED,
                [
                    'company' => $company->getName(),
                    'amount' => $declaration->getAmount(),
                    'currency' => $declaration->getCurrency(),
                    'declaration_id' => $declaration->getId()->toRfc4122(),
                ],
            ));
            $this->mailer->paymentDeclared(new PaymentDeclaredMail(
                $operator->getEmail()->value,
                $operator->getLocale(),
                $company->getName(),
                $declaration->getAmount(),
                $declaration->getCurrency(),
                $declaration->getMethod()->value,
                $declaration->getPaidOn()->format('Y-m-d'),
                $declaration->getReference(),
                $this->platformUrl,
            ));
        }
    }

    private function tellOwners(PaymentDeclaration $declaration, ?\DateTimeImmutable $paidThrough): void
    {
        $company = $declaration->getCompany();
        $confirmed = DeclarationStatus::Confirmed === $declaration->getStatus();
        foreach ($this->memberships->ofCompany($company->getId()) as $membership) {
            if (Role::OWNER !== $membership->getRole()->getName()) {
                continue;
            }
            $owner = $membership->getUser();
            $this->notifications->publish(new Notification(
                'user:'.$owner->getId()->toRfc4122(),
                self::NOTIFICATION_DECIDED,
                [
                    'company' => $company->getName(),
                    'confirmed' => $confirmed,
                    'amount' => $declaration->getAmount(),
                    'currency' => $declaration->getCurrency(),
                ],
            ));
            $this->mailer->paymentDecided(new PaymentDecidedMail(
                $owner->getEmail()->value,
                $owner->getLocale(),
                $company->getName(),
                $confirmed,
                $declaration->getAmount(),
                $declaration->getCurrency(),
                $paidThrough?->setTimezone(new \DateTimeZone($company->getTimezone()))->format('Y-m-d'),
                $declaration->getDecisionNote(),
                $this->loginUrl,
            ));
        }
    }

    private function record(PaymentDeclaration $declaration, string $action, Uuid $actorId): void
    {
        $this->audit->record(new AuditEntry(
            self::ENTITY_TYPE,
            $declaration->getId(),
            $action,
            $actorId,
            [
                'amount' => $declaration->getAmount(),
                'currency' => $declaration->getCurrency(),
                'method' => $declaration->getMethod()->value,
                'paidOn' => $declaration->getPaidOn()->format('Y-m-d'),
                'status' => $declaration->getStatus()->value,
            ],
            $declaration->getCompany()->getId(),
        ));
    }
}
