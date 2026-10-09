<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Fiscal\Application\Company\ProvisionCompany;
use App\Fiscal\Domain\UnitRepository;
use App\Module\Products\Domain\Product;
use App\Module\Products\Domain\ProductDetails;
use App\Module\Products\Domain\ProductKind;
use App\ModuleRegistry\Domain\ModuleState;
use App\Settings\Domain\Setting;
use App\Settings\Domain\SettingAddress;
use App\Tenancy\Domain\Company;
use App\Tests\Support\MakesPictures;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

/**
 * The files a loss keeps: the photo of the broken goods, the complaint filed for a theft. What a reader sees of them and
 * what only a writer may change, on a loss alone and in its own company.
 */
final class StockLossAttachmentsTest extends ApiTestCase
{
    use MakesPictures;

    private const string PDF = "%PDF-1.4\n%âãÏÓ\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF";

    private Company $company;
    private string $productId = '';

    protected function setUp(): void
    {
        parent::setUp();
        [$this->company, $this->productId] = $this->companyKeepingStock('Acme');
    }

    public function testAWriterAttachesAPhotoToALossAndAReaderSeesAndOpensIt(): void
    {
        $this->signedIn('writer@twes.local', ['stock.read', 'stock.write']);
        [$receipt, $loss] = $this->receivedThenLost();

        $this->uploadFile($this->path($loss), 'carton-écrasé.jpg', self::jpeg(8, 8));

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $attachment = $this->stringAt($this->json(), 'id');
        self::assertSame(['carton-écrasé.jpg', 'image/jpeg'], [$this->json()['name'], $this->json()['mime']]);

        $this->signedIn('reader@twes.local', ['stock.read']);
        $this->getJson($this->movementsPath());
        $counts = [];
        foreach ($this->jsonList() as $row) {
            $counts[$this->stringAt($row, 'id')] = $row['attachmentCount'] ?? null;
        }
        self::assertSame([$loss => 1, $receipt => null], $counts, 'counted on the loss, absent from any other movement');
        $this->getJson($this->path($loss));
        self::assertSame([$attachment], array_column($this->jsonList(), 'id'));
        $this->client->request('GET', $this->path($loss).'/'.$attachment.'/content');
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'image/jpeg');
        self::assertStringContainsString('sandbox', (string) $this->client->getResponse()->headers->get('Content-Security-Policy'));
        self::assertSame(self::jpeg(8, 8), $this->client->getResponse()->getContent());

