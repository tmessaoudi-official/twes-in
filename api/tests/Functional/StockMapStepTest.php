<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Fiscal\Application\Company\ProvisionCompany;
use App\Tenancy\Domain\Model\Company;
use App\Tenancy\Domain\Repository\EstablishmentRepository;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

/**
 * The stock map is a draft: every gesture on a floor, on its racks and on its building alike, stays on the screen
 * until ONE « Enregistrer » sends the whole floor's draft as one step, all of it or none of it, on the versions it was
 * read at. The answer says what the step created, so the toast's « Annuler » can send the inverse step in the same
 * shape. StockMapTest holds what the step did before the building and the new places joined it.
 */
final class StockMapStepTest extends ApiTestCase
{
    private Company $company;
    private string $establishmentId;
    private string $ground;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = $this->createCompany('Quincaillerie');
        static::getContainer()->get(ProvisionCompany::class)->handle($this->company);
        $this->establishmentId = static::getContainer()->get(EstablishmentRepository::class)->ofCompany($this->company->getId())[0]->getId()->toRfc4122();
    }

    /** A place posed from the palette is created with its rectangle, in the zone it is drawn in, the zone itself new. */
    public function testNewPlacesAreCreatedWithTheirRectanglesAndSaidBack(): void
    {
        $this->signedIn();
        $this->postJson($this->changes(), ['newPlaces' => [
            $this->newPlace('zone', 'Z1', 'Visserie', ['x' => '10', 'y' => '8', 'width' => '6', 'depth' => '4']),
            $this->newPlace('rack', 'R9', 'Rayonnage 9', ['x' => '11', 'y' => '9', 'width' => '2', 'depth' => '1']),
        ]]);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);

        $created = $this->section($this->json(), 'created');
        self::assertSame([], $created['draws']);
        self::assertSame([], $created['structureBuilds']);
        $places = $this->rows($created, 'newPlaces');
        self::assertCount(2, $places);
        $drawn = array_column($this->rows($this->json(), 'drawings'), 'locationId', 'id');
        foreach ($places as $place) {
            self::assertSame($place['locationId'], $drawn[$this->stringAt($place, 'drawingId')] ?? null, 'each new place is said with the rectangle drawn for it');
        }
        self::assertSame([], $this->arrayAt($this->json(), 'structures'), 'no building was touched');
        $zone = $this->stringAt($places[0] ?? self::fail('a zone'), 'locationId');
        $rack = $this->stringAt($places[1] ?? self::fail('a rack'), 'locationId');

        // The rack stands in the zone the same step drew, so it is a place of that zone; the zone, in none, sits under the site.
        $this->getJson($this->path('stock-locations'));
        $parents = array_column($this->jsonList(), 'parentId', 'id');
        self::assertSame($zone, $parents[$rack] ?? null);
        self::assertSame($this->jsonList()[0]['id'], $parents[$zone] ?? null);
    }

    /** A wall is built, reshaped and removed by the step, each answered with the building as the step left it. */
    public function testThePiecesOfTheBuildingAreBuiltReshapedAndRemovedByTheStep(): void
    {
        $this->signedIn();
        $this->postJson($this->changes(), ['structureBuilds' => [$this->piece([]), $this->piece(['kind' => 'door', 'name' => 'Porte du quai', 'x' => '7', 'width' => '0.9'])]]);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $built = $this->built();
        self::assertSame($built, array_column($this->rows($this->json(), 'structures'), 'id'), 'in the order they were sent');
        [$wall, $door] = [$built[0] ?? self::fail('a wall'), $built[1] ?? self::fail('a door')];
        self::assertSame([['wall', '', 1], ['door', 'Porte du quai', 1]], $this->pieces());

        $this->postJson($this->changes(), ['structureMoves' => [['structureId' => $wall, 'version' => 1, ...$this->piece(['name' => 'Mur nord', 'width' => '8'])]]]);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $rows = array_column($this->rows($this->json(), 'structures'), null, 'id');
        self::assertSame(['Mur nord', '8.000', 2], [$rows[$wall]['name'] ?? null, $rows[$wall]['width'] ?? null, $rows[$wall]['version'] ?? null]);
        self::assertSame([], $this->built());

        $this->postJson($this->changes(), ['structureErasures' => [['structureId' => $door, 'version' => 1]]]);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        self::assertSame([$wall], array_column($this->arrayAt($this->json(), 'structures'), 'id'));
        self::assertSame([['wall', 'Mur nord', 2]], $this->pieces());
    }

    /**
     * A rectangle moved by the step may say which place it is drawn for from now on. The place may come off a
     * rectangle the same step erases; the place it was drawn for before is then drawn nowhere, and still a place.
     */
    public function testAMovedRectangleIsGivenAnotherPlace(): void
    {
        $this->signedIn();
        [$r1, $r1Place] = $this->drawn('R1', []);
        [$r2, $r2Place] = $this->drawn('R2', ['y' => '6']);
        $read = $this->versions();

        $this->postJson($this->changes(), [
            'moves' => [['drawingId' => $r1, ...$this->drawing(['x' => '4']), 'version' => $read[$r1], 'locationId' => $r2Place]],
            'erasures' => [['drawingId' => $r2, 'version' => $read[$r2]]],
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $after = $this->rows($this->json(), 'drawings');
        self::assertSame([[$r1, 'R2', '4.000']], array_map(static fn (array $one): array => [$one['id'], $one['locationCode'], $one['x']], $after));
        self::assertGreaterThan($read[$r1], $this->intAt($after[0] ?? self::fail('a drawing'), 'version'));
        $this->getJson($this->path('stock-locations'));
        self::assertContains($r1Place, array_column($this->jsonList(), 'id'), 'the place left undrawn is still a place');

        // Without a place named, the rectangle keeps the one it has, exactly as before the draft could relabel.
        $this->postJson($this->changes(), ['moves' => [['drawingId' => $r1, ...$this->drawing(['x' => '5']), 'version' => $this->versions()[$r1]]]]);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        self::assertSame(['R2'], array_column($this->arrayAt($this->json(), 'drawings'), 'locationCode'));
    }

    /**
     * The whole draft of a floor in one step: a rack moved, a place drawn new, a place drawn again, a wall reshaped,
     * a door built, a post removed. Each row is read back from the database, the audit says each piece and rectangle
     * as the single endpoints do, and the inverse step built from the answer puts the floor back.
     */
    public function testAWholeDraftIsSavedAsOneStepAndTakenBackByItsInverse(): void
    {
        $this->signedIn();
        [$rack] = $this->drawn('R1', []);
        [$undrawn, $undrawnPlace] = $this->drawn('R2', ['y' => '9']);
        $this->postJson($this->changes(), ['erasures' => [['drawingId' => $undrawn, 'version' => $this->versions()[$undrawn]]]]);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $this->postJson($this->changes(), ['structureBuilds' => [$this->piece([]), $this->piece(['kind' => 'post', 'x' => '12', 'y' => '6', 'width' => '0.3', 'depth' => '0.3'])]]);
        $built = $this->built();
        [$wall, $post] = [$built[0] ?? self::fail('a wall'), $built[1] ?? self::fail('a post')];
        $before = $this->floorAsItStands();
        $read = $this->versions();
        $told = $this->auditIds();

        $this->postJson($this->changes(), [
            'draws' => [['locationId' => $undrawnPlace, ...$this->drawing(['x' => '15', 'y' => '1'])]],
            'moves' => [['drawingId' => $rack, ...$this->drawing(['x' => '5', 'y' => '6', 'rotation' => 90]), 'version' => $read[$rack]]],
            'newPlaces' => [$this->newPlace('rack', 'R7', 'Rayonnage 7', ['x' => '18', 'y' => '2'])],
            'structureBuilds' => [$this->piece(['kind' => 'door', 'x' => '3', 'width' => '0.9', 'height' => '2.1'])],
            'structureMoves' => [['structureId' => $wall, 'version' => 1, ...$this->piece(['width' => '9.5', 'name' => 'Mur nord'])]],
            'structureErasures' => [['structureId' => $post, 'version' => 1]],
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $answer = $this->json();
        $created = $this->section($answer, 'created');
        $drawnAgain = $this->ids($created, 'draws')[0] ?? self::fail('a rectangle drawn');
        $newPlace = $this->rows($created, 'newPlaces')[0] ?? self::fail('a place created');
        [$newDrawing, $newLocation] = [$this->stringAt($newPlace, 'drawingId'), $this->stringAt($newPlace, 'locationId')];
        $door = $this->ids($created, 'structureBuilds')[0] ?? self::fail('a piece built');

        // Row by row in the database, not only in the answer.
        $db = $this->em()->getConnection();
        $spots = [];
        foreach ($db->fetchAllAssociative('SELECT s.id, l.code, s.plan_x, s.plan_y, s.plan_rotation FROM venue_spot s LEFT JOIN stock_location l ON l.spot_id = s.id WHERE s.area_id = ? ORDER BY l.code', [$this->ground]) as $row) {
            $spots[] = [$row['code'], $row['plan_x'], $row['plan_y'], $row['plan_rotation']];
        }
        self::assertSame([['R1', '5.000', '6.000', 90], ['R2', '15.000', '1.000', 0], ['R7', '18.000', '2.000', 0]], $spots);
        $pieces = [];
        foreach ($db->fetchAllAssociative('SELECT id, kind, name, plan_x, plan_width FROM venue_structure WHERE area_id = ? ORDER BY kind', [$this->ground]) as $row) {
            $pieces[] = [$row['id'], $row['kind'], $row['name'], $row['plan_x'], $row['plan_width']];
        }
        self::assertSame([[$door, 'door', '', '3.000', '0.900'], [$wall, 'wall', 'Mur nord', '0.000', '9.500']], $pieces);
        self::assertSame('R7', $db->fetchOne('SELECT code FROM stock_location WHERE id = ? AND spot_id = ?', [$newLocation, $newDrawing]));

        // Each piece and rectangle is told as its own endpoint tells it, which is what open plans reload on.
        // As a set: audit_log.at is to the second, so the rows of one step have no order to assert.
        $audited = [];
        foreach ($db->fetchAllAssociative('SELECT id, entity_id, action FROM audit_log WHERE company_id = ?', [$this->company->getId()->toRfc4122()]) as $row) {
            if (!\in_array($row['id'], $told, true)) {
                $audited[] = \sprintf('%s %s', \is_string($row['entity_id']) ? $row['entity_id'] : '', \is_string($row['action']) ? $row['action'] : '');
            }
        }
        sort($audited);
        $expected = [
            "$rack venue_spot.revised",
            "$drawnAgain venue_spot.created",
            "$undrawnPlace stock_location.revised",
            "$newDrawing venue_spot.created",
            "$newLocation stock_location.created",
            "$newLocation stock_location.revised",
            "$door venue_structure.created",
            "$wall venue_structure.revised",
            "$post venue_structure.deleted",
        ];
        sort($expected);
        self::assertSame($expected, $audited);

        // « Annuler »: the same shape, built from the answer and the versions it gave.
        $versions = array_column($this->rows($answer, 'drawings'), 'version', 'id');
        $pieceVersions = array_column($this->rows($answer, 'structures'), 'version', 'id');
        $this->postJson($this->changes(), [
            'moves' => [['drawingId' => $rack, ...$this->drawing([]), 'version' => $versions[$rack]]],
            'erasures' => [
                ['drawingId' => $drawnAgain, 'version' => $versions[$drawnAgain]],
                ['drawingId' => $newDrawing, 'version' => $versions[$newDrawing]],
            ],
            'structureBuilds' => [$this->piece(['kind' => 'post', 'x' => '12', 'y' => '6', 'width' => '0.3', 'depth' => '0.3'])],
            'structureMoves' => [['structureId' => $wall, 'version' => $pieceVersions[$wall], ...$this->piece([])]],
            'structureErasures' => [['structureId' => $door, 'version' => $pieceVersions[$door]]],
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $this->sendJson('DELETE', $this->path('stock-locations', $newLocation));
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);

        self::assertSame($before, $this->floorAsItStands());
    }

    /**
     * Everything a step names is resolved before the first write: a refusal anywhere, the last entry included, leaves
     * the floor exactly as it was and names the entry by its place in what was sent.
     */
    public function testARefusedStepNamesItsEntryAndWritesNothing(): void
    {
        $this->signedIn(['stock.read', 'stock.write', 'company.read', 'company.settings']);
        [$rack, $rackPlace] = $this->drawn('R1', []);
        [$other] = $this->drawn('R2', ['y' => '6']);
        $this->postJson($this->path('stock-locations'), ['establishmentId' => $this->establishmentId, 'parentId' => $rackPlace, 'kind' => 'bin', 'code' => 'B1', 'name' => 'Bac 1']);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $bin = $this->stringAt($this->json(), 'id');
        $this->postJson($this->path('stock-locations'), ['establishmentId' => $this->establishmentId, 'parentId' => null, 'kind' => 'rack', 'code' => 'R5', 'name' => 'Rayonnage 5']);
        $loose = $this->stringAt($this->json(), 'id');
        $this->postJson($this->path('establishments'), ['code' => '001', 'name' => 'Agence de Sfax', 'city' => 'Sfax']);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $this->postJson($this->path('stock-locations'), ['establishmentId' => $this->stringAt($this->json(), 'id'), 'parentId' => null, 'kind' => 'rack', 'code' => 'SF1', 'name' => 'Rayonnage de Sfax']);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $sfax = $this->stringAt($this->json(), 'id');
        $this->postJson($this->changes(), ['structureBuilds' => [$this->piece([]), $this->piece(['y' => '3']), $this->piece(['y' => '6'])]]);
        $built = $this->built();
        [$a, $b, $c] = [$built[0] ?? self::fail('a wall'), $built[1] ?? self::fail('a wall'), $built[2] ?? self::fail('a wall')];
        $this->postJson($this->path('stock-floors'), ['establishmentId' => $this->establishmentId, 'name' => 'Étage 1', 'level' => 1, 'widthMetres' => '24', 'depthMetres' => '15']);
        $upstairs = $this->stringAt($this->json(), 'id');
        $this->postJson($this->path('stock-floors', $upstairs).'/structures', $this->piece([]));
        $elsewhere = $this->stringAt($this->json(), 'id');
        $before = $this->floorAsItStands();
        $codes = $this->codes();
        $since = $this->auditCount();

        // A valid start to every refused step, so the refusal is never the first thing it meets.
        $start = [
            'structureBuilds' => [$this->piece(['kind' => 'door', 'x' => '20'])],
            'newPlaces' => [$this->newPlace('rack', 'R8', 'Rayonnage 8', ['x' => '20', 'y' => '10'])],
            'moves' => [['drawingId' => $rack, ...$this->drawing(['x' => '9']), 'version' => 1]],
        ];
        $moved = $this->pieceMoved(...);
        foreach ([
            ['structureMoves[2].x', [...$start, 'structureMoves' => [$moved($a), $moved($b), $moved($c, ['x' => '-1'])]]],
            ['structureMoves[2].kind', [...$start, 'structureMoves' => [$moved($a), $moved($b), $moved($c, ['kind' => 'moat'])]]],
            ['structureMoves[0].structureId', [...$start, 'structureMoves' => [$moved(Uuid::v7()->toRfc4122())]]],
            ['structureMoves[0].structureId', [...$start, 'structureMoves' => [$moved($elsewhere)]]],
            ['structureMoves[1].structureId', [...$start, 'structureMoves' => [$moved($a), $moved($a)]]],
            ['structureMoves[0].version', [...$start, 'structureMoves' => [['structureId' => $a, ...$this->piece([])]]]],
            ['structureMoves[0].name', [...$start, 'structureMoves' => [$moved($a, ['name' => str_repeat('m', 121)])]]],
            ['structureErasures[0].structureId', [...$start, 'structureMoves' => [$moved($a)], 'structureErasures' => [['structureId' => $a, 'version' => 1]]]],
            ['structureErasures[0].structureId', [...$start, 'structureErasures' => [['structureId' => $elsewhere, 'version' => 1]]]],
            ['structureErasures[0].version', [...$start, 'structureErasures' => [['structureId' => $b]]]],
            ['structureBuilds[1].kind', [...$start, 'structureBuilds' => [$this->piece([]), $this->piece(['kind' => 'moat'])]]],
            ['structureBuilds[0].name', [...$start, 'structureBuilds' => [$this->piece(['name' => str_repeat('m', 121)])]]],
            ['structureBuilds[0].depth', [...$start, 'structureBuilds' => [$this->piece(['depth' => '0'])]]],
            ['newPlaces[1].code', [...$start, 'newPlaces' => [...$start['newPlaces'], $this->newPlace('rack', 'R1', 'Doublon', [])]]],
            ['newPlaces[1].code', [...$start, 'newPlaces' => [...$start['newPlaces'], $this->newPlace('rack', 'R8', 'Doublon', [])]]],
            ['newPlaces[0].code', [...$start, 'newPlaces' => [$this->newPlace('rack', 'R 8', 'Rayonnage 8', [])]]],
            ['newPlaces[0].name', [...$start, 'newPlaces' => [$this->newPlace('rack', 'R8', '  ', [])]]],
            ['newPlaces[0].kind', [...$start, 'newPlaces' => [$this->newPlace('bin', 'B8', 'Bac 8', [])]]],
            ['newPlaces[0].kind', [...$start, 'newPlaces' => [$this->newPlace('moat', 'M8', 'Douve', [])]]],
            ['newPlaces[0].width', [...$start, 'newPlaces' => [$this->newPlace('rack', 'R8', 'Rayonnage 8', ['width' => '0'])]]],
            ['moves[1].locationId', [...$start, 'moves' => [...$start['moves'], ['drawingId' => $other, ...$this->drawing([]), 'version' => 1, 'locationId' => Uuid::v7()->toRfc4122()]]]],
            ['moves[1].locationId', [...$start, 'moves' => [...$start['moves'], ['drawingId' => $other, ...$this->drawing([]), 'version' => 1, 'locationId' => $bin]]]],
            ['moves[1].locationId', [...$start, 'moves' => [...$start['moves'], ['drawingId' => $other, ...$this->drawing([]), 'version' => 1, 'locationId' => $sfax]]]],
            ['moves[0].locationId', ['moves' => [['drawingId' => $other, ...$this->drawing([]), 'version' => 1, 'locationId' => $rackPlace]]]],
            ['moves[1].locationId', [...$start, 'moves' => [...$start['moves'], ['drawingId' => $other, ...$this->drawing([]), 'version' => 1, 'locationId' => $rackPlace]]]],
            ['moves[1].locationId', ['moves' => [['drawingId' => $rack, ...$this->drawing([]), 'version' => 1, 'locationId' => $loose], ['drawingId' => $other, ...$this->drawing([]), 'version' => 1, 'locationId' => $loose]]]],
            ['draws[0].locationId', [...$start, 'moves' => [['drawingId' => $rack, ...$this->drawing([]), 'version' => 1, 'locationId' => $loose]], 'draws' => [['locationId' => $loose, ...$this->drawing(['y' => '12'])]]]],
            ['structureMoves', [...$start, 'structureMoves' => ['first' => $moved($a)]]],
            ['newPlaces', [...$start, 'newPlaces' => ['first' => $start['newPlaces'][0]]]],
        ] as [$field, $body]) {
            $this->postJson($this->changes(), $body);
            self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, $field);
            self::assertStringContainsString($field.':', (string) $this->client->getResponse()->getContent(), $field);
        }

        self::assertSame($before, $this->floorAsItStands());
        self::assertSame($codes, $this->codes(), 'no place was created');
        self::assertSame($since, $this->auditCount(), 'nothing was written, so nothing was told');
    }

    /** A piece or a rectangle saved since the version a draft named for it refuses the whole mixed step, 409. */
    public function testAMixedStepOverAPieceOrARectangleSavedSinceIsRefusedWhole(): void
    {
        $this->signedIn();
        [$rack, $rackPlace] = $this->drawn('R1', []);
        [$other] = $this->drawn('R2', ['y' => '6']);
        $this->postJson($this->changes(), ['structureBuilds' => [$this->piece([]), $this->piece(['y' => '3'])]]);
        $built = $this->built();
        [$wall, $door] = [$built[0] ?? self::fail('a wall'), $built[1] ?? self::fail('a door')];

        // Someone else reshapes the wall and moves R1 while the draft is open.
        $this->sendJson('PUT', $this->path('stock-structures', $wall), $this->piece(['name' => 'Mur nord', 'version' => 1]));
        self::assertResponseIsSuccessful();
        $this->sendJson('PUT', $this->path('stock-drawings', $rack), $this->drawing(['locationId' => $rackPlace, 'y' => '2', 'version' => 1]));
        self::assertResponseIsSuccessful();
        $before = $this->floorAsItStands();
        $codes = $this->codes();

        $draft = [
            'newPlaces' => [$this->newPlace('rack', 'R8', 'Rayonnage 8', ['x' => '20', 'y' => '10'])],
            'structureBuilds' => [$this->piece(['kind' => 'post', 'x' => '20'])],
            'moves' => [['drawingId' => $other, ...$this->drawing(['x' => '9', 'y' => '6']), 'version' => 1]],
        ];
        foreach ([
            'a stale piece moved' => [...$draft, 'structureMoves' => [['structureId' => $door, 'version' => 1, ...$this->piece(['y' => '4'])], ['structureId' => $wall, 'version' => 1, ...$this->piece(['x' => '1'])]]],
            'a stale piece removed' => [...$draft, 'structureErasures' => [['structureId' => $door, 'version' => 1], ['structureId' => $wall, 'version' => 1]]],
            'a stale rectangle moved' => [...$draft, 'moves' => [...$draft['moves'], ['drawingId' => $rack, ...$this->drawing(['x' => '1']), 'version' => 1]], 'structureMoves' => [['structureId' => $door, 'version' => 1, ...$this->piece(['y' => '4'])]]],
            'a stale rectangle erased' => [...$draft, 'erasures' => [['drawingId' => $rack, 'version' => 1]], 'structureErasures' => [['structureId' => $door, 'version' => 1]]],
        ] as $case => $step) {
            $this->postJson($this->changes(), $step);
            self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT, $case);
            self::assertSame('/problems/record-changed', $this->json()['type'], $case);
        }
        self::assertSame($before, $this->floorAsItStands());
        self::assertSame($codes, $this->codes());
    }

    /** Another company's floor, and a member who may read the stock but not arrange it, are answered 404. */
    public function testAnotherCompanysFloorAndAReaderAreNotFound(): void
    {
        $this->signedIn();
        $this->postJson($this->changes(), ['structureBuilds' => [$this->piece([])]]);
        $wall = $this->built()[0] ?? self::fail('a wall');

        $theirs = $this->createCompany('Atelier voisin');
        static::getContainer()->get(ProvisionCompany::class)->handle($theirs);
        $this->createUser('voisin@twes.local', 'password-1234', $theirs, ['stock.read', 'stock.write'], 'member');
        $this->login('voisin@twes.local', 'password-1234');
        $theirPath = '/api/companies/'.$theirs->getId()->toRfc4122();
        $theirEstablishment = static::getContainer()->get(EstablishmentRepository::class)->ofCompany($theirs->getId())[0]->getId()->toRfc4122();
        $this->postJson($theirPath.'/stock-floors', ['establishmentId' => $theirEstablishment, 'name' => 'Atelier', 'level' => 0, 'widthMetres' => '10', 'depthMetres' => '10']);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $theirFloor = $this->stringAt($this->json(), 'id');
        // This company's wall named on their floor is no piece of that floor.
        $this->postJson($theirPath.'/stock-floors/'.$theirFloor.'/drawing-changes', ['structureErasures' => [['structureId' => $wall, 'version' => 1]]]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertStringContainsString('structureErasures[0].structureId:', (string) $this->client->getResponse()->getContent());

        // Their floor reached through this company's address by this company's member is not found, and keeps its size.
        $this->login('map@twes.local', 'password-1234');
        $this->postJson($this->path('stock-floors', $theirFloor).'/drawing-changes', ['structureBuilds' => [$this->piece([])], 'floor' => ['widthMetres' => '30', 'depthMetres' => '30', 'version' => 1]]);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        self::assertSame('10.000', $this->em()->getConnection()->fetchOne('SELECT width_metres FROM venue_area WHERE id = ?', [$theirFloor]));
        self::assertSame(0, $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM venue_structure WHERE area_id = ?', [$theirFloor]));

        $mine = $this->em()->find(Company::class, $this->company->getId()) ?? self::fail('the company vanished');
        $this->createUser('reader@twes.local', 'password-1234', $mine, ['stock.read'], 'lecteur');
        $this->login('reader@twes.local', 'password-1234');
        $this->postJson($this->changes(), ['structureErasures' => [['structureId' => $wall, 'version' => 1]]]);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        $this->getJson($this->path('stock-floors', $this->ground).'/structures');
        self::assertSame([$wall], array_column($this->jsonList(), 'id'));
    }

    /**
     * Dragging the floor's own edge is part of the draft: its size is saved in the same step, on the version the floor
     * was read at, with the floor form's own rules; its name, level and plan are left as they are.
     */
    public function testTheFloorsSizeIsSavedInTheStep(): void
    {
        $this->signedIn();
        $this->getJson($this->path('stock-floors'));
        $read = $this->intAt($this->jsonList()[0], 'version');

        // Alone: the floor is measured again, and the answer says the version it is now at.
        $this->postJson($this->changes(), ['floor' => ['widthMetres' => '30', 'depthMetres' => '15.5', 'version' => $read]]);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $now = $this->intAt($this->json(), 'floorVersion');
        self::assertGreaterThan($read, $now);
        $this->getJson($this->path('stock-floors'));
        $floor = $this->jsonList()[0];
        self::assertSame(['Rez-de-chaussée', 0, '30.000', '15.500', $now], [$floor['name'], $floor['level'], $floor['widthMetres'], $floor['depthMetres'], $floor['version']]);

        // Absent, the floor is untouched, and its version is said as it stands.
        $this->postJson($this->changes(), ['structureBuilds' => [$this->piece([])]]);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        self::assertSame($now, $this->intAt($this->json(), 'floorVersion'));

        // With a rack moved, both in one step.
        [$rack] = $this->drawn('R1', []);
        $this->postJson($this->changes(), [
            'floor' => ['widthMetres' => '32', 'depthMetres' => '15.5', 'version' => $now],
            'moves' => [['drawingId' => $rack, ...$this->drawing(['x' => '28']), 'version' => 1]],
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $later = $this->intAt($this->json(), 'floorVersion');
        self::assertGreaterThan($now, $later);
        self::assertSame(['28.000'], array_column($this->arrayAt($this->json(), 'drawings'), 'x'));
        $before = $this->floorAsItStands();

        // Refused with the floor form's own rules, named in the step, and nothing of the rest written.
        foreach ([
            'floor.widthMetres' => ['widthMetres' => '0', 'depthMetres' => '15', 'version' => $later],
            'floor.depthMetres' => ['widthMetres' => '30', 'depthMetres' => 'large', 'version' => $later],
            'floor.version' => ['widthMetres' => '30', 'depthMetres' => '15'],
        ] as $field => $sent) {
            $this->postJson($this->changes(), ['moves' => [['drawingId' => $rack, ...$this->drawing(['x' => '1']), 'version' => $this->versions()[$rack]]], 'floor' => $sent]);
            self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, $field);
            self::assertStringContainsString($field.':', (string) $this->client->getResponse()->getContent(), $field);
        }
        self::assertSame($before, $this->floorAsItStands());

        // Stale: someone renamed the floor since, so the whole step is refused, the rack included.
        $this->sendJson('PUT', $this->path('stock-floors', $this->ground), ['name' => 'Réserve', 'level' => 0, 'widthMetres' => '32', 'depthMetres' => '15.5', 'imageFileId' => null, 'imageMetresWide' => null, 'imageOpacity' => 35, 'version' => $later]);
        self::assertResponseIsSuccessful();
        $this->postJson($this->changes(), [
            'floor' => ['widthMetres' => '40', 'depthMetres' => '15.5', 'version' => $later],
            'moves' => [['drawingId' => $rack, ...$this->drawing(['x' => '1']), 'version' => $this->versions()[$rack]]],
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        self::assertSame('/problems/record-changed', $this->json()['type']);
        self::assertSame($before, $this->floorAsItStands());
        $this->getJson($this->path('stock-floors'));
        self::assertSame(['Réserve', '32.000'], [$this->jsonList()[0]['name'], $this->jsonList()[0]['widthMetres']]);
    }

    /**
     * A place drawn through the single endpoint, as the draft's starting point.
     *
     * @param array<string, mixed> $at
     *
     * @return array{string, string} the rectangle and its place
     */
    private function drawn(string $code, array $at): array
    {
        $this->postJson($this->path('stock-floors', $this->ground).'/drawings', $this->drawing(['newLocationKind' => 'rack', 'newLocationCode' => $code, 'newLocationName' => 'Rayonnage '.$code, ...$at]));
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        return [$this->stringAt($this->json(), 'id'), $this->stringAt($this->json(), 'locationId')];
    }

    /**
     * The floor as a person sees it, ids of pieces aside: a piece removed and built again is the same building.
     *
     * @return array{list<list<mixed>>, list<list<mixed>>}
     */
    private function floorAsItStands(): array
    {
        $this->getJson($this->path('stock-floors', $this->ground).'/drawings');
        $drawings = array_map(static fn (array $one): array => [$one['locationCode'], $one['x'], $one['y'], $one['width'], $one['depth'], $one['rotation'], $one['height']], $this->jsonList());
        sort($drawings);
        $this->getJson($this->path('stock-floors', $this->ground).'/structures');
        $pieces = array_map(static fn (array $one): array => [$one['kind'], $one['name'], $one['x'], $one['y'], $one['width'], $one['depth'], $one['rotation'], $one['height']], $this->jsonList());
        sort($pieces);

        return [$drawings, $pieces];
    }

    /** @return list<list<mixed>> each piece of the ground floor as kind, name and version */
    private function pieces(): array
    {
        $this->getJson($this->path('stock-floors', $this->ground).'/structures');

        return array_map(static fn (array $one): array => [$one['kind'], $one['name'], $one['version']], $this->jsonList());
    }

    /** @return list<mixed> every place's code, sorted */
    private function codes(): array
    {
        $this->getJson($this->path('stock-locations'));
        $codes = array_column($this->jsonList(), 'code');
        sort($codes);

        return $codes;
    }

    /**
     * A piece of a step moved to x = 1, with whatever else the case changes, on version 1.
     *
     * @param array<string, mixed> $at
     *
     * @return array<string, mixed>
     */
    private function pieceMoved(string $id, array $at = []): array
    {
        return ['structureId' => $id, 'version' => 1, ...$this->piece(['x' => '1', ...$at])];
    }

    /** @return list<string> the pieces the last step built, in the order they were sent */
    private function built(): array
    {
        return $this->ids($this->section($this->json(), 'created'), 'structureBuilds');
    }

    /**
     * Each object of a list in a JSON body, asserted to be one.
     *
     * @param array<string, mixed> $body
     *
     * @return list<array<string, mixed>>
     */
    private function rows(array $body, string $key): array
    {
        return array_values(array_map(fn (mixed $row): array => $this->section(['row' => $row], 'row'), $this->arrayAt($body, $key)));
    }

    /**
     * Each id of a list in a JSON body, asserted to be one.
     *
     * @param array<string, mixed> $body
     *
     * @return list<string>
     */
    private function ids(array $body, string $key): array
    {
        return array_values(array_map(static fn (mixed $id): string => \is_string($id) ? $id : self::fail("$key holds ids"), $this->arrayAt($body, $key)));
    }

    private function auditCount(): int
    {
        return \count($this->auditIds());
    }

    /** @return list<mixed> every audit row of the company so far */
    private function auditIds(): array
    {
        return $this->em()->getConnection()->fetchFirstColumn('SELECT id FROM audit_log WHERE company_id = ?', [$this->company->getId()->toRfc4122()]);
    }

    /** @return array<string, int> each rectangle of the ground floor and the version it is read at */
    private function versions(): array
    {
        $this->getJson($this->path('stock-floors', $this->ground).'/drawings');
        $versions = [];
        foreach ($this->jsonList() as $one) {
            $versions[$this->stringAt($one, 'id')] = $this->intAt($one, 'version');
        }

        return $versions;
    }

    private function changes(): string
    {
        return $this->path('stock-floors', $this->ground).'/drawing-changes';
    }

    /**
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

    /**
     * @param array<string, mixed> $at
     *
     * @return array<string, mixed>
     */
    private function newPlace(string $kind, string $code, string $label, array $at): array
    {
        return ['kind' => $kind, 'code' => $code, 'name' => $label, ...$this->drawing($at)];
    }

    private function path(string $resource, ?string $id = null): string
    {
        return '/api/companies/'.$this->company->getId()->toRfc4122().'/'.$resource.(null === $id ? '' : '/'.$id);
    }

    /**
     * A member who may arrange the stock, on a ground floor of 24 × 15 m.
     *
     * @param list<string> $permissions
     */
    private function signedIn(array $permissions = ['stock.read', 'stock.write']): void
    {
        $this->createUser('map@twes.local', 'password-1234', $this->company, $permissions, 'member');
        $this->login('map@twes.local', 'password-1234');
        self::assertResponseIsSuccessful();
        $this->postJson($this->path('stock-floors'), ['establishmentId' => $this->establishmentId, 'name' => 'Rez-de-chaussée', 'level' => 0, 'widthMetres' => '24', 'depthMetres' => '15']);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $this->ground = $this->stringAt($this->json(), 'id');
    }
}
