<?php

/*
 * This file is part of twes-in.
 *
 * (c) Takieddine MESSAOUDI <takieddine.messaoudi.official@gmail.com>
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Twes\UI\Http\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Twes\Application\Document\CreateInvoice;
use Twes\Application\Document\CreateInvoiceHandler;
use Twes\Domain\Document\DocumentLine;
use Twes\Domain\Document\Exception\DocumentCannotBeTotalled;
use Twes\Domain\Document\Exception\UnknownClient;
use Twes\Domain\Document\FixedCharge;
use Twes\Domain\Money\Currency;
use Twes\Domain\Money\Money;
use Twes\Domain\Pricing\Rate;
use Twes\UI\Http\ApiResource\InvoiceResource;
use Twes\UI\Http\ApiResource\NewInvoiceInput;

/**
 * `POST /api/invoices` — parse the request body into the domain's own vocabulary, then run the use case.
 *
 * **PARSING IS THIS CLASS'S REAL JOB**, and it is why the use-case command carries `Money` and `Rate` rather than
 * strings. Turning `"19"` into a `Rate` and `"TND"` into a `Currency` is what a transport boundary does; putting it
 * in the handler instead would mean every future caller — a CLI import, a Messenger consumer — reproducing the same
 * string handling, and would put the errors a client must see as a 422 somewhere that cannot produce a response.
 *
 * ## Domain refusals become 422, and the type split is the whole mechanism
 *
 * `\InvalidArgumentException` and `\DomainException` are caught and re-thrown as 422; everything else propagates as a
 * 500. That maps onto the domain's own convention: a value the caller can retype raises the former (a malformed
 * decimal, a negative price, a quantity whose product with the unit price cannot be represented), while *our* faults
 * — a number from the wrong sequence, a sequence adapter breaking its contract — raise a bare `\LogicException` or a
 * `\RuntimeException` and are `error.internal`. Nothing here is rolled back by hand: the handler's transaction has
 * already rolled back by the time the exception arrives, so a 422 leaves the database as it was and consumes no
 * document number.
 *
 * **WHAT IS OWED, stated rather than left to be discovered.** The 422 body carries the domain's English message, not
 * a translation key. `CLAUDE.md` § "Translation keys" specifies a key per user-fixable refusal — `document.
 * quantity_too_precise`, `document.total_too_large` — and resolving them here is impossible today because a dozen
 * distinct keyed refusals all raise a bare `\InvalidArgumentException` with the message as their only payload. Making
 * that work needs a typed exception per refusal in `Domain/`, which is its own deliverable and a larger one than this
 * endpoint. Until then the *shape* errors — the ones a client hits most — are caught by the validator on
 * {@see NewInvoiceInput}, whose messages Symfony translates into all three locales.
 *
 * **`<mixed, …>` RATHER THAN `<NewInvoiceInput, …>`, and the difference is not cosmetic.** `ProcessorInterface::process()`
 * declares `mixed $data`; the generic parameter is a PROMISE about what the operation is wired to, not an enforcement.
 * Annotating it as `NewInvoiceInput` made PHPStan report the guard below as `instanceof.alwaysTrue` — provably dead
 * code — which is the half-hollow-assertion class `CLAUDE.md` records its configuration catching, arriving from the
 * other direction: the annotation would have been the thing making a live check look dead. So the annotation states
 * what the framework actually hands over and the guard is what narrows it. The input type is declared where it is
 * enforced: `input: NewInvoiceInput::class` on the operation.
 *
 * @implements ProcessorInterface<mixed, InvoiceResource>
 */
