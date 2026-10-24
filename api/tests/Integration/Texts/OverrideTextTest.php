<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Integration\Texts;

use App\Shared\Application\RecordChanged;
use App\Shared\Infrastructure\Doctrine\Rows;
use App\Tenancy\Domain\Model\Company;
use App\Texts\Application\OverrideText;
use App\Texts\Application\ShippedTexts;
use App\Texts\Application\TextOverrideRefused;
use App\Texts\Domain\Model\TextOverride;
use App\Texts\Domain\Repository\TextOverrideRepository;
use App\Texts\Domain\ValueObject\TextAddress;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A text changed for the whole installation or for one company: stored at its level beside the original it replaced,
 * refused with its reason when it names no text, a locked one, a language the screens do not speak, or when it would
 * lose what the code fills in; written over the version it read, taken back by a reset, both audited by key alone.
 */
final class OverrideTextTest extends KernelTestCase
{
    private const string PASSKEY = 'auth.mfa.passkey_elsewhere';

    public function testTheInstallationsTextAndACompanysAreEachKeptAtTheirLevelBesideTheOriginal(): void
    {
        $company = $this->company('Textes');
        $address = new TextAddress('fr', 'screen', self::PASSKEY);
        $original = (string) $this->shipped()->original($address);

        $this->overrideText()->override(null, $address, 'Pour l’installation, à {{address}}.', 0, null);
        $this->overrideText()->override($company, $address, 'Pour la boutique, à {{address}}.', 0, null);

        self::assertEquals(
            [
                ['company_id' => null, 'value' => 'Pour l’installation, à {{address}}.', 'original' => $original, 'version' => 1],
                ['company_id' => $company->getId()->toRfc4122(), 'value' => 'Pour la boutique, à {{address}}.', 'original' => $original, 'version' => 1],
            ],
            $this->db()->fetchAllAssociative('SELECT company_id, value, original, version FROM text_override WHERE text_key = ? ORDER BY company_id NULLS FIRST', [self::PASSKEY]),
        );
        $audited = $this->db()->fetchAllAssociative("SELECT action, changes::text AS changes FROM audit_log WHERE entity_type = 'text' AND company_id IS NOT DISTINCT FROM ? ORDER BY at", [$company->getId()->toRfc4122()]);
        self::assertCount(1, $audited);
        self::assertSame('text.overridden', $audited[0]['action']);
        self::assertStringContainsString(self::PASSKEY, Rows::text($audited[0]['changes']));
        self::assertStringNotContainsString('Pour la boutique', Rows::text($audited[0]['changes']), 'the key is recorded, never the words');
    }

