<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Integration\Texts;

use App\Tenancy\Domain\Model\Company;
use App\Texts\Application\ShippedTexts;
use App\Texts\Application\TextsInForce;
use App\Texts\Domain\Model\TextOverride;
use App\Texts\Domain\ValueObject\TextAddress;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Contracts\Service\ResetInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

/**
 * What the API writes itself, a mail or a printed document, in the wording of the company it is written for, else the
 * installation's, else the release's; its parameters filled in as the shipped text's are. A locked or fiscal text is
 * always the release's, whatever a row says.
 */
final class TextsInForceTest extends KernelTestCase
{
    public function testAPrintedLabelTakesTheCompanysWordingThenTheInstallationsThenTheShippedOne(): void
    {
        $company = $this->company();
        $this->word(null, new TextAddress('fr', 'pdf', 'invoice.title'), 'Facture de vente');
        $this->word(null, new TextAddress('fr', 'pdf', 'quote.title'), 'Proposition');
        $this->word($company, new TextAddress('fr', 'pdf', 'invoice.title'), 'Facture client');

        $twig = static::getContainer()->get(Environment::class)->createTemplate("{{ 'invoice.title'|trans({}, 'pdf', 'fr') }}|{{ 'quote.title'|trans({}, 'pdf', 'fr') }}|{{ 'invoice.due_date'|trans({}, 'pdf', 'fr') }}");
        $inForce = static::getContainer()->get(TextsInForce::class);

        self::assertSame('Facture client|Proposition|'.$this->shipped('invoice.due_date'), $inForce->for($company->getId(), static fn (): string => $twig->render()));
        self::assertSame('Facture de vente|Proposition|'.$this->shipped('invoice.due_date'), $twig->render(), 'written for no company: the installation\'s');
        self::assertSame('Facture de vente|Proposition|'.$this->shipped('invoice.due_date'), $inForce->for(null, static fn (): string => $twig->render()), 'named as the installation');
    }

    public function testAMailsWordingKeepsItsParametersFilledIn(): void
    {
        $company = $this->company();
        $this->word($company, new TextAddress('fr', 'emails', 'invitation.subject'), '%company% vous attend sur %product%');
        $translator = static::getContainer()->get(TranslatorInterface::class);

        self::assertSame('Acme vous attend sur twes', static::getContainer()->get(TextsInForce::class)->for(
            $company->getId(),
            static fn (): string => $translator->trans('invitation.subject', ['%company%' => 'Acme', '%product%' => 'twes'], 'emails', 'fr'),
        ));
    }

    public function testALockedOrFiscalTextIsTheReleasesWhateverARowSays(): void
    {
        $company = $this->company();
        // Rows no write would accept: a label locked since, a fiscal text.
        $this->word($company, new TextAddress('fr', 'pdf', 'totals.total'), 'Rien');
        $this->word($company, new TextAddress('fr', 'fiscal', 'fiscal.identifier.tn.matricule_fiscal'), 'Rien');
        $translator = static::getContainer()->get(TranslatorInterface::class);
        $inForce = static::getContainer()->get(TextsInForce::class);

        self::assertSame($this->shipped('totals.total'), $inForce->for($company->getId(), static fn (): string => $translator->trans('totals.total', [], 'pdf', 'fr')));
        self::assertSame('Matricule fiscal', $inForce->for($company->getId(), static fn (): string => $translator->trans('fiscal.identifier.tn.matricule_fiscal', [], 'fiscal', 'fr')));
    }

    public function testAWordingReadOnceIsReadAgainAfterAReset(): void
    {
        $company = $this->company();
        $this->word($company, new TextAddress('en', 'pdf', 'invoice.title'), 'Sales invoice');
        $translator = static::getContainer()->get(TranslatorInterface::class);
        $inForce = static::getContainer()->get(TextsInForce::class);
        $title = static fn (): string => $translator->trans('invoice.title', [], 'pdf', 'en');

        self::assertSame('Sales invoice', $inForce->for($company->getId(), $title));
        $this->db("UPDATE text_override SET value = 'Our invoice' WHERE company_id = ?", $company);
        self::assertSame('Sales invoice', $inForce->for($company->getId(), $title), 'read once a request');

        // What the worker does between two requests: the entity manager forgets its rows, every service resettable resets.
        static::getContainer()->get(EntityManagerInterface::class)->clear();
        self::assertInstanceOf(ResetInterface::class, $inForce);
        $inForce->reset();
        self::assertSame('Our invoice', $inForce->for($company->getId(), $title));
    }

    private function company(): Company
    {
        self::bootKernel();
        $company = new Company('Acme', 'TN', 'TND', 'fr', 'Africa/Tunis');
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->persist($company);
        $em->flush();

        return $company;
    }

    private function word(?Company $company, TextAddress $address, string $value): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->persist(new TextOverride($company, $address, $value, 'shipped', new \DateTimeImmutable()));
        $em->flush();
    }

    private function db(string $sql, Company $company): void
    {
        static::getContainer()->get(EntityManagerInterface::class)->getConnection()->executeStatement($sql, [$company->getId()->toRfc4122()]);
    }

    private function shipped(string $key): string
    {
        $text = static::getContainer()->get(ShippedTexts::class)->original(new TextAddress('fr', 'pdf', $key));
        self::assertNotNull($text);

        return $text;
    }
}
