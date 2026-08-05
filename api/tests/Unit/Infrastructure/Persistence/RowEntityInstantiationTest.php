<?php

/*
 * This file is part of twes-in.
 *
 * (c) Takieddine MESSAOUDI <takieddine.messaoudi.official@gmail.com>
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Twes\Tests\Unit\Infrastructure\Persistence;

use Doctrine\Instantiator\Instantiator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Twes\Infrastructure\Persistence\Doctrine\Entity\DocumentChargeRow;
use Twes\Infrastructure\Persistence\Doctrine\Entity\DocumentLineRow;
use Twes\Infrastructure\Persistence\Doctrine\Entity\DocumentNumberSequenceRow;
use Twes\Infrastructure\Persistence\Doctrine\Entity\DocumentRow;

/**
 * DOCTRINE MUST BE ABLE TO BUILD A ROW ENTITY WITHOUT CALLING ITS CONSTRUCTOR.
 *
 * The four mapped row entities gained constructors with every NOT NULL column as a REQUIRED parameter, and
 * {@see DocumentRow::__construct()} justifies that with one load-bearing claim: *"It costs nothing, because
 * Doctrine never calls it. Hydration goes through `Doctrine\Instantiator\Instantiator`, which materialises an
 * instance without invoking the constructor and then writes every mapped field by reflection."*
 *
 * **Nothing in the repository tested that claim, and if it were wrong EVERY READ of a persisted document would
 * raise `ArgumentCountError` with no test noticing** — a certification round filed exactly that: no test anywhere
 * calls `persist()` or `flush()`, the only mapper test is a pure in-memory round trip that calls the constructors
 * itself, and the Doctrine repository is still owed. So the safety argument for the highest-risk change in that
 * commit was pinned by reasoning alone, which `CLAUDE.md` § Gotchas records five separate times as the most
 * expensive artefact in this repository.
 *
 * This is the ten-second check that was missing. It drives `Instantiator` directly rather than booting the kernel,
 * because that is the exact collaborator `ClassMetadata::newInstance()` delegates to
 * [`vendor/doctrine/orm/src/Mapping/ClassMetadata.php` → `$this->instantiator->instantiate($this->name)`] — a
 * kernel boot would also need a database, and this belongs in the `unit` suite where it runs on every commit.
 *
 * Two assertions per entity, and the second is the one that would catch a subtler regression than an
 * `ArgumentCountError`: the properties must come back UNINITIALISED. If a future edit gave one of them a default
 * value "to be safe", hydration would silently overwrite a real column value with that default on any path where
 * Doctrine does not set the field — and `phpstan.neon.dist`'s `checkUninitializedProperties`, which is what these
 * constructors exist to satisfy, would report clean.
 */
final class RowEntityInstantiationTest extends TestCase
{
    /**
     * Every mapped entity and one NOT NULL property of each, which is the property whose default would betray it.
     *
     * @return iterable<string, array{class-string, string}>
     */
    public static function mappedRowEntities(): iterable
    {
        yield 'document' => [DocumentRow::class, 'currency'];
        yield 'document_line' => [DocumentLineRow::class, 'unitNet'];
        yield 'document_charge' => [DocumentChargeRow::class, 'amount'];
        yield 'document_number_sequence' => [DocumentNumberSequenceRow::class, 'type'];
    }

    /**
     * @param class-string $entity
     */
    #[DataProvider('mappedRowEntities')]
    public function testDoctrineBuildsItWithoutCallingTheConstructor(string $entity, string $property): void
    {
        // No arguments. A constructor call here is an ArgumentCountError, which is the failure this pins.
        $row = new Instantiator()->instantiate($entity);

        self::assertInstanceOf($entity, $row);
        self::assertFalse(
            new \ReflectionProperty($entity, $property)->isInitialized($row),
            \sprintf(
                '%s::$%s came back INITIALISED from an instance Doctrine built without a constructor, which means '
                . 'it has a default value. Hydration would then silently substitute that default for the real '
                . 'column on any path that does not set the field, and `checkUninitializedProperties` — the check '
                . 'these constructors exist to satisfy — would report clean throughout.',
                $entity,
                $property,
            ),
        );
    }

    /**
     * And the constructor really is required, so the test above is not vacuous.
     *
     * Without this, an entity whose constructor was quietly given default values for every parameter would pass
     * the case above for the wrong reason — `Instantiator` would succeed, but so would `new $entity()`, and the
     * forget-a-column guard the constructors exist for would be gone.
     *
     * @param class-string $entity
     */
    #[DataProvider('mappedRowEntities')]
    public function testTheConstructorItselfRefusesToBeCalledWithNoArguments(string $entity, string $property): void
    {
        $this->expectException(\ArgumentCountError::class);

        // NO SUPPRESSION ANNOTATION HERE. One was written on the assumption PHPStan would object to this
        // deliberately illegal call; it does not, and `reportUnmatchedIgnoredErrors` in `phpstan.neon.dist` failed
        // the gate on the unused suppression — that flag earning its place on its first outing. (Nor can the
        // annotation be NAMED in a comment: PHPStan parses its own directive out of any comment it appears in, so
        // writing it inside backticks here produced `Parse error in @phpstan-ig` + `nore` instead.)
        new $entity();
    }
}
