<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Fiscal\Application\Company\ProvisionCompany;
use App\Fiscal\Application\Regime\SyncCustomerTaxRegimes;
use App\Fiscal\Domain\Repository\CustomerTaxRegimeRepository;
use App\Fiscal\Domain\Repository\UnitRepository;
use App\Module\Customers\Domain\Model\Customer;
use App\Shared\Infrastructure\Realtime\LiveChangesAnswer;
use App\Shared\Infrastructure\Scheduler\PurgeSubmissionsEveryHour;
use App\Shared\Infrastructure\Submissions\ClaimedSubmission;
use App\Shared\Infrastructure\Submissions\HeldClaim;
use App\Shared\Infrastructure\Submissions\SubmissionClaims;
use App\Shared\Infrastructure\Submissions\SubmissionGuard;
use App\Shared\Infrastructure\Submissions\Submissions;
use App\Tenancy\Domain\Model\Company;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Uid\Uuid;

/**
 * A write that adds (a payment, money kept to a customer's credit, goods taken in) sent twice is made once: the screen
 * sends an `Idempotency-Key` with it, and the same key from the same account answers what the first answered, with
 * `Idempotent-Replayed: true` and nothing made again (docs/SPEC.md § 3 « One submission, one write »).
 */
final class SubmissionReplayTest extends ApiTestCase
{
    use ClockSensitiveTrait;

    private const array OTHER_KEY = ['HTTP_IDEMPOTENCY_KEY' => '0192c3a4-1111-7000-8000-000000000001'];

