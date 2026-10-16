<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Fiscal\Application\Company\ProvisionCompany;
use App\Fiscal\Application\Regime\SyncCustomerTaxRegimes;
use App\Tenancy\Domain\Model\Company;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

/**
 * A vendor's IBAN or BIC, set or changed, asks who is at the screen again, as the company's own bank details do
 * (docs/SPEC.md § 7, the step-up list): a stolen session pointing a supplier's payments at another account is the same
 * attack. The rest of a vendor is saved as before, and so are the same bank details written as a person types them.
 * An import asks once, for the whole file, before anything is kept; its preview asks nothing.
 */
final class VendorBankDetailsStepUpTest extends ApiTestCase
{
    private const string PASSWORD = 'password-1234';
    private const string IBAN = 'TN5910006035183598478831';
    private const string HEADER = 'number,name,iban,bic';

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        static::getContainer()->get(SyncCustomerTaxRegimes::class)->handle();
        $this->company = $this->createCompany('Acme');
        static::getContainer()->get(ProvisionCompany::class)->handle($this->company);
        $this->createUser('buyer@twes.local', self::PASSWORD, $this->company, ['vendor.read', 'vendor.write'], 'member');
        $this->login('buyer@twes.local', self::PASSWORD);
    }

    protected function tearDown(): void
    {
        Clock::set(new NativeClock());
        parent::tearDown();
    }

    public function testAVendorCreatedWithBankDetailsWaitsForTheProof(): void
    {
        $this->postJson($this->path(), $this->vendor(['iban' => self::IBAN]));

        $this->assertStepUpRequired();
        self::assertSame(0, $this->vendors());

        $this->stepUp(self::PASSWORD);
        $this->postJson($this->path(), $this->vendor(['iban' => self::IBAN]));
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
    }

    public function testAVendorCreatedWithoutBankDetailsAsksNothing(): void
    {
        $this->postJson($this->path(), $this->vendor());

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
    }

    public function testChangingAVendorsIbanOrBicWaitsForTheProofAndKeepsTheOldOnes(): void
    {
        $id = $this->createdWithBankDetails();

        $this->sendJson('PUT', $this->path($id), $this->vendor(['version' => 1, 'iban' => 'TN7004018104003691000188', 'bic' => 'BIATTNTT']));
        $this->assertStepUpRequired();
        $this->sendJson('PUT', $this->path($id), $this->vendor(['version' => 1, 'iban' => self::IBAN, 'bic' => 'STBKTNTT']));
        $this->assertStepUpRequired();
        $this->sendJson('PUT', $this->path($id), $this->vendor(['version' => 1, 'iban' => null, 'bic' => 'BIATTNTT']));
        $this->assertStepUpRequired();

        $this->getJson($this->path($id));
        self::assertSame(self::IBAN, $this->json()['iban']);
        self::assertSame('BIATTNTT', $this->json()['bic']);
    }

    public function testTheRestOfAVendorAndTheSameBankDetailsAsTypedAreSavedWithoutAProof(): void
    {
        $id = $this->createdWithBankDetails();

        $this->sendJson('PUT', $this->path($id), $this->vendor([
            'version' => 1,
            'iban' => 'tn59 1000 6035 1835 9847 8831',
            'bic' => 'biattntt',
            'city' => 'Sfax',
        ]));

        self::assertResponseIsSuccessful();
        self::assertSame('Sfax', $this->json()['city']);
    }

    /** The proof is asked last: a vendor that is not there is not found, as before. */
    public function testAVendorThatIsNotThereIsNotFoundBeforeAnyProof(): void
    {
        $this->sendJson('PUT', $this->path(Uuid::v7()->toRfc4122()), $this->vendor(['version' => 1, 'iban' => self::IBAN]));

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testAnImportSettingBankDetailsWaitsForTheProofAndKeepsNothingBefore(): void
    {
        $file = self::HEADER."\nFRN-0001,Aciers du Sud,".self::IBAN.",BIATTNTT\nFRN-0002,Outillage Pro,,\n";

        $this->import($file);
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertSame('step_up_required', $this->json()['error'] ?? null);
        self::assertSame(0, $this->vendors(), 'the whole file waits, not only the row with bank details');

        $this->stepUp(self::PASSWORD);
        $this->import($file);
        self::assertResponseIsSuccessful();
        self::assertSame(2, $this->vendors());
    }

    public function testAnImportChangingAVendorsBankDetailsWaitsForTheProof(): void
    {
        $this->createdWithBankDetails();

        $this->import(self::HEADER."\nFRN-0001,Sotumag,TN7004018104003691000188,\n", 'upsert');

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertSame('step_up_required', $this->json()['error'] ?? null);
    }

    public function testThePreviewAndAFileWithoutBankDetailsAskNothing(): void
    {
        $this->import(self::HEADER."\nFRN-0001,Aciers du Sud,".self::IBAN.",BIATTNTT\n", dryRun: true);
        self::assertResponseIsSuccessful();
        self::assertSame([2], $this->json()['created']);

        $this->import(self::HEADER."\nFRN-0001,Aciers du Sud,,\n");
        self::assertResponseIsSuccessful();
        self::assertSame(1, $this->vendors());

        // A vendor's bank details written again as they are held change nothing, so ask nothing.
        $this->stepUp(self::PASSWORD);
        $this->import(self::HEADER."\nFRN-0001,Aciers du Sud,".self::IBAN.",BIATTNTT\n", 'upsert');
        self::assertResponseIsSuccessful();
        $this->forgetTheProof();
        $this->import(self::HEADER."\nFRN-0001,Aciers du Sud,tn59 1000 6035 1835 9847 8831,biattntt\n", 'upsert');
        self::assertResponseIsSuccessful();
    }

    /** Created with a proof, which then lapses: what follows starts without one. */
    private function createdWithBankDetails(): string
    {
        $this->stepUp(self::PASSWORD);
        $this->postJson($this->path(), $this->vendor(['iban' => self::IBAN, 'bic' => 'BIATTNTT']));
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $id = $this->stringAt($this->json(), 'id');
        $this->forgetTheProof();

        return $id;
    }

    /** Refused with the token API Platform's refusals lead their detail with, which the screens read to ask for the proof. */
    private function assertStepUpRequired(): void
    {
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertStringStartsWith('step_up_required:', $this->stringAt($this->json(), 'detail'));
    }

    /** The few minutes a proof holds (app.step_up.valid_for) have passed. */
    private function forgetTheProof(): void
    {
        Clock::set(new MockClock(new \DateTimeImmutable('+10 minutes')));
    }

    private function vendors(): int
    {
        $count = $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM vendor');
        self::assertIsInt($count);

        return $count;
    }

    private function import(string $contents, string $mode = 'create', bool $dryRun = false): void
    {
        $this->uploadFile('/api/companies/'.$this->company->getId()->toRfc4122().'/imports/vendors', 'vendors.csv', $contents, 'file', ['mode' => $mode, 'dryRun' => $dryRun ? '1' : '0']);
    }

    /**
     * @param array<string, mixed> $changes
     *
     * @return array<string, mixed>
     */
    private function vendor(array $changes = []): array
    {
        return [...[
            'number' => 'FRN-0001',
            'name' => 'Sotumag',
            'identifiers' => [],
            'addressLine1' => 'Zone industrielle',
            'city' => 'Ben Arous',
            'iban' => null,
            'bic' => null,
            'paymentTermsDays' => 30,
            'isActive' => true,
        ], ...$changes];
    }

    private function path(?string $vendorId = null): string
    {
        return '/api/companies/'.$this->company->getId()->toRfc4122().'/vendors'.(null === $vendorId ? '' : '/'.$vendorId);
    }
}
