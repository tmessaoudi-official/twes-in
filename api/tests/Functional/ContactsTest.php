<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Fiscal\Application\Regime\SyncCustomerTaxRegimes;
use App\Fiscal\Domain\Repository\CustomerTaxRegimeRepository;
use App\Module\Customers\Domain\Model\Customer;
use App\Module\Customers\Domain\ValueObject\CustomerKind;
use App\Module\Customers\Domain\ValueObject\CustomerProfile;
use App\Settings\Application\PhoneSettings;
use App\Settings\Domain\Model\Setting;
use App\Settings\Domain\ValueObject\SettingAddress;
use App\Shared\Domain\ValueObject\CheckMark;
use App\Shared\Infrastructure\Doctrine\Rows;
use App\Tenancy\Domain\Model\Company;
use Symfony\Component\HttpFoundation\Response;

final class ContactsTest extends ApiTestCase
{
    private Company $company;
    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        static::getContainer()->get(SyncCustomerTaxRegimes::class)->handle();
        $this->company = $this->createCompany('Acme');
        $this->customer = $this->customerOf($this->company, 'CLI-0001');
    }

    public function testTheFirstContactIsPrimaryAndAnotherCanTakeItOver(): void
    {
        $this->signedIn(['customer.read', 'customer.write']);

        $this->postJson($this->path(), ['firstName' => 'Leila', 'lastName' => 'Ben Salah', 'email' => 'leila@carthage.tn', 'role' => 'Comptable', 'isPrimary' => false]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        self::assertTrue($this->json()['isPrimary']);

        $this->postJson($this->path(), ['firstName' => 'Karim', 'isPrimary' => true]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        $this->getJson($this->path());
        $rows = $this->jsonList();
        self::assertSame(['Karim', 'Leila'], array_column($rows, 'firstName'));
        self::assertSame([true, false], array_column($rows, 'isPrimary'));
    }

    public function testAContactIsRevisedAndRemoved(): void
    {
        $this->signedIn(['customer.read', 'customer.write']);
        $this->postJson($this->path(), ['firstName' => 'Leila', 'isPrimary' => true]);
        $id = $this->stringAt($this->json(), 'id');

        $this->sendJson('PUT', $this->path($id), ['firstName' => 'Leila', 'lastName' => 'Trabelsi', 'phone' => '+216 71 000 000', 'isPrimary' => true, 'version' => 1]);
        self::assertResponseIsSuccessful();
        self::assertSame(['Trabelsi', 2], [$this->json()['lastName'], $this->json()['version']]);

        $this->sendJson('DELETE', $this->path($id));
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
        $this->getJson($this->path());
        self::assertSame([], $this->jsonList());
        self::assertEquals(3, $this->em()->getConnection()->fetchOne("SELECT COUNT(*) FROM audit_log WHERE entity_type = 'contact'"));
    }

    public function testAFormOpenedBeforeAnotherContactTookOverCannotTakeItBack(): void
    {
        // Another contact becoming the primary one steps this one down: a form opened before that, still showing it
        // primary, would take the role back unseen when saved for a new phone number.
        $this->signedIn(['customer.read', 'customer.write']);
        $this->postJson($this->path(), ['firstName' => 'Leila', 'isPrimary' => true]);
        $leila = $this->stringAt($this->json(), 'id');
        self::assertSame(1, $this->json()['version']);
        $this->postJson($this->path(), ['firstName' => 'Karim', 'isPrimary' => true]);

        $this->sendJson('PUT', $this->path($leila), ['firstName' => 'Leila', 'phone' => '+216 71 000 000', 'isPrimary' => true, 'version' => 1]);

        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        self::assertSame('/problems/record-changed', $this->json()['type']);
        $this->getJson($this->path());
        $rows = array_column($this->jsonList(), null, 'firstName');
        self::assertSame([true, false, 2, null], [$rows['Karim']['isPrimary'], $rows['Leila']['isPrimary'], $rows['Leila']['version'], $rows['Leila']['phone']]);
    }

    public function testARevisionThatChangesNothingKeepsItsVersionAndOneWithoutAVersionIsRefused(): void
    {
        $this->signedIn(['customer.read', 'customer.write']);
        $this->postJson($this->path(), ['firstName' => 'Leila', 'isPrimary' => true]);
        $id = $this->stringAt($this->json(), 'id');

        $this->sendJson('PUT', $this->path($id), ['firstName' => 'Leila', 'isPrimary' => true, 'version' => 1]);
        self::assertResponseIsSuccessful();
        self::assertSame(1, $this->json()['version']);

        $this->sendJson('PUT', $this->path($id), ['firstName' => 'Leïla', 'isPrimary' => true]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame(['version'], array_column($this->arrayAt($this->json(), 'violations'), 'propertyPath'));
    }

    /** A contact's phone is read by its customer's country, kept as E.164, and refused with that country's example. */
    public function testAContactsPhoneIsReadByItsCustomersCountry(): void
    {
        $this->signedIn(['customer.read', 'customer.write']);

        $this->postJson($this->path(), ['firstName' => 'Leila', 'phone' => '71.900.868', 'isPrimary' => true]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        self::assertSame('+216 71 900 868', $this->json()['phone']);
        $id = $this->stringAt($this->json(), 'id');

        $this->sendJson('PUT', $this->path($id), ['firstName' => 'Leila', 'phone' => '71 900', 'isPrimary' => true, 'version' => 1]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame([['propertyPath' => 'phone', 'reason' => 'phone_length', 'params' => ['example' => '+216 30 010 123', 'callingCode' => '+216']]], $this->refusals());

        // A phone kept before the rule is accepted while it is sent back unchanged.
        $this->em()->getConnection()->executeStatement("UPDATE contact SET phone = '71 900' WHERE id = ?", [$id]);
        $this->sendJson('PUT', $this->path($id), ['firstName' => 'Leila', 'lastName' => 'Trabelsi', 'phone' => '71 900', 'isPrimary' => true, 'version' => 1]);
        self::assertResponseIsSuccessful();
        self::assertSame(['Trabelsi', '71 900'], [$this->json()['lastName'], $this->json()['phone']]);
    }

    /**
     * A contact has no « À vérifier » of its own: a phone of the right length outside the numbers its country hands out,
     * kept under the platform's « Garder et marquer », marks its customer, the line naming the contact and the number
     * after the note the mark already carried, as the customer's own phone does. The same number sent back marks nothing
     * again, so a person who took the mark off is not handed it back by the next save.
     */
    public function testAContactsPhoneOutsideTheRangesMarksItsCustomerNamingTheContact(): void
    {
        $this->em()->persist(new Setting(SettingAddress::platform(), PhoneSettings::OUTSIDE_RANGES, PhoneSettings::KEEP, new \DateTimeImmutable()));
        $this->customer->reviseCheck(new CheckMark(true, 'Relancer la comptabilité.'), new \DateTimeImmutable());
        $this->em()->flush();
        $this->signedIn(['customer.read', 'customer.write']);

        $this->postJson($this->path(), ['firstName' => 'Leila', 'lastName' => 'Trabelsi', 'phone' => '+216 11 111 111', 'isPrimary' => true]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $id = $this->stringAt($this->json(), 'id');
        self::assertSame('+216 11111111', $this->json()['phone']);

        [$marked, $note] = $this->markOfCustomer();
        self::assertTrue($marked);
        $lines = explode("\n", $note);
        self::assertCount(2, $lines, $note);
        self::assertSame('Relancer la comptabilité.', $lines[0], 'the note the mark carried comes first');
        self::assertStringContainsString('Leila Trabelsi', $lines[1], 'the line names the contact');
        self::assertStringContainsString('+216 11111111', $lines[1], 'and the number to check');
        self::assertSame(1, $this->customerRevisions(), 'the customer\'s mark is recorded in its journal');

        // The person who looked takes the mark off; the contact saved again with the same number leaves it off.
        $this->em()->getConnection()->executeStatement('UPDATE customer SET to_check = false, check_note = null WHERE id = ?', [$this->customer->getId()->toRfc4122()]);
        $this->sendJson('PUT', $this->path($id), ['firstName' => 'Leila', 'lastName' => 'Trabelsi', 'phone' => '+216 11 111 111', 'isPrimary' => true, 'version' => 1]);
        self::assertResponseIsSuccessful();
        self::assertFalse($this->markOfCustomer()[0]);
    }

    /** @return array{bool, string} */
    private function markOfCustomer(): array
    {
        $row = $this->em()->getConnection()->fetchAssociative('SELECT to_check, check_note FROM customer WHERE id = ?', [$this->customer->getId()->toRfc4122()]);
        self::assertIsArray($row);

        return [true === $row['to_check'], Rows::text($row['check_note'] ?? '')];
    }

    private function customerRevisions(): int
    {
        return Rows::int($this->em()->getConnection()->fetchOne("SELECT count(*) FROM audit_log WHERE entity_type = 'customer' AND action = 'customer.revised' AND entity_id = ?", [$this->customer->getId()->toRfc4122()]));
    }

    public function testAContactWithoutANameIsRefused(): void
    {
        $this->signedIn(['customer.read', 'customer.write']);

        $this->postJson($this->path(), ['email' => 'nobody@carthage.tn', 'isPrimary' => false]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame([['propertyPath' => 'lastName', 'reason' => 'name_required', 'params' => []]], $this->refusals());

        $this->postJson($this->path(), ['lastName' => str_repeat('x', 101), 'isPrimary' => false]);
        self::assertSame([['propertyPath' => 'lastName', 'reason' => 'invalid_text', 'params' => ['max' => 100]]], $this->refusals());
    }

    public function testAContactIsOnlyReachedThroughItsCustomerAndAReaderOnlyReads(): void
    {
        $other = $this->customerOf($this->company, 'CLI-0002');
        $theirs = $this->customerOf($this->createCompany('Globex'), 'CLI-0001');
        $this->signedIn(['customer.read', 'customer.write']);
        $this->postJson($this->path(), ['firstName' => 'Leila', 'isPrimary' => true]);
        $id = $this->stringAt($this->json(), 'id');

        $this->sendJson('PUT', $this->path($id, $other), ['firstName' => 'Hijacked', 'isPrimary' => true, 'version' => 1]);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        $this->getJson($this->path(null, $theirs));
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testAReaderCannotAddAContact(): void
    {
        $this->signedIn(['customer.read']);

        $this->getJson($this->path());
        self::assertResponseIsSuccessful();
        $this->postJson($this->path(), ['firstName' => 'Leila', 'isPrimary' => true]);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    private function customerOf(Company $company, string $number): Customer
    {
        $regime = static::getContainer()->get(CustomerTaxRegimeRepository::class)->ofPresetAndCode('TN', 'standard');
        self::assertNotNull($regime);
        $customer = Customer::create($company, $number, new CustomerProfile(CustomerKind::Company, 'Carthage Conseil'), null, $regime, [], new \DateTimeImmutable());
        $this->em()->persist($customer);
        $this->em()->flush();

        return $customer;
    }

    /** @param list<string> $permissions */
    private function signedIn(array $permissions): void
    {
        $this->createUser('sales@twes.local', 'password-1234', $this->company, $permissions, 'member');
        $this->login('sales@twes.local', 'password-1234');
        self::assertResponseIsSuccessful();
    }

    private function path(?string $contactId = null, ?Customer $customer = null): string
    {
        $customer ??= $this->customer;

        return '/api/companies/'.$customer->getCompany()->getId()->toRfc4122().'/customers/'.$customer->getId()->toRfc4122().'/contacts'.(null === $contactId ? '' : '/'.$contactId);
    }
}
