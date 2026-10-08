<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Files\Application\Files;
use App\Fiscal\Application\Company\ProvisionCompany;
use App\Fiscal\Domain\UnitRepository;
use App\Tenancy\Domain\Company;
use App\Tests\Support\MakesPictures;
use Symfony\Component\HttpFoundation\Response;

/**
 * docs/SPEC.md § 7, 2026-10-08 23:02: a product has a gallery of photos with one marked main, put in order, each
 * kept as sent with two copies the server cuts; every limit is a parameter, and the photos count in what the company
 * keeps.
 */
final class ProductPhotosTest extends ApiTestCase
{
    use MakesPictures;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = $this->createCompany('Acme');
        static::getContainer()->get(ProvisionCompany::class)->handle($this->company);
    }

    public function testTheFirstPhotoIsMainAndTheProductSaysWhichItIs(): void
    {
        $this->signedIn(['product.read', 'product.write']);
        $product = $this->aProduct();

        $first = $this->added($product, self::jpeg(1200, 800));
        $second = $this->added($product, self::jpeg(600, 600));

        $this->getJson($this->photos($product));
        self::assertResponseIsSuccessful();
        $gallery = $this->jsonList();
        self::assertSame([$first, $second], array_map(fn (array $row): string => $this->stringAt($row, 'id'), $gallery));
        self::assertSame([true, false], array_map(fn (array $row): bool => $this->boolAt($row, 'main'), $gallery));
        self::assertSame([1200, 800, 'image/jpeg'], [$gallery[0]['width'], $gallery[0]['height'], $gallery[0]['mime']]);

        $this->getJson($this->products().'/'.$product);
        self::assertSame($first, $this->stringAt($this->json(), 'mainPhotoId'));
        $this->getJson($this->products());
        self::assertSame($first, $this->stringAt($this->jsonList()[0], 'mainPhotoId'), 'the list names each row\'s main photo');
    }

    public function testTheProductOptionsSayTheLimitsBeforeAPhotoIsSent(): void
    {
        $this->signedIn(['product.read']);

        $this->getJson('/api/companies/'.$this->company->getId()->toRfc4122().'/product-options');

        self::assertResponseIsSuccessful();
        self::assertSame([6, 5242880], [$this->json()['photosPerProduct'] ?? null, $this->json()['photoMaxBytes'] ?? null]);
    }

    public function testAProductWithoutPhotosSaysNone(): void
    {
        $this->signedIn(['product.read', 'product.write']);
        $product = $this->aProduct();

        $this->getJson($this->products().'/'.$product);

        self::assertNull($this->json()['mainPhotoId'] ?? null);
    }

    public function testTheServerCutsTwoCopiesAndKeepsTheOriginalAsSent(): void
    {
        $this->signedIn(['product.read', 'product.write']);
        $product = $this->aProduct();
        $sent = self::jpeg(1200, 800);
        $photo = $this->added($product, $sent);

        $this->getJson($this->photos($product)."/$photo/content?size=small");
        self::assertResponseIsSuccessful();
        self::assertSame('image/webp', $this->client->getResponse()->headers->get('Content-Type'));
        self::assertSame([160, 107], self::sizeOf((string) $this->client->getResponse()->getContent()));
        self::assertSame('nosniff', $this->client->getResponse()->headers->get('X-Content-Type-Options'));
        self::assertStringContainsString('immutable', (string) $this->client->getResponse()->headers->get('Cache-Control'));
        self::assertStringContainsString('private', (string) $this->client->getResponse()->headers->get('Cache-Control'));

        $this->getJson($this->photos($product)."/$photo/content?size=large");
        self::assertSame([960, 640], self::sizeOf((string) $this->client->getResponse()->getContent()));

        $this->getJson($this->photos($product)."/$photo/content?size=original");
        self::assertSame('image/jpeg', $this->client->getResponse()->headers->get('Content-Type'));
        self::assertSame($sent, $this->client->getResponse()->getContent());

        $this->getJson($this->photos($product)."/$photo/content?size=huge");
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testAPhoneHeldSidewaysGivesAnUprightPhoto(): void
    {
        $this->signedIn(['product.read', 'product.write']);
        $product = $this->aProduct();
        $photo = $this->added($product, self::jpeg(400, 200, orientation: 6));

        $this->getJson($this->photos($product));
        self::assertSame([200, 400], [$this->jsonList()[0]['width'], $this->jsonList()[0]['height']]);
        $this->getJson($this->photos($product)."/$photo/content?size=small");
        self::assertSame([80, 160], self::sizeOf((string) $this->client->getResponse()->getContent()));
    }

    public function testMarkingAnotherPhotoMainMovesNothing(): void
    {
        $this->signedIn(['product.read', 'product.write']);
        $product = $this->aProduct();
        $first = $this->added($product, self::jpeg(40, 40));
        $second = $this->added($product, self::jpeg(40, 40));

        $this->postJson($this->photos($product)."/$second/main", null);
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);

        $this->getJson($this->photos($product));
        self::assertSame([$first => false, $second => true], $this->mainByPhoto());
        $this->getJson($this->products().'/'.$product);
        self::assertSame($second, $this->stringAt($this->json(), 'mainPhotoId'));
    }

    public function testPhotosArePutInOrderAndAnOrderNamingOtherPhotosIsRefused(): void
    {
        $this->signedIn(['product.read', 'product.write']);
        $product = $this->aProduct();
        $first = $this->added($product, self::jpeg(40, 40));
        $second = $this->added($product, self::jpeg(40, 40));
        $third = $this->added($product, self::jpeg(40, 40));

        $this->postJson($this->photos($product).'/order', ['photoIds' => [$third, $first, $second]]);
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
        $this->getJson($this->photos($product));
        self::assertSame([$third => false, $first => true, $second => false], $this->mainByPhoto(), 'the order changed and the main photo did not');

        foreach ([
            'one left out' => [$third, $first],
            'one twice' => [$third, $first, $first],
            'one that is not there' => [$third, $first, $second, '0192f3b4-1c2d-7e8f-9a0b-1c2d3e4f5a6b'],
        ] as $why => $ids) {
            $this->postJson($this->photos($product).'/order', ['photoIds' => $ids]);
            self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT, $why);
        }
    }

    public function testRemovingTheMainPhotoMakesTheNextMainAndPuttingItBackUndoesIt(): void
    {
        $this->signedIn(['product.read', 'product.write']);
        $product = $this->aProduct();
        $first = $this->added($product, self::jpeg(40, 40));
        $second = $this->added($product, self::jpeg(40, 40));

        $this->sendJson('DELETE', $this->photos($product)."/$first");
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
        $this->getJson($this->photos($product));
        self::assertSame([$second => true], $this->mainByPhoto());
        $this->getJson($this->photos($product)."/$first/content?size=small");
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND, 'a removed photo is shown nowhere');

        $this->postJson($this->photos($product)."/$first/restore", null);
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
        $this->getJson($this->photos($product));
        self::assertSame([$first => true, $second => false], $this->mainByPhoto(), 'back where it was, main again');
    }

    public function testAProductHoldsAtMostSixPhotosAndARemovedOneLeavesItsPlace(): void
    {
        $this->signedIn(['product.read', 'product.write']);
        $product = $this->aProduct();
        $photos = [];
        for ($i = 0; $i < 6; ++$i) {
            $photos[] = $this->added($product, self::jpeg(20, 20));
        }

        $this->uploadFile($this->photos($product), 'seventh.jpg', self::jpeg(20, 20));
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame(['code' => 'too_many_photos', 'params' => ['max' => 6]], array_intersect_key($this->json(), ['code' => 1, 'params' => 1]));

        $this->sendJson('DELETE', $this->photos($product).'/'.$photos[0]);
        $this->added($product, self::jpeg(20, 20));

        $this->postJson($this->photos($product).'/'.$photos[0].'/restore', null);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, 'the gallery filled up meanwhile');
        self::assertSame('too_many_photos', $this->stringAt($this->json(), 'code'));
    }

    public function testOnlyARasterPictureIsKept(): void
    {
        $this->signedIn(['product.read', 'product.write']);
        $product = $this->aProduct();

        foreach ([
            'a vector file, which can carry a script' => ['photo.png', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', 'not_a_picture'],
            'a PDF' => ['photo.jpg', "%PDF-1.4\n1 0 obj<<>>endobj", 'not_a_picture'],
            'a GIF' => ['photo.gif', self::gif(), 'not_a_picture'],
            'bytes that are no picture' => ['photo.jpg', 'not a picture at all', 'not_a_picture'],
            'an empty file' => ['photo.jpg', '', 'empty'],
        ] as $why => [$name, $bytes, $code]) {
            $this->uploadFile($this->photos($product), $name, $bytes);
            self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, $why);
            self::assertSame($code, $this->stringAt($this->json(), 'code'), $why);
        }
        $this->getJson($this->photos($product));
        self::assertSame([], $this->jsonList(), 'a refused file leaves no photo');
    }

    public function testAPhotoTooLargeInBytesOrInPixelsIsRefusedBeforeItIsDecoded(): void
    {
        $this->signedIn(['product.read', 'product.write']);
        $product = $this->aProduct();

        $this->uploadFile($this->photos($product), 'big.jpg', self::jpeg(20, 20).str_repeat("\0", 5 * 1024 * 1024));
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame(['code' => 'too_large', 'params' => ['maxBytes' => 5242880]], array_intersect_key($this->json(), ['code' => 1, 'params' => 1]));

        $this->uploadFile($this->photos($product), 'bomb.png', self::pngClaiming(20000, 20000));
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame(['code' => 'too_many_pixels', 'params' => ['maxMegapixels' => 16]], array_intersect_key($this->json(), ['code' => 1, 'params' => 1]));
    }

    public function testPhotosCountInWhatTheCompanyKeeps(): void
    {
        $this->signedIn(['product.read', 'product.write']);
        $product = $this->aProduct();
        $files = static::getContainer()->get(Files::class);
        $before = $files->bytesUsed($this->company);
        $sent = self::jpeg(1200, 800);

        $this->added($product, $sent);

        $kept = $this->em()->getConnection()->fetchOne('SELECT SUM(f.size) FROM product_photo p JOIN file f ON f.id IN (p.original_file_id, p.small_file_id, p.large_file_id)');
        self::assertIsNumeric($kept);
        $kept = (int) $kept;
        self::assertGreaterThan(\strlen($sent), $kept, 'the original and both copies');
        self::assertSame($before + $kept, $files->bytesUsed($this->company));
    }

    public function testEachChangeIsOnRecordOnTheProduct(): void
    {
        $this->signedIn(['product.read', 'product.write']);
        $product = $this->aProduct();
        $first = $this->added($product, self::jpeg(20, 20));
        $second = $this->added($product, self::jpeg(20, 20));
        $this->postJson($this->photos($product)."/$second/main", null);
        $this->postJson($this->photos($product).'/order', ['photoIds' => [$second, $first]]);
        $this->sendJson('DELETE', $this->photos($product)."/$first");
        $this->postJson($this->photos($product)."/$first/restore", null);

        $actions = $this->em()->getConnection()->fetchFirstColumn("SELECT action FROM audit_log WHERE entity_type = 'product' AND entity_id = ? AND action LIKE 'product.%photo%'", [$product]);
        sort($actions);
        self::assertSame(['product.main_photo_changed', 'product.photo_added', 'product.photo_added', 'product.photo_removed', 'product.photo_restored', 'product.photos_reordered'], $actions);
    }

    public function testAReaderSeesPhotosAndChangesNone(): void
    {
        $this->createUser('reader@twes.local', 'password-1234', $this->company, ['product.read'], 'reader');
        $this->signedIn(['product.read', 'product.write']);
        $product = $this->aProduct();
        $photo = $this->added($product, self::jpeg(20, 20));
        $this->login('reader@twes.local', 'password-1234');

        $this->getJson($this->photos($product));
        self::assertCount(1, $this->jsonList());
        $this->getJson($this->photos($product)."/$photo/content?size=small");
        self::assertResponseIsSuccessful();

        $this->uploadFile($this->photos($product), 'photo.jpg', self::jpeg(20, 20));
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        $this->sendJson('DELETE', $this->photos($product)."/$photo");
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        $this->postJson($this->photos($product)."/$photo/main", null);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        $this->postJson($this->photos($product).'/order', ['photoIds' => [$photo]]);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testAnotherCompanysPhotosAreNotFound(): void
    {
        $other = $this->createCompany('Other');
        static::getContainer()->get(ProvisionCompany::class)->handle($other);
        $this->createUser('stranger@twes.local', 'password-1234', $other, ['product.read', 'product.write'], 'stranger');
        $this->signedIn(['product.read', 'product.write']);
        $product = $this->aProduct();
        $photo = $this->added($product, self::jpeg(20, 20));
        $this->login('stranger@twes.local', 'password-1234');
        $theirs = '/api/companies/'.$other->getId()->toRfc4122()."/products/$product/photos";

        $this->getJson($this->photos($product));
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        $this->getJson("$theirs/$photo/content?size=original");
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND, 'their product path does not reach our product');
        $this->uploadFile($theirs, 'photo.jpg', self::jpeg(20, 20));
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testASignedOutCallerGetsNothing(): void
    {
        $this->getJson('/api/companies/'.$this->company->getId()->toRfc4122().'/products/0192f3b4-1c2d-7e8f-9a0b-1c2d3e4f5a6b/photos');

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    private function added(string $product, string $bytes): string
    {
        $this->uploadFile($this->photos($product), 'photo.jpg', $bytes);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        return $this->stringAt($this->json(), 'id');
    }

    /** @return array<string, bool> whether each photo of the gallery is main, in the gallery's order */
    private function mainByPhoto(): array
    {
        $main = [];
        foreach ($this->jsonList() as $row) {
            $main[$this->stringAt($row, 'id')] = $this->boolAt($row, 'main');
        }

        return $main;
    }

    private function aProduct(): string
    {
        $unit = static::getContainer()->get(UnitRepository::class)->ofCodeInCompany('C62', $this->company->getId());
        self::assertNotNull($unit);
        $this->postJson($this->products(), [
            'reference' => 'ART-'.bin2hex(random_bytes(3)), 'name' => 'Perceuse', 'description' => null, 'kind' => 'goods',
            'unitId' => $unit->getId()->toRfc4122(), 'unitPriceNet' => '10', 'costPrice' => null, 'categoryId' => null,
            'defaultTaxComponentIds' => [], 'customFields' => [], 'isActive' => true,
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        return $this->stringAt($this->json(), 'id');
    }

    /** @param list<string> $permissions */
    private function signedIn(array $permissions): void
    {
        $this->createUser('clerk@twes.local', 'password-1234', $this->company, $permissions, 'clerk');
        $this->login('clerk@twes.local', 'password-1234');
        self::assertResponseIsSuccessful();
    }

    private function products(): string
    {
        return '/api/companies/'.$this->company->getId()->toRfc4122().'/products';
    }

    private function photos(string $product): string
    {
        return $this->products()."/$product/photos";
    }
}