        self::assertSame([['stock_movement.attachment_added', $loss, ['attachmentId' => $attachment]]], $this->audited(), 'the attachment id alone, never the file');
    }

    public function testAFileAttachedByMistakeComesOff(): void
    {
        $this->signedIn('writer@twes.local', ['stock.read', 'stock.write']);
        [, $loss] = $this->receivedThenLost();
        $this->uploadFile($this->path($loss), 'plainte.pdf', self::PDF);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED, 'a complaint filed for a theft is kept as well');
        $attachment = $this->stringAt($this->json(), 'id');

        $this->sendJson('DELETE', $this->path($loss).'/'.$attachment);

        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
        $this->getJson($this->path($loss));
        self::assertSame([], $this->jsonList());
        $this->getJson($this->movementsPath().'?sourceType=loss');
        self::assertSame([0], array_column($this->jsonList(), 'attachmentCount'));
        self::assertSame(['stock_movement.attachment_added', 'stock_movement.attachment_removed'], array_column($this->audited(), 0));
        $this->sendJson('DELETE', $this->path($loss).'/'.$attachment);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testOnlyALossOfTheCompanyKeepsFiles(): void
    {
        [$other, $otherProduct] = $this->companyKeepingStock('Ailleurs');
        $this->signedIn('elsewhere@twes.local', ['stock.read', 'stock.write'], $other);
        [, $strangerLoss] = $this->receivedThenLost($other, $otherProduct);

        $this->signedIn('writer@twes.local', ['stock.read', 'stock.write']);
        [$receipt, $loss] = $this->receivedThenLost();
        foreach (['another company’s loss' => $strangerLoss, 'a receipt' => $receipt, 'no movement' => Uuid::v7()->toRfc4122()] as $what => $movement) {
            $this->uploadFile($this->path($movement), 'photo.jpg', self::jpeg(4, 4));
            self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND, $what);
            $this->getJson($this->path($movement));
            self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND, $what);
        }
        $this->sendJson('DELETE', $this->path($loss).'/'.Uuid::v7()->toRfc4122());
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        self::assertSame([], $this->audited());
    }

    public function testAReaderChangesNothingAndASwitchedOffStockShowsNothing(): void
    {
        $this->signedIn('writer@twes.local', ['stock.read', 'stock.write']);
        [, $loss] = $this->receivedThenLost();
        $this->uploadFile($this->path($loss), 'photo.jpg', self::jpeg(4, 4));
        $attachment = $this->stringAt($this->json(), 'id');

        $this->signedIn('reader@twes.local', ['stock.read']);
        $this->uploadFile($this->path($loss), 'photo.jpg', self::jpeg(4, 4));
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND, 'attaching is a writer’s');
        $this->sendJson('DELETE', $this->path($loss).'/'.$attachment);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND, 'and so is removing');

        $this->em()->persist(ModuleState::of($this->em()->find(Company::class, $this->company->getId()) ?? self::fail('The company is gone.'), 'inventory', false, new \DateTimeImmutable()));
        $this->em()->flush();
        $this->getJson($this->path($loss));
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        $this->client->request('GET', $this->path($loss).'/'.$attachment.'/content');
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testAFileTheLossCannotKeepIsRefusedForTheFile(): void
    {
        $this->signedIn('writer@twes.local', ['stock.read', 'stock.write']);
        [, $loss] = $this->receivedThenLost();

        foreach (['empty' => '', 'a GIF' => self::gif()] as $what => $contents) {
            $this->uploadFile($this->path($loss), 'photo.gif', $contents);
            self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, $what);
            self::assertStringContainsString('file', (string) $this->client->getResponse()->getContent(), $what);
        }
        for ($i = 0; $i < 10; ++$i) {
            $this->uploadFile($this->path($loss), \sprintf('photo-%d.jpg', $i), self::jpeg(4, 4));
            self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        }
        $this->uploadFile($this->path($loss), 'photo-11.jpg', self::jpeg(4, 4));
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, 'ten files at most');
    }

    /**
     * Ten goods received at the company's default place, then three of them broken.
     *
     * @return array{string, string} the receipt's id and the loss's
     */
    private function receivedThenLost(?Company $company = null, ?string $productId = null): array
    {
        $company ??= $this->company;
        $productId ??= $this->productId;
        $movements = '/api/companies/'.$company->getId()->toRfc4122().'/stock-movements';
        $this->getJson('/api/companies/'.$company->getId()->toRfc4122().'/stock-locations');
        self::assertResponseIsSuccessful();
        $site = $this->stringAt($this->jsonList()[0], 'id');

        $this->postJson($movements, ['operation' => 'receive', 'productId' => $productId, 'locationId' => $site, 'quantity' => '10']);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $receipt = $this->stringAt($this->json(), 'id');
        $this->postJson($movements, ['operation' => 'loss', 'productId' => $productId, 'locationId' => $site, 'quantity' => '3', 'reason' => 'broken', 'note' => null]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        self::assertSame(0, $this->json()['attachmentCount'] ?? null, 'a loss just declared has nothing attached yet');

        return [$receipt, $this->stringAt($this->json(), 'id')];
    }

    /** @return array{Company, string} a company keeping stock of its goods, and one of them */
    private function companyKeepingStock(string $name): array
    {
        $company = $this->createCompany($name);
        static::getContainer()->get(ProvisionCompany::class)->handle($company);
        $piece = static::getContainer()->get(UnitRepository::class)->ofCodeInCompany('C62', $company->getId()) ?? self::fail('No piece unit.');
        $now = new \DateTimeImmutable();
        $product = Product::create($company, 'ART-001', new ProductDetails('Portable 14"', null, ProductKind::Goods, '1250'), $piece, null, [], $now);
        $this->em()->persist($product);
        $this->em()->persist(new Setting(SettingAddress::company($company), 'article.stock_tracking', true, $now));
        $this->em()->flush();

        return [$company, $product->getId()->toRfc4122()];
    }

    /** @return list<array{string, string, array<mixed>}> each audit row of a movement: action, movement, changes */
    private function audited(): array
    {
        $rows = $this->em()->getConnection()->fetchAllAssociative("SELECT action, entity_id, changes FROM audit_log WHERE entity_type = 'stock_movement' ORDER BY at, action");

        return array_map(static function (array $row): array {
            self::assertIsString($row['action']);
            self::assertIsString($row['entity_id']);
            self::assertIsString($row['changes']);
            $changes = json_decode($row['changes'], true, flags: \JSON_THROW_ON_ERROR);
            self::assertIsArray($changes);

            return [$row['action'], $row['entity_id'], $changes];
        }, $rows);
    }

    private function path(string $movementId): string
    {
        return $this->movementsPath().'/'.$movementId.'/attachments';
    }

    private function movementsPath(): string
    {
        return '/api/companies/'.$this->company->getId()->toRfc4122().'/stock-movements';
    }

    /** @param list<string> $permissions */
    private function signedIn(string $email, array $permissions, ?Company $company = null): void
    {
        // Found again: a request reboots the kernel, and the company held from before belongs to its old entity manager.
        $company = $this->em()->find(Company::class, ($company ?? $this->company)->getId()) ?? self::fail('The company is gone.');
        // A role of its own, named after the person: a company names each of its roles once.
        $this->createUser($email, 'password-1234', $company, $permissions, strtok($email, '@') ?: $email);
        $this->login($email, 'password-1234');
        self::assertResponseIsSuccessful();
    }
}
