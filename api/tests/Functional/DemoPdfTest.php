<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Demo\Application\BuildSandbox;
use App\Demo\Domain\Model\DemoSandbox;
use App\Demo\Domain\Repository\DemoSandboxRepository;
use App\Demo\Infrastructure\Http\DemoAccountGuard;
use App\Tenancy\Application\Seed\SeedPlatform;
use App\Tests\Support\FakePdfRenderer;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * A demo's document says « DÉMO — sans valeur fiscale » on every page, top and bottom, whatever prints it: what the
 * build issues and stores, and each document the visitor prints. Factur-X, a file meant for a real customer's books,
 * is refused there. The same company once it is no sandbox prints as any other.
 */
final class DemoPdfTest extends ApiTestCase
{
    use MakesSandboxes;

    private const string BAND = 'DÉMO — sans valeur fiscale';

    protected function setUp(): void
    {
        parent::setUp();
        // Its own client address, so no other test's entries count toward this one's budget.
        $this->client->setServerParameters(['REMOTE_ADDR' => '198.51.100.'.random_int(1, 254)]);
    }

    public function testEveryDocumentOfADemoCarriesTheBandAndFacturXIsRefused(): void
    {
        $company = $this->builtAndEntered();
        $renderer = static::getContainer()->get(FakePdfRenderer::class);
        self::assertNotSame([], $renderer->rendered, 'the build issued invoices and stored their PDFs');
        foreach ($renderer->rendered as $page) {
            self::assertTrue(self::banded($page), 'every page the build printed carries the band');
        }

        foreach ($this->documentPaths($company) as $what => $path) {
            $this->client->request('GET', $path);
            self::assertResponseIsSuccessful($what);
            self::assertTrue(self::banded((string) $this->client->getInternalResponse()->getContent()), "the $what carries the band, top and bottom");
        }

        foreach (['xml', 'pdf'] as $format) {
            $this->client->request('GET', \sprintf('/api/companies/%s/invoices/%s/factur-x.%s', $company, $this->issuedInvoice($company), $format));
            self::assertResponseStatusCodeSame(403);
            self::assertSame(['error' => DemoAccountGuard::REFUSED], $this->json(), "Factur-X $format is not available in the demo");
        }
    }

    public function testTheSameCompanyOnceNoSandboxPrintsWithoutTheBand(): void
    {
        $company = $this->builtAndEntered();
        $this->em()->getConnection()->executeStatement('DELETE FROM demo_sandbox WHERE company_id = ?', [$company]);

        $this->client->request('GET', $this->documentPaths($company)['quote']);

        self::assertResponseIsSuccessful();
        $page = (string) $this->client->getInternalResponse()->getContent();
        self::assertStringNotContainsString('data-pdf=', $page);
        self::assertStringNotContainsString(self::BAND, $page);
    }

    /** A Tunisian sandbox built as the worker builds it, and the visitor signed in to it. */
    private function builtAndEntered(): string
    {
        static::getContainer()->get(SeedPlatform::class)->converge();
        $sandbox = new DemoSandbox(Uuid::v7(), 'TN', new \DateTimeImmutable());
        static::getContainer()->get(DemoSandboxRepository::class)->save($sandbox);
        static::getContainer()->get(BuildSandbox::class)->handle($sandbox->getId());

        $this->postJson('/api/auth/demo', ['country' => 'TN']);
        self::assertResponseIsSuccessful();
        $company = $this->row('SELECT company_id FROM demo_sandbox WHERE id = ?', [$sandbox->getId()->toRfc4122()])['company_id'];

        return \is_string($company) ? $company : self::fail('A built sandbox has a company.');
    }

    /** @return array<string, string> each kind of printed document, by where it is printed */
    private function documentPaths(string $company): array
    {
        $first = fn (string $sql): string => \is_string($id = $this->em()->getConnection()->fetchOne($sql, [$company])) ? $id : self::fail("Nothing for: $sql");

        return [
            'invoice' => \sprintf('/api/companies/%s/invoices/%s/pdf', $company, $this->issuedInvoice($company)),
            'draft invoice' => \sprintf('/api/companies/%s/invoices/%s/pdf', $company, $first("SELECT id FROM invoice WHERE company_id = ? AND status = 'draft' LIMIT 1")),
            'quote' => \sprintf('/api/companies/%s/quotes/%s/pdf', $company, $first('SELECT id FROM quote WHERE company_id = ? LIMIT 1')),
            'delivery note' => \sprintf('/api/companies/%s/delivery-notes/%s/pdf', $company, $first('SELECT id FROM delivery_note WHERE company_id = ? LIMIT 1')),
            'statement' => \sprintf('/api/companies/%s/customers/%s/statement/pdf', $company, $first('SELECT customer_id FROM invoice WHERE company_id = ? AND customer_id IS NOT NULL LIMIT 1')),
        ];
    }

    private function issuedInvoice(string $company): string
    {
        $id = $this->em()->getConnection()->fetchOne("SELECT id FROM invoice WHERE company_id = ? AND status NOT IN ('draft', 'cancelled') LIMIT 1", [$company]);

        return \is_string($id) ? $id : self::fail('The build issued an invoice.');
    }

    /**
     * Whether the page carries the band as its own header and footer, each saying it in words: in French, or in English
     * for a document printed in its customer's English.
     */
    private static function banded(string $page): bool
    {
        $translator = static::getContainer()->get(TranslatorInterface::class);
        self::assertSame(self::BAND, $translator->trans('demo.band', [], 'pdf', 'fr'));
        $words = implode('|', array_map(static fn (string $locale): string => preg_quote($translator->trans('demo.band', [], 'pdf', $locale), '#'), ['fr', 'en']));

        return 1 === preg_match('#<template data-pdf="header">.*?('.$words.').*?</template>#s', $page)
            && 1 === preg_match('#<template data-pdf="footer">.*?('.$words.').*?</template>#s', $page);
    }
}