final readonly class CreateInvoiceProcessor implements ProcessorInterface
{
    public function __construct(private CreateInvoiceHandler $handler) {}

    /**
     * @param array<string, mixed> $uriVariables
     * @param array<string, mixed> $context
     *
     * @throws UnprocessableEntityHttpException if the payload is well-formed but the domain refuses it
     */
    public function process(
        mixed $data,
        Operation $operation,
        array $uriVariables = [],
        array $context = [],
    ): InvoiceResource {
        // NOT AN `assert()`. API Platform's `input:` declaration is what puts a `NewInvoiceInput` here, and this is
        // a `mixed` parameter by interface — so the check is real rather than a restatement of a type declaration,
        // and it is a `\LogicException` because only a misconfigured operation can reach it.
        if (!$data instanceof NewInvoiceInput) {
            throw new \LogicException(\sprintf(
                'Expected a %s, got %s. This processor is wired to an operation whose `input:` is something else.',
                NewInvoiceInput::class,
                get_debug_type($data),
            ));
        }

        // **THE CONVERSION IS INSIDE THE `try` AND THE HANDLER IS NOT, and separating them is the fix for the third
        // instance of one defect.** Round 2 removed a whole-call `catch (\InvalidArgumentException)` from
        // `InvoiceProvider` and then from `IssueInvoiceProcessor` — writing *"a full-set miss, found by the second
        // MAXIMAL round"* in the second one — and left this one standing. Round 3 found it.
        //
        // What the wide catch swallowed: `handle()` reaches the repository's read-back, which hydrates through
        // `InvoiceMapper`, and every refusal the mapper raises for corrupt or unrepresentable column data
        // (`InvalidMoneyAmount`, `UnknownCurrency`, `InvalidRate`) extends `\InvalidArgumentException`. So OUR fault
        // was reported to the client as a **422 naming an internal column** — and the old comment asserted *"every one
        // of these is a caller error"*, which was the false half.
        //
        // **Deleting the catch outright is NOT the fix, and measuring that is what produced this shape.** Round 3
        // measured six legitimate 422 cases going red, because `self::command($data)` was evaluated inside the same
        // `try`: an unknown currency, a negative unit price, a negative quantity, a too-precise quantity, a negative
        // VAT rate and an oversized product are all genuine caller errors raised by the CONVERSION. So the conversion
        // keeps the catch and the handler loses it.
        try {
            $command = self::command($data);
        } catch (\InvalidArgumentException|\DomainException $refused) {
            // The original is passed as `$previous` so the log keeps the class and the stack, while the client gets
            // the message. Wrapped rather than re-thrown because an `\InvalidArgumentException` reaching Symfony's
            // exception listener untranslated is a 500 — and everything reachable HERE really is a caller error,
            // because `command()` only ever parses what arrived on the wire.
            throw new UnprocessableEntityHttpException($refused->getMessage(), $refused);
        }

        // OUTSIDE THE `try`. A `\DomainException` from the aggregate is still a 422 — Symfony's own listener has no
        // opinion, so it becomes a 500 — and that is deliberate: `Invoice::draft()` plus `withLine()` cannot refuse
        // anything the conversion above did not already accept, so a domain refusal here would be OUR modelling error
        // rather than the caller's. If a future transition makes one reachable on create, it wants its own arm with
        // its own reasoning, not this one silently widening to cover it.
        // **ITS OWN ARM, WHICH IS WHAT THE PARAGRAPH ABOVE ASKED FOR RATHER THAN A WIDER CATCH.** `UnknownClient` is
        // the one domain refusal reachable from `handle()` that the conversion cannot have already caught, because
        // whether a client id names a client THIS TENANT can see is a question only the database answers — through
        // the composite foreign key, inside the handler's transaction. Widening the `catch` above to cover it would
        // also re-admit every `\InvalidArgumentException` the mapper raises for corrupt column data, which is the
        // defect three rounds removed from three separate classes.
        //
        // Without this arm the refusal is a 500: `\DomainException` means nothing to Symfony's exception listener,
        // so a caller who mistyped one character of a client id would be told the server broke.
        //
        // **AND `DocumentCannotBeTotalled`, WHICH IS THE SECOND SUCH ARM AND WAS FOUND THE SAME WAY.** The
        // paragraph above ends *"`Invoice::draft()` plus `withLine()` cannot refuse anything the conversion above
        // did not already accept"*, and round 4 measured that false (R4C-2): the conversion checks each LINE, and
        // the document TOTAL is not a line. Two amounts of `900000000000000` are each representable and their sum
        // is not, so the refusal is raised by the aggregate inside `handle()` and answered **500** — a caller told
        // the server broke for typing two large-but-legal numbers. The same reasoning as the arm above applies
        // unchanged: widening the conversion's `catch` to cover it would re-admit every `\InvalidArgumentException`
        // the mapper raises for corrupt column data, which is OUR fault and must stay a 500. So it gets its own
        // arm, and `DocumentCannotBeTotalled` exists to make it distinguishable from the mapper's.
        //
        // `assertRoomFor()` — the other refusal `Invoice` raises from inside `handle()` — deliberately has NO arm:
        // `NewInvoiceInput` carries `#[Assert\Count(max: Invoice::MAX_LINES)]` on both collections, so the
        // validator answers 422 before the aggregate is built and the guard is unreachable from this transport.
        // It is kept in the aggregate regardless, because a guard deleted because today's only caller cannot reach
        // it is deleted for the wrong reason — and an importer or a `PUT` will reach it. It earns its arm and its
        // key in the change that makes it reachable, not before.
        try {
            $persisted = $this->handler->handle($command);
        } catch (UnknownClient|DocumentCannotBeTotalled $refused) {
            throw new UnprocessableEntityHttpException($refused->getMessage(), $refused);
        }

        return InvoiceRepresentation::of($persisted);
    }

    /**
     * The wire's strings, parsed into the domain's types.
     *
     * **THE CURRENCY IS RESOLVED ONCE AND EVERY AMOUNT IS BUILT FROM IT.** `Money::of()` takes the currency, so
     * parsing each line against a freshly resolved one would be four lookups for one answer — and, more to the
     * point, `Invoice::withLine()` refuses a line whose currency is not the document's, so a single `Currency`
     * instance is what makes that check about the DOCUMENT rather than about string equality.
     */
    private static function command(NewInvoiceInput $input): CreateInvoice
    {
        $currency = Currency::of($input->currency);

        $lines = [];

        foreach ($input->lines as $line) {
            $lines[] = new DocumentLine(
                $line->quantity,
                Money::of($line->unitNet, $currency),
                Rate::fromPercentage($line->vatRate),
            );
        }

        $charges = [];

        foreach ($input->fixedCharges as $charge) {
            $charges[] = new FixedCharge($charge->label, Money::of($charge->amount, $currency));
        }

        // NO ROUNDING POINT. This call used to pass `VatRoundingPoint::PerRateGroup` as a literal, under a comment
        // ending "it becomes company configuration when the settings table lands". It landed, so the literal is
        // gone rather than annotated (`CLAUDE.md` § Gotchas 2026-07-29) — and it is gone from the COMMAND too, not
        // merely from this call site, because a field there would have left a CLI import or a Messenger consumer
        // free to state what the 2026-08-07 ruling says no caller may choose. `CreateInvoiceHandler` reads it from
        // the company's settings inside its own transaction; see {@see CreateInvoice} for the full argument.
        return new CreateInvoice($currency, $lines, $charges, $input->clientId);
    }
}