    /** @return iterable<string, array{TextAddress, string, string}> */
    public static function refusals(): iterable
    {
        yield 'no such key' => [new TextAddress('fr', 'screen', 'auth.mfa.nowhere'), 'Texte', 'unknown_text'];
        yield 'a group of texts' => [new TextAddress('fr', 'screen', 'auth.mfa'), 'Texte', 'unknown_text'];
        yield 'no such file' => [new TextAddress('fr', 'messages', 'anything'), 'Texte', 'unknown_text'];
        yield 'a language the screens do not speak' => [new TextAddress('de', 'pdf', 'invoice.title'), 'Rechnung', 'language'];
        yield 'a fiscal text' => [new TextAddress('fr', 'fiscal', 'fiscal.identifier.tn.matricule_fiscal'), 'Texte', 'locked'];
        yield 'a printed total' => [new TextAddress('fr', 'pdf', 'totals.total'), 'Montant', 'locked'];
        yield 'nothing written' => [new TextAddress('fr', 'pdf', 'invoice.title'), "  \u{00A0}", 'empty'];
        yield 'a filled-in name lost' => [new TextAddress('fr', 'screen', self::PASSKEY), 'Utilisez le code.', 'placeholders'];
        yield 'a plural broken' => [new TextAddress('fr', 'screen', 'company.unread'), '{{count}} {count, plural, one {non lue}}', 'plural'];
        yield 'too long' => [new TextAddress('fr', 'pdf', 'invoice.title'), str_repeat('x', 2001), 'too_long'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('refusals')]
    public function testEachRefusalSaysWhyAndKeepsNothing(TextAddress $address, string $value, string $reason): void
    {
        $company = $this->company('Refus');
        try {
            $this->overrideText()->override($company, $address, $value, 0, null);
            self::fail('stored a text it should refuse as '.$reason);
        } catch (TextOverrideRefused $refused) {
            self::assertSame($reason, $refused->reason);
        }
        self::assertSame(0, Rows::int($this->db()->fetchOne('SELECT count(*) FROM text_override WHERE company_id = ?', [$company->getId()->toRfc4122()])));
    }

    public function testATextIsWrittenOverTheVersionItReadAndAChangeMadeSinceIsRefused(): void
    {
        $company = $this->company('Versions');
        $address = new TextAddress('fr', 'pdf', 'invoice.title');

        $this->overrideText()->override($company, $address, 'Facture de vente', 0, null);
        $this->overrideText()->override($company, $address, 'Facture client', 1, null);
        self::assertSame(['value' => 'Facture client', 'version' => 2], $this->row($company));

        try {
            $this->overrideText()->override($company, $address, 'Facture de service', 1, null);
            self::fail('wrote over a change it never read');
        } catch (RecordChanged) {
        }
        try {
            $this->overrideText()->override($company, $address, 'Facture de service', 0, null);
            self::fail('wrote a first text over one already there');
        } catch (RecordChanged) {
        }
        self::assertSame(['value' => 'Facture client', 'version' => 2], $this->row($company));
    }

    /**
     * Two first texts for the installation both read no row, and nothing can hold a row that is not there yet: the key,
     * whose NULL company counts as one value, decides, and the second reads as a save made since.
     */
    public function testTheInstallationHoldsOneTextPerAddressEvenWhenTwoFirstTextsMeet(): void
    {
        self::bootKernel();
        $overrides = static::getContainer()->get(TextOverrideRepository::class);
        $address = new TextAddress('ar', 'pdf', 'invoice.title');

        $overrides->save(new TextOverride(null, $address, 'فاتورة بيع', 'فاتورة', new \DateTimeImmutable()));
        $this->expectException(RecordChanged::class);
        $overrides->save(new TextOverride(null, $address, 'فاتورة', 'فاتورة', new \DateTimeImmutable()));
    }

    public function testAResetPutsTheOriginalBackForThatLevelAloneAndIsAudited(): void
    {
        $company = $this->company('Rétablir');
        $address = new TextAddress('en', 'pdf', 'invoice.title');
        $this->overrideText()->override(null, $address, 'Sales invoice', 0, null);
        $this->overrideText()->override($company, $address, 'Our invoice', 0, null);

        self::assertTrue($this->overrideText()->reset($company, $address, null));
        self::assertFalse($this->overrideText()->reset($company, $address, null), 'nothing left to take back');

        self::assertNull($this->row($company));
        self::assertSame('Sales invoice', $this->db()->fetchOne("SELECT value FROM text_override WHERE company_id IS NULL AND language = 'en' AND domain = 'pdf' AND text_key = 'invoice.title'"));
        self::assertSame(
            ['text.overridden', 'text.reset'],
            $this->db()->fetchFirstColumn("SELECT action FROM audit_log WHERE entity_type = 'text' AND company_id = ? ORDER BY action", [$company->getId()->toRfc4122()]),
        );
    }

    /**
     * Read again on every call: the row changes between two.
     *
     * @return array{value: string, version: int}|null
     *
     * @phpstan-impure
     */
    private function row(Company $company): ?array
    {
        $row = $this->db()->fetchAssociative('SELECT value, version FROM text_override WHERE company_id = ?', [$company->getId()->toRfc4122()]);

        return false === $row ? null : ['value' => Rows::text($row['value']), 'version' => Rows::int($row['version'])];
    }

    private function company(string $name): Company
    {
        self::bootKernel();
        $company = new Company($name, 'TN', 'TND', 'fr', 'Africa/Tunis');
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($company);
        $entityManager->flush();

        return $company;
    }

    private function db(): Connection
    {
        return static::getContainer()->get(EntityManagerInterface::class)->getConnection();
    }

    private function shipped(): ShippedTexts
    {
        return static::getContainer()->get(ShippedTexts::class);
    }

    private function overrideText(): OverrideText
    {
        return static::getContainer()->get(OverrideText::class);
    }
}