    private Company $company;
    private string $customerId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = $this->createCompany('Acme');
        static::getContainer()->get(ProvisionCompany::class)->handle($this->company);
        static::getContainer()->get(SyncCustomerTaxRegimes::class)->handle();
        $this->customerId = $this->customer()->getId()->toRfc4122();
        $this->createUser('sales@twes.local', 'password-1234', $this->company, ['customer.read', 'invoice.read', 'invoice.write', 'invoice.issue', 'payment.write'], 'member');
        $this->createUser('other@twes.local', 'password-1234', $this->company, ['customer.read', 'invoice.read', 'payment.write'], 'cashier');
        $this->login('sales@twes.local', 'password-1234');
        self::assertResponseIsSuccessful();
    }

    public function testAPaymentSentTwiceUnderOneKeyIsRecordedOnceAndAnsweredTheSameBothTimes(): void
    {
        $invoice = $this->issue('100');

        $this->pay($invoice, '40', [...self::OTHER_KEY, 'HTTP_X_TAB' => 'tab-1']);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $first = (string) $this->client->getResponse()->getContent();
        $changes = $this->client->getResponse()->headers->get(LiveChangesAnswer::HEADER);
        self::assertNotNull($changes, 'the first answer names what changed');
        self::assertFalse($this->client->getResponse()->headers->has(Submissions::REPLAYED_HEADER));

        $this->pay($invoice, '40', [...self::OTHER_KEY, 'HTTP_X_TAB' => 'tab-1']);

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        self::assertSame($first, (string) $this->client->getResponse()->getContent());
        self::assertSame('true', $this->client->getResponse()->headers->get(Submissions::REPLAYED_HEADER));
        self::assertSame($changes, $this->client->getResponse()->headers->get(LiveChangesAnswer::HEADER), 'a tab that lost the first answer still reads its list again');
        self::assertSame(['60.000', 1], $this->dueAndPayments($invoice));
    }

    public function testMoneyKeptToACustomersCreditSentTwiceUnderOneKeyIsKeptOnce(): void
    {
        $invoice = $this->issue('100');
        $this->pay($invoice, '100');

        foreach ([Response::HTTP_NO_CONTENT, Response::HTTP_NO_CONTENT] as $status) {
            $this->postJson($this->invoicePath($invoice).'/overpayments', ['amount' => '500', 'date' => $this->today()], true, self::OTHER_KEY);
            self::assertResponseStatusCodeSame($status);
        }

        $this->getJson($this->companyPath().'/customers/'.$this->customerId.'/credit-balance');
        self::assertSame(['500.000', 1], [$this->json()['balance'], \count($this->arrayAt($this->json(), 'entries'))]);
    }

    public function testAnAddingWriteWithoutAKeyIsRefusedAndMakesNothing(): void
    {
        $invoice = $this->issue('100');

        $this->pay($invoice, '40', [Submissions::KEY_SERVER => null]);

        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        self::assertSame(Submissions::KEY_MISSING, $this->json()['type']);
        self::assertSame(['100.000', 0], $this->dueAndPayments($invoice));
    }

    public function testAKeyThatIsNotAUuidIsRefused(): void
    {
        $invoice = $this->issue('100');

        foreach (['abc', '', str_repeat('a', 300), '"0192c3a4-1111-7000-8000-00000000000g"'] as $key) {
            $this->pay($invoice, '40', [Submissions::KEY_SERVER => $key]);
            self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST, $key);
            self::assertSame('' === $key ? Submissions::KEY_MISSING : Submissions::KEY_INVALID, $this->json()['type'], $key);
        }
        self::assertSame(['100.000', 0], $this->dueAndPayments($invoice));
    }

    public function testAKeyWrittenAsAStructuredFieldStringIsTheSameKey(): void
    {
        $invoice = $this->issue('100');

        $this->pay($invoice, '40', self::OTHER_KEY);
        $this->pay($invoice, '40', [Submissions::KEY_SERVER => '"'.self::OTHER_KEY['HTTP_IDEMPOTENCY_KEY'].'"']);

        self::assertSame('true', $this->client->getResponse()->headers->get(Submissions::REPLAYED_HEADER));
        self::assertSame(['60.000', 1], $this->dueAndPayments($invoice));
    }

    public function testOneKeySentWithAnotherBodyIsRefusedAndMakesNothingMore(): void
    {
        $invoice = $this->issue('100');
        $this->pay($invoice, '40', self::OTHER_KEY);

        $this->pay($invoice, '30', self::OTHER_KEY);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame(Submissions::KEY_REUSED, $this->json()['type']);
        self::assertSame(['60.000', 1], $this->dueAndPayments($invoice));
    }

    public function testOneKeySentToAnotherAddressIsRefused(): void
    {
        $first = $this->issue('100');
        $second = $this->issue('100');
        $this->pay($first, '40', self::OTHER_KEY);

        $this->pay($second, '40', self::OTHER_KEY);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame(Submissions::KEY_REUSED, $this->json()['type']);
        self::assertSame(['100.000', 0], $this->dueAndPayments($second));
    }

    public function testARefusedSubmissionLeavesItsKeyFreeForTheCorrectedOne(): void
    {
        $invoice = $this->issue('100');

        $this->pay($invoice, '500', self::OTHER_KEY);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $this->pay($invoice, '40', self::OTHER_KEY);

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        self::assertFalse($this->client->getResponse()->headers->has(Submissions::REPLAYED_HEADER));
        self::assertSame(['60.000', 1], $this->dueAndPayments($invoice));
    }

    public function testAKeyStillBeingWorkedOnAnswersThatItIsAndMakesNothing(): void
    {
        $invoice = $this->issue('100');
        $this->claim(self::OTHER_KEY['HTTP_IDEMPOTENCY_KEY'], $invoice, '40', 'now', false);

        $this->pay($invoice, '40', self::OTHER_KEY);

        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        self::assertSame(Submissions::IN_PROGRESS, $this->json()['type']);
        self::assertSame(['100.000', 0], $this->dueAndPayments($invoice));
    }

    public function testAWriteThatWasMadeButNeverAnsweredIsNeverMadeAgain(): void
    {
        $invoice = $this->issue('100');
        $this->claim(self::OTHER_KEY['HTTP_IDEMPOTENCY_KEY'], $invoice, '40', '-1 hour', true);

        $this->pay($invoice, '40', self::OTHER_KEY);

        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        self::assertSame(Submissions::UNANSWERED, $this->json()['type']);
        self::assertSame(['100.000', 0], $this->dueAndPayments($invoice), 'the claim stands for a payment the test never made');
    }

    public function testAKeyWhoseWorkStoppedWithoutBeingMadeIsTakenAgainOnceItCannotStillRun(): void
    {
        $invoice = $this->issue('100');
        $this->claim(self::OTHER_KEY['HTTP_IDEMPOTENCY_KEY'], $invoice, '40', '-1 hour', false);

        $this->pay($invoice, '40', self::OTHER_KEY);

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        self::assertSame(['60.000', 1], $this->dueAndPayments($invoice));
    }

    public function testARequestWhoseClaimWasTakenBackNeitherFreesNorAnswersTheClaimThatTookIt(): void
    {
        $invoice = $this->issue('100');
        $stopped = $this->claim(self::OTHER_KEY['HTTP_IDEMPOTENCY_KEY'], $invoice, '40', '-1 hour', false);
        $this->pay($invoice, '40', self::OTHER_KEY);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $first = (string) $this->client->getResponse()->getContent();

        $late = new HeldClaim($this->account(), Uuid::fromString(self::OTHER_KEY['HTTP_IDEMPOTENCY_KEY']), Uuid::fromString($stopped));
        $claims = static::getContainer()->get(SubmissionClaims::class);
        $claims->release($late);
        $claims->answer($late, Response::HTTP_GONE, [], 'late');
        $this->pay($invoice, '40', self::OTHER_KEY);

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        self::assertSame($first, (string) $this->client->getResponse()->getContent());
        self::assertSame('true', $this->client->getResponse()->headers->get(Submissions::REPLAYED_HEADER));
        self::assertSame(['60.000', 1], $this->dueAndPayments($invoice));
    }

    public function testAWriteMadeButAnsweredWithAServerErrorIsNeverToldAsFailedAgain(): void
    {
        $invoice = $this->issue('100');
        $held = new HeldClaim($this->account(), Uuid::fromString(self::OTHER_KEY['HTTP_IDEMPOTENCY_KEY']), Uuid::fromString(
            $this->claim(self::OTHER_KEY['HTTP_IDEMPOTENCY_KEY'], $invoice, '40', 'now', false),
        ));
        $claimed = static::getContainer()->get(ClaimedSubmission::class);
        $claimed->hold($held);
        $claimed->begin();
        $claimed->commit();
        $request = Request::create($this->invoicePath($invoice).'/payments', 'POST');
        $request->attributes->set(SubmissionGuard::WON, $held);
        static::getContainer()->get(SubmissionGuard::class)->onResponse(new ResponseEvent($this->client->getKernel(), $request, HttpKernelInterface::MAIN_REQUEST, new Response('{"error":"broken"}', Response::HTTP_INTERNAL_SERVER_ERROR)));
        $claimed->reset();

        $this->pay($invoice, '40', self::OTHER_KEY);

        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        self::assertSame(Submissions::UNANSWERED, $this->json()['type']);
    }

    public function testAKeyIsEachAccountsOwn(): void
    {
        $invoice = $this->issue('100');
        $this->pay($invoice, '40', self::OTHER_KEY);

        $this->login('other@twes.local', 'password-1234');
        $this->pay($invoice, '40', self::OTHER_KEY);

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        self::assertFalse($this->client->getResponse()->headers->has(Submissions::REPLAYED_HEADER));
        self::assertSame(['20.000', 2], $this->dueAndPayments($invoice));
    }

    public function testAKeyIsKeptForItsHoursThenForgotten(): void
    {
        $invoice = $this->issue('100');
        $this->pay($invoice, '40', self::OTHER_KEY);
        $kept = static::getContainer()->getParameter('app.submissions.kept_hours');

        self::mockTime('+'.($kept - 1).' hours');
        static::getContainer()->get(PurgeSubmissionsEveryHour::class)();
        self::assertSame(1, $this->claims());
        self::mockTime('+'.$kept.' hours +1 second');
        static::getContainer()->get(PurgeSubmissionsEveryHour::class)();

        self::assertSame(0, $this->claims());
    }

    /** @param array<string, string|null> $server */
    private function pay(string $invoice, string $amount, array $server = []): void
    {
        $this->postJson($this->invoicePath($invoice).'/payments', ['date' => $this->today(), 'amount' => $amount, 'method' => 'cash', 'reference' => null, 'notes' => null], true, $server);
    }

    /** @return array{string, int} what is still due on the invoice and how many payments it holds */
    private function dueAndPayments(string $invoice): array
    {
        $this->getJson($this->invoicePath($invoice));
        self::assertResponseIsSuccessful();

        return [$this->stringAt($this->json(), 'amountDue'), \count($this->arrayAt($this->json(), 'payments'))];
    }

    /**
     * A claim another request made: its body the payment `pay` would send, its work made or not, and no answer kept.
     *
     * @return string the attempt holding it
     */
    private function claim(string $key, string $invoice, string $amount, string $when, bool $workDone): string
    {
        $body = json_encode(['date' => $this->today(), 'amount' => $amount, 'method' => 'cash', 'reference' => null, 'notes' => null], \JSON_THROW_ON_ERROR);
        $attempt = Uuid::v7()->toRfc4122();
        $this->em()->getConnection()->insert('submission_claim', [
            'account_id' => $this->account()->toRfc4122(),
            'key' => $key,
            'attempt' => $attempt,
            'company_id' => $this->company->getId()->toRfc4122(),
            'fingerprint' => Submissions::fingerprint('POST', $this->invoicePath($invoice).'/payments', $body),
            'claimed_at' => new \DateTimeImmutable(self::apiNow(), new \DateTimeZone('UTC'))->modify($when)->format('Y-m-d H:i:s'),
            'work_done' => $workDone ? 'true' : 'false',
        ]);

        return $attempt;
    }

    private function account(): Uuid
    {
        $account = $this->em()->getConnection()->fetchOne('SELECT id FROM "user" WHERE email = ?', ['sales@twes.local']);
        self::assertIsString($account);

        return Uuid::fromString($account);
    }

    private function claims(): int
    {
        $count = $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM submission_claim');
        self::assertIsInt($count);

        return $count;
    }

    private function issue(string $net): string
    {
        $this->postJson($this->companyPath().'/invoices', [
            'customerId' => $this->customerId,
            'establishmentId' => null,
            'supplyDate' => null,
            'paymentTermsDays' => null,
            'customerReference' => null,
            'notesPrinted' => null,
            'notesInternal' => null,
            'discountAmount' => null,
            'documentTaxComponentIds' => [],
            'lines' => [['description' => 'Prestation', 'quantity' => '1', 'unitId' => $this->unitId(), 'unitPriceNet' => $net, 'taxComponentIds' => []]],
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $id = $this->stringAt($this->json(), 'id');
        $this->postJson($this->invoicePath($id).'/issue', null);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);

        return $id;
    }

    private function customer(): Customer
    {
        $regime = static::getContainer()->get(CustomerTaxRegimeRepository::class)->ofPresetAndCode('TN', 'standard');
        self::assertNotNull($regime);
        $customer = Customer::create($this->company, 'CLI-0001', self::aTunisianBusiness('Carthage Conseil'), null, $regime, [], new \DateTimeImmutable());
        $this->em()->persist($customer);
        $this->em()->flush();

        return $customer;
    }

    private function unitId(): string
    {
        $unit = static::getContainer()->get(UnitRepository::class)->ofCodeInCompany('C62', $this->company->getId());
        self::assertNotNull($unit);

        return $unit->getId()->toRfc4122();
    }

    private function today(): string
    {
        return self::dayOf($this->company);
    }

    private function companyPath(): string
    {
        return '/api/companies/'.$this->company->getId()->toRfc4122();
    }

    private function invoicePath(string $id): string
    {
        return $this->companyPath().'/invoices/'.$id;
    }
}
