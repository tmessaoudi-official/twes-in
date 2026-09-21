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

        $this->postJson($this->path('stock-floors'), ['establishmentId' => $this->establishmentId, 'name' => 'Rez-de-chaussée', 'level' => 0]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $ground = $this->stringAt($this->json(), 'id');
        self::assertSame(['Rez-de-chaussée', 0, null, 0], [$this->json()['name'], $this->json()['level'], $this->json()['imageFileId'], $this->json()['drawingCount']]);

        // One floor per level: a second plan of the same storey is two truths about one place.
        $this->postJson($this->path('stock-floors'), ['establishmentId' => $this->establishmentId, 'name' => 'Doublon', 'level' => 0]);
        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);

        $this->postJson($this->path('stock-floors'), ['establishmentId' => $this->establishmentId, 'name' => 'Étage 1', 'level' => 1]);
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

    public function testAPlanBehindTheDrawingIsPlacedWithItsWidthInMetres(): void
    {
        $this->signedIn(['stock.read', 'stock.write']);
        $this->postJson($this->path('stock-floors'), ['establishmentId' => $this->establishmentId, 'name' => 'Rez-de-chaussée', 'level' => 0]);
        $ground = $this->stringAt($this->json(), 'id');
        $file = Uuid::v7()->toRfc4122();

        $this->sendJson('PUT', $this->path('stock-floors', $ground), ['name' => 'Rez-de-chaussée', 'level' => 0, 'imageFileId' => $file, 'imageMetresWide' => '24', 'imageOpacity' => 40]);

        self::assertResponseIsSuccessful();
        self::assertSame([$file, '24.000', 40], [$this->json()['imageFileId'], $this->json()['imageMetresWide'], $this->json()['imageOpacity']]);

        // An image with no scale cannot sit under rectangles measured in metres, so the two travel together.
        $this->sendJson('PUT', $this->path('stock-floors', $ground), ['name' => 'Rez-de-chaussée', 'level' => 0, 'imageFileId' => $file, 'imageMetresWide' => null, 'imageOpacity' => 40]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertStringContainsString('imageMetresWide', (string) $this->client->getResponse()->getContent());
    }

    public function testRemovingAFloorTakesItsRectanglesAndLeavesTheLocationsStanding(): void
    {
        $this->signedIn(['stock.read', 'stock.write']);
        $this->locations();
        $this->postJson($this->path('stock-floors'), ['establishmentId' => $this->establishmentId, 'name' => 'Rez-de-chaussée', 'level' => 0]);
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
        $this->postJson($this->path('stock-floors'), ['establishmentId' => $this->establishmentId, 'name' => 'Rez-de-chaussée', 'level' => 0]);
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
        $this->postJson($this->path('stock-floors'), ['establishmentId' => $this->establishmentId, 'name' => 'Rez-de-chaussée', 'level' => 0]);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);

        $this->getJson($this->path('stock-floors'));
        self::assertResponseIsSuccessful();
        self::assertSame([], $this->jsonList());

        $stranger = $this->createCompany('Voisine');
        $this->getJson('/api/companies/'.$stranger->getId()->toRfc4122().'/stock-floors');
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
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
