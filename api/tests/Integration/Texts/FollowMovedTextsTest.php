<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Integration\Texts;

use App\Shared\Infrastructure\Doctrine\Rows;
use App\Tenancy\Domain\Model\Company;
use App\Texts\Application\Catalogue\TextCatalogue;
use App\Texts\Application\Catalogue\TextCatalogueQuery;
use App\Texts\Application\FollowMovedTexts;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * A screen text a release moves into a page's own file keeps its wording: the wording is stored under the file that
 * shipped the text when it was written, and every reader matches that file, so without this the company's words were
 * neither shown nor listed in « Textes », the shipped word back on screen, unsaid. A screen key lives in one file only,
 * so the key names where its wording now goes. Nothing is ever deleted: a wording that cannot follow stays where it is.
 */
final class FollowMovedTextsTest extends KernelTestCase
{
    private const string MOVED = 'products.labels.title';

    public function testAWordingLeftUnderTheFileItsTextLeftIsReadAndListedAgainOnceItFollows(): void
    {
        $company = $this->company('Étiquettes');
        $this->stored($company, 'screen', self::MOVED, 'Étiquettes de la boutique', '2026-10-25 09:00:00');

        self::assertSame(0, $this->listed($company, 'Étiquettes de la boutique')['total'], 'the defect: the wording is kept but nobody reads it');

        self::assertSame(1, $this->follow()->follow());

        $listed = $this->listed($company, 'Étiquettes de la boutique');
        self::assertSame(1, $listed['total']);
        self::assertSame('screen:labels', $listed['items'][0]->domain);
        self::assertSame(self::MOVED, $listed['items'][0]->key);
        self::assertSame('Étiquettes de la boutique', $listed['items'][0]->company);
        self::assertSame(0, $this->follow()->follow(), 'a second start moves nothing');
    }

    public function testAWordingNeverReplacesOneAlreadyAtItsPlaceNorMovesWhereNoScreenFileShipsItsText(): void
    {
        $company = $this->company('Restes');
        // Worded again at its new place after the move: the newer one stays in force, the older one where it was.
        $this->stored($company, 'screen', 'products.labels.copies', 'Copies, avant', '2026-10-25 09:00:00');
        $this->stored($company, 'screen:labels', 'products.labels.copies', 'Copies, après', '2026-11-01 09:00:00');
        // Left twice by two moves: the one written last follows.
        $this->stored($company, 'screen', 'products.labels.back', 'Retour, premier', '2026-10-25 09:00:00');
        $this->stored($company, 'screen:settings', 'products.labels.back', 'Retour, dernier', '2026-11-01 09:00:00');
        // Shipped nowhere any more, and an API text: neither has a screen file to go to.
        $this->stored($company, 'screen', 'products.labels.nowhere', 'Disparu', '2026-10-25 09:00:00');
        $this->stored($company, 'pdf', 'products.labels.title', 'Pas un écran', '2026-10-25 09:00:00');
        // The installation's own wording follows as a company's does.
        $this->stored(null, 'screen', self::MOVED, 'Pour toute l’installation', '2026-10-25 09:00:00');

        self::assertSame(2, $this->follow()->follow());

        self::assertSame(
            [
                ['company' => 'Restes', 'domain' => 'pdf', 'text_key' => 'products.labels.title', 'value' => 'Pas un écran'],
                ['company' => 'Restes', 'domain' => 'screen', 'text_key' => 'products.labels.back', 'value' => 'Retour, premier'],
                ['company' => 'Restes', 'domain' => 'screen', 'text_key' => 'products.labels.copies', 'value' => 'Copies, avant'],
                ['company' => 'Restes', 'domain' => 'screen', 'text_key' => 'products.labels.nowhere', 'value' => 'Disparu'],
                ['company' => 'Restes', 'domain' => 'screen:labels', 'text_key' => 'products.labels.back', 'value' => 'Retour, dernier'],
                ['company' => 'Restes', 'domain' => 'screen:labels', 'text_key' => 'products.labels.copies', 'value' => 'Copies, après'],
                ['company' => '', 'domain' => 'screen:labels', 'text_key' => self::MOVED, 'value' => 'Pour toute l’installation'],
            ],
            array_map(
                static fn (array $row): array => array_map(static fn (mixed $value): string => Rows::text($value ?? ''), $row),
                $this->db()->fetchAllAssociative('SELECT c.name AS company, t.domain, t.text_key, t.value FROM text_override t LEFT JOIN company c ON c.id = t.company_id ORDER BY c.name NULLS LAST, t.domain, t.text_key'),
            ),
        );
    }

    /** @return array{items: list<\App\Texts\Application\Catalogue\TextCatalogueEntry>, total: int} */
    private function listed(Company $company, string $search): array
    {
        return static::getContainer()->get(TextCatalogue::class)->page($company, 'fr', new TextCatalogueQuery($search, null, false, false, 1, 25));
    }

    private function stored(?Company $company, string $domain, string $key, string $value, string $at): void
    {
        $this->db()->insert('text_override', [
            'id' => Uuid::v7()->toRfc4122(),
            'company_id' => $company?->getId()->toRfc4122(),
            'language' => 'fr',
            'domain' => $domain,
            'text_key' => $key,
            'value' => $value,
            'original' => 'Original',
            'updated_at' => $at,
            'version' => 1,
        ]);
    }

    private function company(string $name): Company
    {
        self::bootKernel();
        $company = new Company($name, 'TN', 'TND', 'fr', 'Africa/Tunis', new \DateTimeImmutable());
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($company);
        $entityManager->flush();

        return $company;
    }

    private function db(): Connection
    {
        return static::getContainer()->get(EntityManagerInterface::class)->getConnection();
    }

    private function follow(): FollowMovedTexts
    {
        return static::getContainer()->get(FollowMovedTexts::class);
    }
}
