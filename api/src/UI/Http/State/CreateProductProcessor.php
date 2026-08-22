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
use Twes\Application\Product\CreateProduct;
use Twes\Application\Product\CreateProductHandler;
use Twes\Domain\Money\Currency;
use Twes\Domain\Money\Money;
use Twes\Domain\Pricing\ProductPricing;
use Twes\Domain\Pricing\Rate;
use Twes\UI\Http\ApiResource\NewProductInput;
use Twes\UI\Http\ApiResource\ProductResource;

/**
 * `POST /api/products` — parse the request body into the domain's own vocabulary, then run the use case.
 *
 * **PARSING IS THIS CLASS'S REAL JOB, and here it includes the one decision F4 will not let anyone else make:
 * WHICH pricing constructor to call.** `ProductPricing` has exactly two, and no way to build one without
 * choosing; the choice is "which field did the user type", which only the layer holding the request body knows.
 * By the time a {@see CreateProduct} exists the choice is made and unforgeable, which is why that command
 * carries a built `ProductPricing` rather than two nullable fields.
 *
 * ## The `try` covers the CONVERSION and not the handler
 *
 * Inherited from the invoice write path, which took three rounds to get right: a whole-call catch swallowed
 * hydration failures and reported our fault as the caller's, while deleting the catch outright turned six
 * legitimate 422s into 500s. The conversion keeps the catch; the handler does not.
 *
 * **The handler being outside it is only correct because of the validator.** Every bound the aggregate enforces
 * is mirrored on {@see NewProductInput} — including the exactly-one-price-field rule, which has no domain
 * exception at all because the domain type makes the state unrepresentable. If a future bound is added to
 * `Domain/` without its edge constraint, this reasoning stops holding and the symptom is a 500 on a payload a
 * user could have fixed.
 *
 * @implements ProcessorInterface<mixed, ProductResource>
 */
final readonly class CreateProductProcessor implements ProcessorInterface
{
    public function __construct(private CreateProductHandler $handler) {}

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
    ): ProductResource {
        // NOT AN `assert()`. API Platform's `input:` declaration is what puts a `NewProductInput` here, and this
        // is a `mixed` parameter by interface — so the check is real rather than a restatement of a type
        // declaration, and it is a `\LogicException` because only a misconfigured operation can reach it.
        if (!$data instanceof NewProductInput) {
            throw new \LogicException(\sprintf(
                'Expected a %s, got %s. This processor is wired to an operation whose `input:` is something else.',
                NewProductInput::class,
                get_debug_type($data),
            ));
        }

        try {
            $command = self::command($data);
        } catch (\InvalidArgumentException|\DomainException $refused) {
            // The original is passed as `$previous` so the log keeps the class and the stack while the client
            // gets the message. Everything reachable HERE is a caller error, because `command()` only ever
            // parses what arrived on the wire.
            throw new UnprocessableEntityHttpException($refused->getMessage(), $refused);
        }

        return ProductRepresentation::of($this->handler->handle($command));
    }

    /**
     * The wire's strings, parsed into the domain's types.
     *
     * **THE CURRENCY IS RESOLVED ONCE AND BOTH AMOUNTS ARE BUILT FROM IT.** `ProductPricing::fromNetPrice()`
     * refuses a price in a different currency from the cost, so one `Currency` instance is what makes that check
     * about the PRODUCT rather than about string equality — the same reasoning `CreateInvoiceProcessor` gives
     * for a document's lines.
     *
     * **THE `else` BRANCH IS UNREACHABLE FROM HTTP AND IS NOT AN `assert()`.** The validator's
     * exactly-one-price-field callback refuses both-or-neither before this runs. It is written as a real
     * refusal anyway because this method is a pure function of its input and will one day be called by something
     * that is not the validator — a CLI import, a Messenger consumer — and a guard that exists only in a
     * comment is the shape `CLAUDE.md` § Gotchas records four separate times.
     */
    private static function command(NewProductInput $input): CreateProduct
    {
        $currency = Currency::of($input->currency);
        $cost = Money::of($input->cost, $currency);

        if (null !== $input->profitRate && null === $input->netPrice) {
            $pricing = ProductPricing::fromProfitRate($cost, Rate::fromPercentage($input->profitRate));
        } elseif (null !== $input->netPrice && null === $input->profitRate) {
            $pricing = ProductPricing::fromNetPrice($cost, Money::of($input->netPrice, $currency));
        } else {
            throw new \InvalidArgumentException(
                'A product is priced by a typed profit rate OR a typed net price, never both and never neither. '
                . 'Which one the user typed is what decides which value is authoritative and which is merely '
                . 'derived, so it cannot be inferred.',
            );
        }

        return new CreateProduct($input->name, $input->sku, $pricing, Rate::fromPercentage($input->vatRate));
    }
}
