<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Tenancy\Domain\Company;
use App\Tenancy\Domain\Role;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mime\Email as MimeEmail;

/**
 * Cash payments (docs/SPEC.md § 7, 2026-09-17): the company declares what it paid, which keeps it open for the hold
 * days, the operator hears of it and confirms or rejects it, and only a confirmation carries the covered time forward.
 * One declaration waits at a time.
 */
final class SubscriptionPaymentsTest extends ApiTestCase
{
    private bool $operatorCreated = false;

    public function testACompanyDeclaresAPaymentWhichKeepsItOpenUntilTheOperatorDecides(): void
    {
        $company = $this->createCompany('Acme');
        $this->createUser('owner@twes.local', 'password-1234', $company);
        $this->subscribe($company, ['paidThrough' => $this->day(-30), 'graceDays' => 0, 'unpaidMode' => 'locked']);

        $this->login('owner@twes.local', 'password-1234');
        // Locked, and the way out is open: the subscription is read and a payment declared.
        $this->getJson($this->profileOf($company));
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

        $this->getJson($this->subscriptionOf($company));
        self::assertResponseIsSuccessful();
        self::assertSame('unpaid', $this->json()['stage']);
        // The key is THERE and null, not left out: assertNull alone passes on a missing key, and a reader that
        // cannot tell the two apart is what broke the locked company's own page (2026-09-17).
        self::assertArrayHasKey('openPayment', $this->json());
        self::assertNull($this->json()['openPayment']);
        self::assertArrayHasKey('trialEndsOn', $this->json());

        $this->postJson($this->paymentsOf($company), $this->payment());
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        self::assertSame('declared', $this->json()['status']);

        // The operator was told, at the address they signed up with. Asserted here: the mailer collector answers for
        // the request that just ran, and the reads below would leave it empty.
        $message = self::getMailerMessage();
        self::assertInstanceOf(MimeEmail::class, $message);
        self::assertSame('op@twes.local', $message->getTo()[0]->getAddress());
        self::assertStringContainsString('Acme', (string) $message->getSubject());

        // Held: the company works again while the operator has not answered.
        $this->getJson($this->subscriptionOf($company));
        self::assertSame('held', $this->json()['stage']);
        self::assertSame('full', $this->json()['access']);
        self::assertSame('600.000', $this->section($this->json(), 'openPayment')['amount']);
        $this->getJson($this->profileOf($company));
        self::assertResponseIsSuccessful();

        // One waits at a time.
        $this->postJson($this->paymentsOf($company), $this->payment());
        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
    }

    public function testTheOperatorSeesTheHoldOnTheCompaniesListToo(): void
    {
        $company = $this->createCompany('Acme');
        $this->createUser('owner@twes.local', 'password-1234', $company);
        $this->subscribe($company, ['paidThrough' => $this->day(-30), 'graceDays' => 0]);
        $this->login('owner@twes.local', 'password-1234');
        $this->postJson($this->paymentsOf($company), $this->payment());
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        // The list reads every company's open declaration in ONE query, keyed by company: a different path from the
        // one every other case here takes, and the operator's own page is its only caller.
        $this->signInOperator();
        $this->getJson('/api/platform/companies');
        self::assertResponseIsSuccessful();
        $rows = array_column($this->jsonList(), null, 'name');
        self::assertArrayHasKey('Acme', $rows);
        $standing = $rows['Acme']['subscription'] ?? null;
        self::assertIsArray($standing);
        self::assertSame('held', $standing['stage']);
        self::assertSame('full', $standing['access']);
    }

    public function testTheHoldLastsAsLongAsTheOperatorSaysAndNoLonger(): void
    {
        // Nothing holds when the hold is zero days: the declaration is recorded and the company stays as it was.
        $company = $this->createCompany('Acme');
        $this->createUser('owner@twes.local', 'password-1234', $company);
        $this->subscribe($company, ['paidThrough' => $this->day(-30), 'graceDays' => 0, 'holdDays' => 0]);

        $this->login('owner@twes.local', 'password-1234');
        $this->postJson($this->paymentsOf($company), $this->payment());
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $this->getJson($this->subscriptionOf($company));
        self::assertSame('unpaid', $this->json()['stage']);
        self::assertSame('read_only', $this->json()['access']);
        self::assertNotNull($this->json()['openPayment']);
    }

