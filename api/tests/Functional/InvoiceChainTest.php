<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Fiscal\Application\Company\ProvisionCompany;
use App\Fiscal\Application\Regime\SyncCustomerTaxRegimes;
use App\Fiscal\Domain\CustomerTaxRegimeRepository;
use App\Fiscal\Domain\UnitRepository;
use App\Module\Customers\Domain\Customer;
use App\Module\Invoices\Application\Chain\ChainBreak;
use App\Module\Invoices\Application\Chain\SealInvoiceChains;
use App\Module\Invoices\Application\Chain\VerifyInvoiceChains;
use App\Tenancy\Domain\Company;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Each issued document is chained to the one issued before it in its series: its hash covers what it says and the hash
 * before it, so a figure changed afterwards, or a document taken out or put in, shows (docs/SPEC.md § 8 row 271). The
 * hash is checked from what the database gives back, which is how anyone checking it later reads it.
 */
final class InvoiceChainTest extends ApiTestCase
{
    private Company $company;
    private string $customerId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = $this->createCompany('Acme');
        static::getContainer()->get(ProvisionCompany::class)->handle($this->company);
        static::getContainer()->get(SyncCustomerTaxRegimes::class)->handle();
        $regime = static::getContainer()->get(CustomerTaxRegimeRepository::class)->ofPresetAndCode('TN', 'standard');
        self::assertNotNull($regime);
        $customer = Customer::create($this->company, 'CLI-0001', self::aTunisianBusiness('Carthage Conseil'), null, $regime, [], new \DateTimeImmutable());
        $this->em()->persist($customer);
        $this->em()->flush();
        $this->customerId = $customer->getId()->toRfc4122();
        $this->createUser('sales@twes.local', 'password-1234', $this->company, ['invoice.read', 'invoice.write', 'invoice.issue'], 'member');
        $this->login('sales@twes.local', 'password-1234');
    }

    public function testEachIssuedInvoiceLinksToTheOneIssuedBeforeItInItsSeries(): void
    {
        $first = $this->draft('100');
        $second = $this->draft('250.5');
        $third = $this->draft('3');
        // Issued in another order than drafted: the chain follows the issue.
        $this->issue($second);
        $this->issue($third);
        $this->issue($first);

        $links = $this->links();
        self::assertSame([$second, $third, $first], array_column($links, 'id'));
        self::assertSame([1, 2, 3], array_column($links, 'chain_position'));
        self::assertNull($links[0]['chain_previous']);
        self::assertSame($links[0]['chain_hash'], $links[1]['chain_previous']);
        self::assertSame($links[1]['chain_hash'], $links[2]['chain_previous']);
        foreach ($links as $link) {
            self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $link['chain_hash']);
            self::assertSame(1, $link['chain_version']);
        }
        self::assertSame([], $this->breaks(), 'the hashes made at issue are the ones the stored rows give again');
    }

    public function testWhatChangesAfterIssueLeavesTheChainWhole(): void
    {
        $id = $this->draft('100');
        $this->issue($id);
        // Paying, crediting and printing move these columns; the document itself says the same.
        $this->connection()->executeStatement(
            "UPDATE invoice SET amount_paid = '50.000', amount_due = amount_due - 50, status = 'partially_paid', updated_at = now() WHERE id = ?",
            [$id],
        );

        self::assertSame([], $this->breaks());
    }

    public function testAFigureOrALineChangedAfterIssueBreaksTheChain(): void
    {
        $first = $this->draft('100');
        $second = $this->draft('200');
        $this->issue($first);
        $this->issue($second);

        $this->connection()->executeStatement('UPDATE invoice SET total_gross = total_gross + 1 WHERE id = ?', [$first]);
        $this->connection()->executeStatement("UPDATE invoice_line SET description = 'Autre chose' WHERE invoice_id = ?", [$second]);

        $breaks = $this->breaks();
        self::assertSame([ChainBreak::HASH, ChainBreak::HASH], array_map(static fn (ChainBreak $break): string => $break->reason, $breaks));
        self::assertSame([$this->numberOf($first), $this->numberOf($second)], array_map(static fn (ChainBreak $break): ?string => $break->number, $breaks));
    }

    public function testADocumentTakenOutOfTheChainShows(): void
    {
        $first = $this->draft('100');
        $second = $this->draft('200');
        $third = $this->draft('300');
        $this->issue($first);
        $this->issue($second);
        $this->issue($third);

        // The second one's place, emptied: the third one no longer follows what is before it.
        $this->connection()->executeStatement('UPDATE invoice SET chain_position = 99 WHERE id = ?', [$second]);

        $reasons = array_map(static fn (ChainBreak $break): string => $break->reason, $this->breaks());
        self::assertContains(ChainBreak::POSITION, $reasons);
        self::assertContains(ChainBreak::LINK, $reasons);
    }

    public function testTwoDocumentsCannotHoldOnePlaceOfASeries(): void
    {
        $first = $this->draft('100');
        $second = $this->draft('200');
        $this->issue($first);
        $this->issue($second);

        $connection = $this->connection();
        $connection->beginTransaction();
        try {
            $connection->executeStatement('UPDATE invoice SET chain_position = 1, chain_previous = NULL WHERE id = ?', [$second]);
            self::fail('A second document took the first place of the series.');
        } catch (UniqueConstraintViolationException) {
            $this->addToAssertionCount(1);
        } finally {
            $connection->rollBack();
        }
    }

    public function testInvoicesIssuedBeforeTheChainAreSealedInIssueOrderBeforeTheNextOne(): void
    {
        $first = $this->draft('100');
        $second = $this->draft('200');
        $this->issue($second);
        $this->issue($first);
        $this->unseal();
        self::assertNotSame([], $this->breaks(), 'an issued document left unsealed is reported');

        $third = $this->draft('300');
        $this->issue($third);

        self::assertSame([$second, $first, $third], array_column($this->links(), 'id'));
        self::assertSame([], $this->breaks());
    }

    public function testSealingAtStartChainsWhatIsUnsealedAndIsSafeToRunAgain(): void
    {
        $first = $this->draft('100');
        $second = $this->draft('200');
        $this->issue($first);
        $this->issue($second);
        $before = $this->links();
        $this->unseal();

        $seal = static::getContainer()->get(SealInvoiceChains::class);
        self::assertSame(2, $seal->all());
        self::assertSame(0, $seal->all(), 'nothing is sealed twice');

        self::assertSame($before, $this->links(), 'sealed again, the same documents give the same chain');
        self::assertSame([], $this->breaks());
    }

    /** As the release before the chain left them, and nothing of them kept in memory. */
    private function unseal(): void
    {
        $this->connection()->executeStatement('UPDATE invoice SET chain_position = NULL, chain_previous = NULL, chain_hash = NULL, chain_version = NULL WHERE company_id = ?', [$this->company->getId()->toRfc4122()]);
        $this->em()->clear();
    }

    /** @return list<ChainBreak> */
    private function breaks(): array
    {
        $this->em()->clear();

        return static::getContainer()->get(VerifyInvoiceChains::class)->of($this->company->getId());
    }

    /** @return list<array{id: string, chain_position: int, chain_previous: string|null, chain_hash: string, chain_version: int}> */
    private function links(): array
    {
        /** @var list<array{id: string, chain_position: int, chain_previous: string|null, chain_hash: string, chain_version: int}> $links */
        $links = $this->connection()->fetchAllAssociative(
            'SELECT id::text AS id, chain_position, chain_previous, chain_hash, chain_version FROM invoice WHERE company_id = ? AND number IS NOT NULL ORDER BY chain_position',
            [$this->company->getId()->toRfc4122()],
        );

        return $links;
    }

    private function numberOf(string $id): string
    {
        $number = $this->connection()->fetchOne('SELECT number FROM invoice WHERE id = ?', [$id]);
        self::assertIsString($number);

        return $number;
    }

    private function connection(): Connection
    {
        return $this->em()->getConnection();
    }

    private function issue(string $id): void
    {
        $this->postJson($this->path($id).'/issue', null);
        self::assertResponseIsSuccessful();
    }

    private function draft(string $price): string
    {
        $unit = static::getContainer()->get(UnitRepository::class)->ofCodeInCompany('C62', $this->company->getId());
        self::assertNotNull($unit);
        $this->postJson($this->path(), [
            'customerId' => $this->customerId,
            'establishmentId' => null,
            'supplyDate' => null,
            'paymentTermsDays' => null,
            'customerReference' => null,
            'notesPrinted' => null,
            'notesInternal' => null,
            'discountAmount' => null,
            'documentTaxComponentIds' => null,
            'lines' => [['description' => 'Conseil', 'quantity' => '2', 'unitId' => $unit->getId()->toRfc4122(), 'unitPriceNet' => $price, 'taxComponentIds' => []]],
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        return $this->stringAt($this->json(), 'id');
    }

    private function path(?string $id = null): string
    {
        return '/api/companies/'.$this->company->getId()->toRfc4122().'/invoices'.(null === $id ? '' : '/'.$id);
    }
}
