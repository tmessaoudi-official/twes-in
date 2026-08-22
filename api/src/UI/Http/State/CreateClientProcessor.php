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
use Twes\Application\Client\CreateClient;
use Twes\Application\Client\CreateClientHandler;
use Twes\Application\Client\NewContact;
use Twes\Domain\Client\PostalAddress;
use Twes\UI\Http\ApiResource\ClientResource;
use Twes\UI\Http\ApiResource\NewClientInput;

/**
 * `POST /api/clients` — parse the request body into the domain's own vocabulary, then run the use case.
 *
 * ## The `try` covers the CONVERSION and not the handler, and that split is inherited from a measured mistake
 *
 * `CLAUDE.md` § Gotchas records the invoice write path getting this wrong three times: a whole-call
 * `catch (\InvalidArgumentException)` around `handle()` swallowed HYDRATION failures, so corrupt column data was
 * reported to the client as a 422 naming an internal column — our fault, dressed as the caller's. Deleting the
 * catch outright was not the fix either, because six legitimate 422 cases were raised by the CONVERSION. So the
 * conversion keeps the catch and the handler loses it, and this class is written that way from the start rather
 * than after the same three rounds.
 *
 * **THE HANDLER OUTSIDE THE `try` MEANS A DOMAIN REFUSAL FROM IT IS A 500, AND THAT IS ONLY CORRECT BECAUSE OF THE
 * VALIDATOR.** Everything {@see \Twes\Domain\Client\Client} and {@see \Twes\Domain\Client\Contact} refuse is
 * mirrored by a constraint on {@see NewClientInput} and {@see \Twes\UI\Http\ApiResource\NewContactInput}, so a
 * payload that would offend the aggregate is answered as a 422 naming the field before `handle()` is called. If a
 * future bound is added to the domain WITHOUT its edge constraint, this reasoning stops holding and the symptom is
 * a 500 on a payload a user could have fixed — which is why the two are described as one rule in both places.
 *
 * **`<mixed, …>` RATHER THAN `<NewClientInput, …>`.** `ProcessorInterface::process()` declares `mixed $data`; the
 * generic parameter is a PROMISE about what the operation is wired to, not an enforcement. Annotating it narrowly
 * makes PHPStan report the guard below as `instanceof.alwaysTrue` — provably dead code — which is the annotation
 * making a LIVE check look dead, recorded in `CLAUDE.md` § Gotchas 2026-08-07. The input type is declared where it
 * is enforced: `input: NewClientInput::class` on the operation.
 *
 * @implements ProcessorInterface<mixed, ClientResource>
 */
final readonly class CreateClientProcessor implements ProcessorInterface
{
    public function __construct(private CreateClientHandler $handler) {}

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
    ): ClientResource {
        // NOT AN `assert()`. API Platform's `input:` declaration is what puts a `NewClientInput` here, and this is a
        // `mixed` parameter by interface — so the check is real rather than a restatement of a type declaration,
        // and it is a `\LogicException` because only a misconfigured operation can reach it.
        if (!$data instanceof NewClientInput) {
            throw new \LogicException(\sprintf(
                'Expected a %s, got %s. This processor is wired to an operation whose `input:` is something else.',
                NewClientInput::class,
                get_debug_type($data),
            ));
        }

        try {
            $command = self::command($data);
        } catch (\InvalidArgumentException|\DomainException $refused) {
            // The original is passed as `$previous` so the log keeps the class and the stack while the client gets
            // the message. Wrapped rather than re-thrown because an `\InvalidArgumentException` reaching Symfony's
            // exception listener untranslated is a 500 — and everything reachable HERE really is a caller error,
            // because `command()` only ever parses what arrived on the wire.
            throw new UnprocessableEntityHttpException($refused->getMessage(), $refused);
        }

        return ClientRepresentation::of($this->handler->handle($command));
    }

    /**
     * The wire's values, parsed into the domain's types.
     *
     * **THE ADDRESS IS BUILT HERE AND THE CONTACTS ARE NOT**, which is the one asymmetry worth understanding.
     * {@see PostalAddress} is constructible from exactly what arrived, so it is built here and its refusals become
     * a 422. A {@see \Twes\Domain\Client\Contact} cannot be built without an id this layer is not allowed to
     * choose, so the three fields travel as a {@see NewContact} and the handler completes them once it has minted
     * an identity.
     */
    private static function command(NewClientInput $input): CreateClient
    {
        $address = null === $input->address ? null : new PostalAddress(
            $input->address->line1,
            $input->address->line2,
            $input->address->postcode,
            $input->address->city,
            $input->address->countryCode,
        );

        $contacts = [];

        foreach ($input->contacts as $contact) {
            $contacts[] = new NewContact($contact->name, $contact->email, $contact->phone);
        }

        return new CreateClient($input->name, $input->taxIdentifier, $address, $contacts);
    }
}
