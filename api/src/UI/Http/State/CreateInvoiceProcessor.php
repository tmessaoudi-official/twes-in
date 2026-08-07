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
use Twes\Domain\Document\FixedCharge;
use Twes\Domain\Document\VatRoundingPoint;
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

        try {
            $persisted = $this->handler->handle(self::command($data));
        } catch (\InvalidArgumentException|\DomainException $refused) {
            // The original is passed as `$previous` so the log keeps the class and the stack, while the client gets
            // the message. Wrapped rather than re-thrown because an `\InvalidArgumentException` reaching Symfony's
            // exception listener untranslated is a 500, and every one of these is a caller error.
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

        return new CreateInvoice(
            $currency,
            $lines,
            $charges,
            // NOT THE CLIENT'S CHOICE — see `NewInvoiceInput`, which has no such field. `PerRateGroup` and `PerLine`
            // produce numerically different tax figures, so a client picking per request would be a client picking
            // how much tax a document declares. It becomes company configuration when the settings table lands, and
            // it is persisted per document so that a later change cannot restate a document already sent.
            VatRoundingPoint::PerRateGroup,
        );
    }
}
