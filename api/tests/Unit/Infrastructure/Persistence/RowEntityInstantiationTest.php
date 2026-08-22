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
 * **EVERY MAPPED PROPERTY, not one per entity.** The first version of this test drove ONE named property per
 * class — 4 of 19 — while this paragraph claimed "the properties must come back uninitialised", and it picked
 * `type` for `DocumentNumberSequenceRow`, whose `$nextValue = 1` is exactly the shape being guarded against: a
 * NOT NULL `bigint` column WITH a default. So the case that exists to catch a default silently substituted for a
 * real column value looked past the only one present. Round 2 filed it as reading stronger than it is, the
 * `test-gates.sh` 33/33 shape.
 *
 * The property set is now DERIVED by reflection and the two legitimate defaults are named with their reasons, so
 * adding `public string $state = 'draft';` to `DocumentRow` fails here rather than passing. If a future default is
 * genuinely correct, this list is where the argument for it gets written down.
 */
final class RowEntityInstantiationTest extends TestCase
{
    /**
     * The two properties that legitimately carry a default, each with the reason the entity itself gives.
     *
     * Nothing else may. A third entry here is a decision about persistence semantics, not a way to make this
     * test pass.
     */
    private const array DEFAULTED = [
        // THE NULLABLE PAIR: a document is created unnumbered and `Invoice::issue()` allocates afterwards, so both
        // halves of the number default to null. They are listed as two entries rather than one because this list is
        // keyed by property and a single entry would leave the sibling unguarded — but they are ONE decision, and the
        // migration's `document_number_halves_are_paired` is what stops them diverging in the database.
        //
        // The comment here said "The one NULLABLE column" until 2026-08-06, when `number_rendered` landed and made it
        // false. Corrected in place rather than annotated, per `CLAUDE.md` § Gotchas 2026-07-29 — and worth noting
        // that this test is what forced the correction: it failed the moment the new property appeared, refusing to
        // accept a defaulted field that nobody had declared deliberate.
        DocumentRow::class . '::number',
        DocumentRow::class . '::numberRendered',
        // THE CLIENT, added 2026-08-22 with the document -> client link, and this test forced the entry exactly as
        // it forced `numberRendered`'s: the property appeared, the assertion refused a defaulted field nobody had
        // declared deliberate, and the reason had to be written before it would pass. That is the third time this
        // list has done its job on its own author.
        //
        // Deliberate for the same reason as the number pair, and it is the SAME SHAPE: a draft legitimately has
        // none, `Invoice::issue()` requires one (EN 16931 makes the buyer mandatory, BT-44), and the requirement
        // is therefore CONDITIONAL on another column. A required constructor parameter cannot express "present
        // unless this is a draft", so the relationship is enforced where a relationship can be — the migration's
        // `document_client_required_once_issued`, `Invoice::issue()`, and `Invoice::fromPersistedState()`.
        DocumentRow::class . '::clientId',
        // NOT NULL, and the default IS the contract: 1 is the number sequence port's second guarantee, so a row
        // that has handed nothing out is at 1. This is the shape the assertion below guards against everywhere
        // else, permitted here because the value is the domain's, not a convenience.
        DocumentNumberSequenceRow::class . '::nextValue',
    ];

    /** @return iterable<string, array{class-string}> */
    public static function mappedRowEntities(): iterable
    {
        yield 'document' => [DocumentRow::class];
        yield 'document_line' => [DocumentLineRow::class];
        yield 'document_charge' => [DocumentChargeRow::class];
        yield 'document_number_sequence' => [DocumentNumberSequenceRow::class];
    }

    /**
     * @param class-string $entity
     */
    #[DataProvider('mappedRowEntities')]
    public function testDoctrineBuildsItWithoutCallingTheConstructor(string $entity): void
    {
        // No arguments. A constructor call here is an ArgumentCountError, which is the failure this pins.
        $row = new Instantiator()->instantiate($entity);

        self::assertInstanceOf($entity, $row);

        $properties = new \ReflectionClass($entity)->getProperties();

        // ANTI-VACUITY: reflection returning nothing would make every assertion below pass on an empty loop,
        // which is the shape this repository has filed against its own gates more than once.
        self::assertNotEmpty($properties, 'reflection found no properties, so the loop below proves nothing');

        foreach ($properties as $property) {
            if (\in_array($entity . '::' . $property->getName(), self::DEFAULTED, true)) {
                // Asserted in the POSITIVE direction, so the exemption cannot outlive the default it exempts:
                // remove the default and this fails, which is what stops the list becoming a stale allowlist.
                self::assertTrue(
                    $property->isInitialized($row),
                    \sprintf('%s::$%s is listed in DEFAULTED but has no default', $entity, $property->getName()),
                );

                continue;
            }

            self::assertFalse(
                $property->isInitialized($row),
                \sprintf(
                    '%s::$%s came back INITIALISED from an instance Doctrine built without a constructor, which '
                    . 'means it has a default value. Hydration would then silently substitute that default for the '
                    . 'real column on any path that does not set the field, and `checkUninitializedProperties` — '
                    . 'the check these constructors exist to satisfy — would report clean throughout. If the '
                    . 'default is deliberate, add it to DEFAULTED with the reason.',
                    $entity,
                    $property->getName(),
                ),
            );
        }
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
    public function testTheConstructorItselfRefusesToBeCalledWithNoArguments(string $entity): void
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
