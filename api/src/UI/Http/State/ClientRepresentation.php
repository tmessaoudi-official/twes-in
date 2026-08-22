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

use Twes\Domain\Client\Client;
use Twes\Domain\Client\Contact;
use Twes\UI\Http\ApiResource\ClientResource;
use Twes\UI\Http\ApiResource\ContactResource;
use Twes\UI\Http\ApiResource\PostalAddressResource;

/**
 * The one translation from the {@see Client} aggregate to the resource the API returns.
 *
 * **ONE PLACE, BECAUSE TWO OPERATIONS ANSWER WITH THE SAME RESOURCE.** {@see CreateClientProcessor} and
 * {@see ClientProvider} both hand back a {@see ClientResource}, and the day they disagree is the day
 * `POST /api/clients` and a later `GET` of the same client describe it differently — with nothing failing.
 * {@see InvoiceRepresentation} exists for exactly this reason on the invoice path; this is the same rule applied
 * before the second caller had a chance to drift.
 *
 * **A STATIC FUNCTION AND NOT A SERVICE**, because it holds nothing and decides nothing: it is a total function
 * from an aggregate to a DTO. A service would invite a dependency, and a dependency here would be a piece of the
 * response that the aggregate did not determine.
 */
final class ClientRepresentation
{
    private function __construct() {}

    public static function of(Client $client): ClientResource
    {
        $address = $client->address();

        return new ClientResource(
            $client->id(),
            $client->name(),
            $client->taxIdentifier(),
            // NULL RATHER THAN AN OBJECT OF EMPTY STRINGS. A client with no address has none; a resource with five
            // blank fields would be a different claim, and a client rendering it would print an empty address block
            // rather than omitting one.
            null === $address ? null : new PostalAddressResource(
                $address->line1,
                $address->line2,
                $address->postcode,
                $address->city,
                $address->countryCode,
            ),
            // `array_map` OVER THE AGGREGATE'S OWN ORDER, with nothing sorted. The order is what the user arranged,
            // it is persisted in a `position` column for that reason, and `DoctrineClientRepository::find()` reads
            // it back with an explicit `ORDER BY` whose absence a mutant proved detectable only once the fixture
            // stopped letting an index scan supply the ordering by accident.
            array_map(
                static fn(Contact $contact): ContactResource => new ContactResource(
                    $contact->id,
                    $contact->name,
                    $contact->email,
                    $contact->phone,
                ),
                $client->contacts(),
            ),
        );
    }
}