    public function testACompanyWithoutItsOwnHoldFollowsThePlatformSetting(): void
    {
        $company = $this->createCompany('Acme');
        $this->createUser('owner@twes.local', 'password-1234', $company);
        $this->subscribe($company, ['paidThrough' => $this->day(-30), 'graceDays' => 0, 'holdDays' => null]);
        $this->sendJson('PUT', '/api/platform/settings/licensing.hold_days', ['value' => 0]);
        self::assertResponseIsSuccessful();

        $this->login('owner@twes.local', 'password-1234');
        $this->postJson($this->paymentsOf($company), $this->payment());
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $this->getJson($this->subscriptionOf($company));
        self::assertSame('unpaid', $this->json()['stage'], 'the platform hold of zero days holds nothing');
    }

    public function testTheOperatorConfirmsThePaymentWhichCarriesTheCoveredTimeForward(): void
    {
        $company = $this->createCompany('Acme');
        $this->createUser('owner@twes.local', 'password-1234', $company);
        $this->subscribe($company, ['paidThrough' => $this->day(-30), 'graceDays' => 0, 'periodCount' => 1, 'periodUnit' => 'month']);
        $this->login('owner@twes.local', 'password-1234');
        $this->postJson($this->paymentsOf($company), $this->payment());
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $declarationId = $this->stringAt($this->json(), 'id');

        $this->signInOperator();
        $this->getJson('/api/platform/payment-declarations');
        self::assertResponseIsSuccessful();
        $waiting = $this->jsonList();
        self::assertCount(1, $waiting);
        self::assertSame('Acme', $waiting[0]['companyName']);

        $this->postJson('/api/platform/payment-declarations/'.$declarationId.'/confirm', ['periods' => 2, 'note' => 'reçu en espèces']);
        self::assertResponseIsSuccessful();
        self::assertSame('confirmed', $this->json()['status']);

        $this->getJson('/api/platform/payment-declarations');
        self::assertSame([], $this->jsonList());

        // An operator is no member of the company, so its own endpoint is read by its owner.
        $this->login('owner@twes.local', 'password-1234');
        $this->getJson($this->subscriptionOf($company));
        self::assertSame('paid', $this->json()['stage']);
        // Two periods from today, since the covered time was long past: paying buys the time ahead, never time spent.
        self::assertSame($this->inMonths(2), $this->json()['paidThrough']);
        self::assertNull($this->json()['openPayment']);
        self::assertSame('confirmed', $this->jsonPayments()[0]['status']);
    }

    public function testARejectedPaymentEndsTheHoldAtOnceAndTheOwnerIsTold(): void
    {
        $company = $this->createCompany('Acme');
        $this->createUser('owner@twes.local', 'password-1234', $company);
        $this->subscribe($company, ['paidThrough' => $this->day(-30), 'graceDays' => 0, 'unpaidMode' => 'read_only']);
        $this->login('owner@twes.local', 'password-1234');
        $this->postJson($this->paymentsOf($company), $this->payment());
        $declarationId = $this->stringAt($this->json(), 'id');

        $this->signInOperator();
        $this->postJson('/api/platform/payment-declarations/'.$declarationId.'/reject', ['note' => 'rien reçu']);
        self::assertResponseIsSuccessful();
        self::assertSame('rejected', $this->json()['status']);

        $message = self::getMailerMessage();
        self::assertInstanceOf(MimeEmail::class, $message);
        self::assertSame('owner@twes.local', $message->getTo()[0]->getAddress());

        $this->login('owner@twes.local', 'password-1234');
        $this->getJson($this->subscriptionOf($company));
        self::assertSame('unpaid', $this->json()['stage']);
        self::assertSame('read_only', $this->json()['access']);
        $this->sendJson('PUT', $this->profileOf($company), ['legalName' => 'Acme SARL']);
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

        // Rejected, so the way is open to declare again.
        $this->postJson($this->paymentsOf($company), $this->payment());
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
    }

