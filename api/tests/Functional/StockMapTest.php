<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Fiscal\Application\Company\ProvisionCompany;
use App\Tenancy\Domain\Company;
use App\Tenancy\Domain\EstablishmentRepository;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

/**
 * The drawn map's own surface (docs/SPEC.md row 83; § 7, 2026-09-14 and 2026-09-21): floors, and the rectangles on
 * them. The rectangles belong to the venue and the binding to the inventory, so this surface always draws a LOCATION —
 * a rectangle bound to nothing would be unreachable from here and unlabelled on any screen.
 */
final class StockMapTest extends ApiTestCase
{
    private Company $company;
    private string $establishmentId;
    private string $siteId;
    private string $rackId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = $this->createCompany('Quincaillerie');
        static::getContainer()->get(ProvisionCompany::class)->handle($this->company);
        $this->establishmentId = static::getContainer()->get(EstablishmentRepository::class)->ofCompany($this->company->getId())[0]->getId()->toRfc4122();
    }

    public function testAFloorIsDrawnOncePerLevelAndItsRectanglesNameTheirLocations(): void
    {
        $this->signedIn(['stock.read', 'stock.write']);
        $this->locations();

        $this->postJson($this->path('stock-floors'), ['establishmentId' => $this->establishmentId, 'name' => 'Rez-de-chaussée', 'level' => 0, 'widthMetres' => '24', 'depthMetres' => '15']);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $ground = $this->stringAt($this->json(), 'id');
        self::assertSame(['Rez-de-chaussée', 0, null, 0], [$this->json()['name'], $this->json()['level'], $this->json()['imageFileId'], $this->json()['drawingCount']]);

        // One floor per level: a second plan of the same storey is two truths about one place.
        $this->postJson($this->path('stock-floors'), ['establishmentId' => $this->establishmentId, 'name' => 'Doublon', 'level' => 0, 'widthMetres' => '24', 'depthMetres' => '15']);
        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);

        $this->postJson($this->path('stock-floors'), ['establishmentId' => $this->establishmentId, 'name' => 'Étage 1', 'level' => 1, 'widthMetres' => '24', 'depthMetres' => '15']);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $this->getJson($this->path('stock-floors'));
        self::assertSame(['Rez-de-chaussée', 'Étage 1'], array_column($this->jsonList(), 'name'), 'from the ground up');

        $this->postJson($this->path('stock-floors', $ground).'/drawings', $this->drawing(['locationId' => $this->rackId]));
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $drawing = $this->stringAt($this->json(), 'id');
        self::assertSame(['2.500', '4.000', '3.900', '0.600', 0, '2.100'], [
            $this->json()['x'], $this->json()['y'], $this->json()['width'], $this->json()['depth'], $this->json()['rotation'], $this->json()['height'],
        ]);
        // The rectangle carries what the screen labels it with; the venue itself knows none of this.
        self::assertSame([$this->rackId, 'R1', 'Rayonnage 1', 'rack'], [
            $this->json()['locationId'], $this->json()['locationCode'], $this->json()['locationName'], $this->json()['locationKind'],
        ]);

        $this->getJson($this->path('stock-floors', $ground).'/drawings');
        self::assertSame([$this->rackId], array_column($this->jsonList(), 'locationId'));
        $this->getJson($this->path('stock-floors'));
        self::assertSame([1, 0], array_column($this->jsonList(), 'drawingCount'));

        $this->sendJson('PUT', $this->path('stock-drawings', $drawing), $this->drawing(['locationId' => $this->rackId, 'y' => '5.4', 'rotation' => 90]));
        self::assertResponseIsSuccessful();
        self::assertSame(['5.400', 90], [$this->json()['y'], $this->json()['rotation']]);

        // Undrawing a rectangle leaves the location itself alone: it keeps its code, its tree and its stock.
        $this->sendJson('DELETE', $this->path('stock-drawings', $drawing));
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
        $this->getJson($this->path('stock-floors', $ground).'/drawings');
        self::assertSame([], $this->jsonList());
        $this->getJson($this->path('stock-locations'));
        self::assertContains('R1', array_column($this->jsonList(), 'code'));
    }

    /** A floor is asked its size (docs/SPEC.md § 7, 2026-09-22, findings E and H) and answers with it. */
    public function testAFloorIsAddedWithItsSizeAndRefusedWithoutIt(): void
    {
        $this->signedIn(['stock.read', 'stock.write']);

        $this->postJson($this->path('stock-floors'), ['establishmentId' => $this->establishmentId, 'name' => 'Rez-de-chaussée', 'level' => 0]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertStringContainsString('widthMetres', (string) $this->client->getResponse()->getContent());

        $this->postJson($this->path('stock-floors'), ['establishmentId' => $this->establishmentId, 'name' => 'Rez-de-chaussée', 'level' => 0, 'widthMetres' => '24', 'depthMetres' => '0']);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertStringContainsString('depthMetres', (string) $this->client->getResponse()->getContent());

        $this->postJson($this->path('stock-floors'), ['establishmentId' => $this->establishmentId, 'name' => 'Rez-de-chaussée', 'level' => 0, 'widthMetres' => '24', 'depthMetres' => '15.5']);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        self::assertSame(['24.000', '15.500'], [$this->json()['widthMetres'], $this->json()['depthMetres']]);
        $ground = $this->stringAt($this->json(), 'id');

        $this->sendJson('PUT', $this->path('stock-floors', $ground), ['name' => 'Rez-de-chaussée', 'level' => 0, 'widthMetres' => '30', 'depthMetres' => '15.5', 'imageFileId' => null, 'imageMetresWide' => null, 'imageOpacity' => 35]);
        self::assertResponseIsSuccessful();
        $this->getJson($this->path('stock-floors'));
        self::assertSame('30.000', $this->jsonList()[0]['widthMetres']);
    }

    public function testAPlanBehindTheDrawingIsPlacedWithItsWidthInMetres(): void
    {
        $this->signedIn(['stock.read', 'stock.write']);
        $this->postJson($this->path('stock-floors'), ['establishmentId' => $this->establishmentId, 'name' => 'Rez-de-chaussée', 'level' => 0, 'widthMetres' => '24', 'depthMetres' => '15']);
        $ground = $this->stringAt($this->json(), 'id');
        $file = Uuid::v7()->toRfc4122();

        $this->sendJson('PUT', $this->path('stock-floors', $ground), ['name' => 'Rez-de-chaussée', 'level' => 0, 'widthMetres' => '24', 'depthMetres' => '15', 'imageFileId' => $file, 'imageMetresWide' => '24', 'imageOpacity' => 40]);

        self::assertResponseIsSuccessful();
        self::assertSame([$file, '24.000', 40], [$this->json()['imageFileId'], $this->json()['imageMetresWide'], $this->json()['imageOpacity']]);

        // An image with no scale cannot sit under rectangles measured in metres, so the two travel together.
        $this->sendJson('PUT', $this->path('stock-floors', $ground), ['name' => 'Rez-de-chaussée', 'level' => 0, 'widthMetres' => '24', 'depthMetres' => '15', 'imageFileId' => $file, 'imageMetresWide' => null, 'imageOpacity' => 40]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertStringContainsString('imageMetresWide', (string) $this->client->getResponse()->getContent());
    }

    public function testRemovingAFloorTakesItsRectanglesAndLeavesTheLocationsStanding(): void
    {
        $this->signedIn(['stock.read', 'stock.write']);
        $this->locations();
        $this->postJson($this->path('stock-floors'), ['establishmentId' => $this->establishmentId, 'name' => 'Rez-de-chaussée', 'level' => 0, 'widthMetres' => '24', 'depthMetres' => '15']);
        $ground = $this->stringAt($this->json(), 'id');
        $this->postJson($this->path('stock-floors', $ground).'/drawings', $this->drawing(['locationId' => $this->rackId]));
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        $this->sendJson('DELETE', $this->path('stock-floors', $ground));

        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
        $this->getJson($this->path('stock-floors'));
        self::assertSame([], $this->jsonList());
        $this->getJson($this->path('stock-locations'));
        self::assertContains('R1', array_column($this->jsonList(), 'code'), 'a location that is no longer drawn is still a location');
    }

    public function testWhatIsRefusedOnTheSurfaceIsRefusedWithItsField(): void
    {
        $this->signedIn(['stock.read', 'stock.write']);
        $this->locations();
        $this->postJson($this->path('stock-floors'), ['establishmentId' => $this->establishmentId, 'name' => 'Rez-de-chaussée', 'level' => 0, 'widthMetres' => '24', 'depthMetres' => '15']);
        $ground = $this->stringAt($this->json(), 'id');

        foreach ([
            ['width', ['locationId' => $this->rackId, 'width' => '0']],
            ['x', ['locationId' => $this->rackId, 'x' => '-1']],
            ['rotation', ['locationId' => $this->rackId, 'rotation' => 400]],
            ['locationId', ['locationId' => Uuid::v7()->toRfc4122()]],
        ] as [$field, $changes]) {
            $this->postJson($this->path('stock-floors', $ground).'/drawings', $this->drawing($changes));
            self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, $field);
            self::assertStringContainsString($field, (string) $this->client->getResponse()->getContent(), $field);
        }
    }

    public function testAReaderDrawsNothingAndAnotherCompanysPlansAreNotThere(): void
    {
        $this->signedIn(['stock.read']);

        // Not 403: a permission the caller does not hold answers as a company they are not in does, so what a
        // company draws cannot be probed for from outside it.
        $this->postJson($this->path('stock-floors'), ['establishmentId' => $this->establishmentId, 'name' => 'Rez-de-chaussée', 'level' => 0, 'widthMetres' => '24', 'depthMetres' => '15']);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);

        $this->getJson($this->path('stock-floors'));
        self::assertResponseIsSuccessful();
        self::assertSame([], $this->jsonList());

        $stranger = $this->createCompany('Voisine');
        $this->getJson('/api/companies/'.$stranger->getId()->toRfc4122().'/stock-floors');
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    /**
     * Repeating a rack down an aisle (the approved canvas's Repeat board): one call makes the copies AND the stock
     * locations they are, because a rack that is drawn and does not exist is a picture rather than a place.
     *
     * The three refusals are checked on the surface and not only in the use case, because each names a different box
     * of the same four-box form and a person given a bare "invalid" has to guess which one to change.
     */
    public function testARackIsRepeatedDownTheAisleAsLocationsAndRectanglesAtOnce(): void
    {
        $this->signedIn(['stock.read', 'stock.write']);
        $this->locations();
        $this->postJson($this->path('stock-floors'), ['establishmentId' => $this->establishmentId, 'name' => 'Rez-de-chaussée', 'level' => 0, 'widthMetres' => '24', 'depthMetres' => '15']);
        $ground = $this->stringAt($this->json(), 'id');
        $this->postJson($this->path('stock-floors', $ground).'/drawings', $this->drawing(['locationId' => $this->rackId]));
        $drawing = $this->stringAt($this->json(), 'id');

        $this->postJson($this->path('stock-drawings', $drawing).'/repeat', $this->repeat([]));
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $made = $this->arrayAt($this->json(), 'drawings');
        self::assertSame(['R2', 'R3', 'R4'], array_column($made, 'locationCode'));
        // Down the floor by the rack's own depth plus the free floor between two of them: 0,6 + 0,6.
        self::assertSame(['5.200', '6.400', '7.600'], array_column($made, 'y'));
        self::assertSame(['2.500', '2.500', '2.500'], array_column($made, 'x'));

        // Each copy is a stock location of its own, under the same parent, ready for goods.
        $this->getJson($this->path('stock-locations'));
        self::assertSame(['R2', 'R3', 'R4'], array_values(array_filter(
            array_column($this->jsonList(), 'code'),
            static fn (mixed $code): bool => \in_array($code, ['R2', 'R3', 'R4'], true),
        )));

        // Four rectangles on the floor now: the one repeated and the three made from it.
        $this->getJson($this->path('stock-floors', $ground).'/drawings');
        self::assertCount(4, $this->jsonList());

        // A code already taken stops the whole run and says WHICH one, so the person knows what to change.
        $this->postJson($this->path('stock-drawings', $drawing).'/repeat', $this->repeat(['firstCode' => 'R3']));
        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        self::assertStringContainsString('R3', (string) $this->client->getResponse()->getContent());

        // A copy stepping off the floor is refused BEFORE anything is written, naming the side it left by.
        $this->postJson($this->path('stock-drawings', $drawing).'/repeat', $this->repeat(['way' => 'up', 'count' => 9, 'firstCode' => 'R9']));
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertStringContainsString('y', (string) $this->client->getResponse()->getContent());

        $this->postJson($this->path('stock-drawings', $drawing).'/repeat', $this->repeat(['firstCode' => 'RAYONNAGE', 'count' => 1]));
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);

        // Nothing of any of the three refusals reached the floor.
        $this->getJson($this->path('stock-floors', $ground).'/drawings');
        self::assertCount(4, $this->jsonList());
    }

    public function testRepeatingNeedsTheWritePermissionAndARectangleOfThisCompany(): void
    {
        $this->signedIn(['stock.read', 'stock.write']);
        $this->locations();
        $this->postJson($this->path('stock-floors'), ['establishmentId' => $this->establishmentId, 'name' => 'Rez-de-chaussée', 'level' => 0, 'widthMetres' => '24', 'depthMetres' => '15']);
        $ground = $this->stringAt($this->json(), 'id');
        $this->postJson($this->path('stock-floors', $ground).'/drawings', $this->drawing(['locationId' => $this->rackId]));
        $drawing = $this->stringAt($this->json(), 'id');

        // A rectangle of nobody: not found rather than an empty repeat that reads as success.
        $this->postJson($this->path('stock-drawings', Uuid::v7()->toRfc4122()).'/repeat', $this->repeat([]));
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);

        // Re-found, never reused: the test client reboots the kernel between requests, so the company held since
        // setUp is detached by the time a second member is created for it.
        $mineNow = $this->em()->find(Company::class, $this->company->getId());
        self::assertNotNull($mineNow);
        $this->createUser('reader@twes.local', 'password-1234', $mineNow, ['stock.read'], 'lecteur');
        $this->login('reader@twes.local', 'password-1234');
        $this->postJson($this->path('stock-drawings', $drawing).'/repeat', $this->repeat(['firstCode' => 'R8']));
        // 404 and not 403, as every other company-scoped surface here: a member without the permission is told the
        // company is not theirs to act for rather than that it exists and is closed to them.
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    /**
     * @param array<string, mixed> $changes
     *
     * @return array<string, mixed>
     */
    private function repeat(array $changes): array
    {
        return [...['count' => 3, 'spacing' => '0.6', 'way' => 'down', 'firstCode' => 'R2'], ...$changes];
    }

    /** The default site and a rack under it, so a rectangle has something real to name. */
    private function locations(): void
    {
        $this->getJson($this->path('stock-locations'));
        self::assertResponseIsSuccessful();
        $this->siteId = $this->stringAt($this->jsonList()[0], 'id');
        $this->postJson($this->path('stock-locations'), [
            'establishmentId' => $this->establishmentId,
            'parentId' => $this->siteId,
            'kind' => 'rack',
            'code' => 'R1',
            'name' => 'Rayonnage 1',
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $this->rackId = $this->stringAt($this->json(), 'id');
    }

    public function testTheBuildingIsDrawnBesideTheStockAndIsNoneOfIt(): void
    {
        $this->signedIn(['stock.read', 'stock.write']);
        $this->locations();
        $ground = $this->floor();

        // The helper sends no `name` key at all, which is the common case: most walls are just walls.
        $this->postJson($this->path('stock-floors', $ground).'/structures', $this->piece([]));
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $wall = $this->stringAt($this->json(), 'id');
        self::assertSame('', $this->json()['name'], 'a piece nobody named is named nothing, not null');
        self::assertSame(['wall', '0.000', '0.000', '6.900', '0.200', 0, '3.000'], [
            $this->json()['kind'], $this->json()['x'], $this->json()['y'],
            $this->json()['width'], $this->json()['depth'], $this->json()['rotation'], $this->json()['height'],
        ]);
        self::assertSame($ground, $this->json()['floorId']);

        // The whole reason it is its own layer: a wall is not a place, so nothing of it reaches the stock at all.
        $this->getJson($this->path('stock-locations'));
        self::assertNotContains('wall', array_column($this->jsonList(), 'kind'));
        $this->getJson($this->path('stock-floors', $ground).'/drawings');
        self::assertSame([], $this->jsonList(), 'the building is on no stock drawing');

        // Traced with the wall tool and really the doorway: the rectangle is right, only the kind is wrong.
        // The store already calls it something, and that is written down with the correction, trimmed.
        $this->sendJson('PUT', $this->path('stock-structures', $wall), $this->piece(['kind' => 'door', 'width' => '0.9', 'height' => '2.1', 'name' => '  Porte du quai 2  ']));
        self::assertResponseIsSuccessful();
        self::assertSame(['door', '0.900', '2.100'], [$this->json()['kind'], $this->json()['width'], $this->json()['height']]);
        self::assertSame('Porte du quai 2', $this->json()['name']);

        $this->getJson($this->path('stock-floors', $ground).'/structures');
        self::assertSame(['door'], array_column($this->jsonList(), 'kind'));
        self::assertSame(['Porte du quai 2'], array_column($this->jsonList(), 'name'), 'the name is read back with the plan');

        $this->sendJson('DELETE', $this->path('stock-structures', $wall));
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
        $this->getJson($this->path('stock-floors', $ground).'/structures');
        self::assertSame([], $this->jsonList());
    }

    public function testAPieceOfStructureIsRefusedWithItsFieldAndNeedsTheWritePermission(): void
    {
        $this->signedIn(['stock.read', 'stock.write']);
        $this->locations();
        $ground = $this->floor();

        // A tool the board has not got, and a piece with no surface: both name what is wrong rather than 500.
        $this->postJson($this->path('stock-floors', $ground).'/structures', $this->piece(['kind' => 'moat']));
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $this->postJson($this->path('stock-floors', $ground).'/structures', $this->piece(['depth' => '0']));
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertStringContainsString('depth', (string) $this->client->getResponse()->getContent());

        // Longer than the column holds: refused at the door rather than cut to fit and read back as a different name.
        $this->postJson($this->path('stock-floors', $ground).'/structures', $this->piece(['name' => str_repeat('m', 121)]));
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);

        // A wall thinner than any rack the palette would pose is still a wall, and is accepted as measured.
        $this->postJson($this->path('stock-floors', $ground).'/structures', $this->piece(['depth' => '0.05']));
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $thin = $this->stringAt($this->json(), 'id');

        // A reader sees the building and builds none of it, and a piece of another company's is not found at all.
        // The company is re-found: the test client reboots the kernel between requests, so the instance this case
        // opened with is detached by now and persisting a membership through it would insert a second company.
        $mineNow = $this->em()->find(Company::class, $this->company->getId()) ?? self::fail('the company vanished');
        $this->createUser('reader@twes.local', 'password-1234', $mineNow, ['stock.read'], 'lecteur');
        $this->login('reader@twes.local', 'password-1234');
        $this->getJson($this->path('stock-floors', $ground).'/structures');
        self::assertSame(['wall'], array_column($this->jsonList(), 'kind'));
        $this->sendJson('DELETE', $this->path('stock-structures', $thin));
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        $this->sendJson('PUT', $this->path('stock-structures', Uuid::v7()->toRfc4122()), $this->piece([]));
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    /** A floor to build on, which every structure case needs and none of them is about. */
    private function floor(): string
    {
        $this->postJson($this->path('stock-floors'), ['establishmentId' => $this->establishmentId, 'name' => 'Rez-de-chaussée', 'level' => 0, 'widthMetres' => '24', 'depthMetres' => '15']);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        return $this->stringAt($this->json(), 'id');
    }

    /**
     * The canvas's own partition: 6,90 × 0,20 m.
     *
     * @param array<string, mixed> $changes
     *
     * @return array<string, mixed>
     */
    private function piece(array $changes): array
    {
        return [...['kind' => 'wall', 'x' => '0', 'y' => '0', 'width' => '6.9', 'depth' => '0.2', 'rotation' => 0, 'height' => '3'], ...$changes];
    }

    /**
     * @param array<string, mixed> $changes
     *
     * @return array<string, mixed>
     */
    private function drawing(array $changes): array
    {
        return [...['x' => '2.5', 'y' => '4', 'width' => '3.9', 'depth' => '0.6', 'rotation' => 0, 'height' => '2.1'], ...$changes];
    }

    private function path(string $resource, ?string $id = null): string
    {
        return '/api/companies/'.$this->company->getId()->toRfc4122().'/'.$resource.(null === $id ? '' : '/'.$id);
    }

    /** @param list<string> $permissions */
    private function signedIn(array $permissions): void
    {
        $this->createUser('map@twes.local', 'password-1234', $this->company, $permissions, 'member');
        $this->login('map@twes.local', 'password-1234');
        self::assertResponseIsSuccessful();
    }
}