    public function testAPaymentIsDecidedOnceAndOnlyByAnOperator(): void
    {
        $company = $this->createCompany('Acme');
        $this->createUser('owner@twes.local', 'password-1234', $company);
        $this->subscribe($company, ['paidThrough' => $this->day(-30)]);
        $this->login('owner@twes.local', 'password-1234');
        $this->postJson($this->paymentsOf($company), $this->payment());
        $declarationId = $this->stringAt($this->json(), 'id');

        $this->postJson('/api/platform/payment-declarations/'.$declarationId.'/confirm', []);
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

        $this->signInOperator();
        $this->postJson('/api/platform/payment-declarations/'.$declarationId.'/confirm', []);
        self::assertResponseIsSuccessful();
        $this->postJson('/api/platform/payment-declarations/'.$declarationId.'/reject', []);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function testACompanyWithoutASubscriptionHasNothingToDeclare(): void
    {
        $company = $this->createCompany('Globex');
        $this->createUser('owner@twes.local', 'password-1234', $company);
        $this->login('owner@twes.local', 'password-1234');

        $this->getJson($this->subscriptionOf($company));
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        $this->postJson($this->paymentsOf($company), $this->payment());
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testAMemberWhoDoesNotPayTheBillsDeclaresNothing(): void
    {
        $company = $this->createCompany('Acme');
        $this->createUser('owner@twes.local', 'password-1234', $company);
        $this->createUser('member@twes.local', 'password-1234', $company, ['invoice.read'], Role::MEMBER);
        $this->subscribe($company, ['paidThrough' => $this->day(-30)]);

        $this->login('member@twes.local', 'password-1234');
        $this->postJson($this->paymentsOf($company), $this->payment());
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testAPaymentThatCannotBeTrueIsUnprocessable(): void
    {
        $company = $this->createCompany('Acme');
        $this->createUser('owner@twes.local', 'password-1234', $company);
        $this->subscribe($company, ['paidThrough' => $this->day(-30)]);
        $this->login('owner@twes.local', 'password-1234');

        $this->postJson($this->paymentsOf($company), $this->payment(['amount' => '0']));
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $this->postJson($this->paymentsOf($company), $this->payment(['method' => 'barter']));
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $this->postJson($this->paymentsOf($company), $this->payment(['paidOn' => '16/09/2026']));
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    /** @return list<array<string, mixed>> the declarations the company-side answer carries, newest first */
    private function jsonPayments(): array
    {
        $rows = [];
        foreach ($this->arrayAt($this->json(), 'payments') as $row) {
            self::assertIsArray($row);
            $declaration = [];
            foreach ($row as $key => $value) {
                $declaration[(string) $key] = $value;
            }
            $rows[] = $declaration;
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function payment(array $overrides = []): array
    {
        return [
            'amount' => '600.000',
            'currency' => 'TND',
            'method' => 'cash',
            'paidOn' => $this->day(-1),
            'reference' => 'REC-12',
            'note' => null,
            ...$overrides,
        ];
    }

    /** @param array<string, mixed> $terms */
    private function subscribe(Company $company, array $terms): void
    {
        $this->signInOperator();
        $this->sendJson('PUT', '/api/platform/companies/'.$company->getId()->toRfc4122().'/subscription', [
            'periodCount' => 1,
            'periodUnit' => 'month',
            'trialEndsOn' => null,
            'paidThrough' => null,
            'price' => null,
            'currency' => null,
            'graceDays' => null,
            'unpaidMode' => null,
            'holdDays' => null,
            ...$terms,
        ]);
        self::assertResponseIsSuccessful();
    }

    private function signInOperator(): void
    {
        if (!$this->operatorCreated) {
            $this->createUser('op@twes.local', 'password-1234', operator: true);
            $this->operatorCreated = true;
        }
        $this->login('op@twes.local', 'password-1234');
    }

    private function inMonths(int $months): string
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('Africa/Tunis'))->modify(\sprintf('+%d months', $months))->format('Y-m-d');
    }

    private function day(int $days): string
    {
        return new \DateTimeImmutable('today', new \DateTimeZone('Africa/Tunis'))->modify(\sprintf('%+d days', $days))->format('Y-m-d');
    }

    private function subscriptionOf(Company $company): string
    {
        return '/api/companies/'.$company->getId()->toRfc4122().'/subscription';
    }

    private function paymentsOf(Company $company): string
    {
        return $this->subscriptionOf($company).'/payments';
    }

    private function profileOf(Company $company): string
    {
        return '/api/companies/'.$company->getId()->toRfc4122().'/profile';
    }
}
