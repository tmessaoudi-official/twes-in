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
use Twes\Application\Shared\TransactionalScope;
use Twes\Domain\Document\NumberPattern;
use Twes\Domain\Document\VatRoundingPoint;
use Twes\Domain\Settings\CompanySettings;
use Twes\Domain\Settings\CompanySettingsRepository;
use Twes\UI\Http\ApiResource\CompanySettingsInput;
use Twes\UI\Http\ApiResource\CompanySettingsResource;

/**
 * `PUT /api/settings` — replace the calling company's settings.
 *
 * **THIS IS THE ONLY PRODUCTION CALLER OF `CompanySettingsRepository::save()`, and it shipping in the same change
 * as that method is not a coincidence.** For one commit the settings table existed, the adapter existed, the read
 * path honoured it, and nothing could WRITE it except raw SQL — the "declared but nothing consults it" shape
 * `CLAUDE.md` § Gotchas records four times over, most expensively when `PostgresRowLevelSecurityIsolation::bind()`
 * was documented everywhere as the primary tenancy control and had no call site for three commits.
 *
 * ## The conversion is inside the `try`; the write is not
 *
 * The same split {@see CreateInvoiceProcessor} arrived at after three rounds, and for the same reason. Turning
 * `9` into a `NumberPattern` and `'per_line'` into a `VatRoundingPoint` is a PARSE of untrusted input, and every
 * refusal it raises is a caller error that belongs in a 422. The write is not: once the values are valid, a
 * failure from `save()` is a database or tenancy fault — ours — and wrapping it would report an internal condition
 * to a client as their own bad request. `Money`'s own hydration refusals extend `\InvalidArgumentException` too,
 * which is exactly how a wide catch swallowed our fault as a 422 on the invoice path.
 *
 * **A `\ValueError` IS CAUGHT ALONGSIDE, and that is not defensive padding.** `VatRoundingPoint::from()` raises
 * `\ValueError` — not `\InvalidArgumentException` — for a string the enum does not know, and `\ValueError` reaching
 * Symfony's exception listener is a **500**. The validator on {@see CompanySettingsInput} normally refuses such a
 * value first, so this arm is reachable only for a caller that bypasses validation, which is precisely why it must
 * not be a 500: the two paths would disagree about whose fault the same input is.
 *
 * ## The write and the read-back are ONE transaction
 *
 * Not two, and not one plus a bare return of what was sent. `save()` refuses outside a transaction because the
 * tenant binding is transaction-local; the response is then built from what `forCurrentTenant()` reads back inside
 * that same transaction, so `PUT` and a subsequent `GET` cannot disagree. That is the rule `CLAUDE.md` states for
 * the invoice write path — *a write response is the document READ BACK inside the write transaction* — applied to
 * the one other thing this API writes. It matters less here than for `NUMERIC(21,6)` (a `smallint` does not
 * re-scale), and it is done anyway because the property a client depends on is "what I read next is what I get",
 * not "this particular column happens to round-trip".
 *
 * @implements ProcessorInterface<mixed, CompanySettingsResource>
 */
final readonly class CompanySettingsProcessor implements ProcessorInterface
{
    public function __construct(
        private CompanySettingsRepository $settings,
        private TransactionalScope $transaction,
    ) {}

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
    ): CompanySettingsResource {
        // NOT AN `assert()`, for the reason `CreateInvoiceProcessor` gives: the parameter is `mixed` by interface
        // and API Platform's `input:` is what puts the right class here, so this is a real check rather than a
        // restatement of a type declaration — and only a misconfigured operation can reach it.
        if (!$data instanceof CompanySettingsInput) {
            throw new \LogicException(\sprintf(
                'Expected a %s, got %s. This processor is wired to an operation whose `input:` is something else.',
                CompanySettingsInput::class,
                get_debug_type($data),
            ));
        }

        try {
            $settings = CompanySettings::of(
                NumberPattern::padded($data->numberPatternWidth),
                VatRoundingPoint::from($data->defaultVatRoundingPoint),
            );
        } catch (\InvalidArgumentException|\ValueError $refused) {
            // The original travels as `$previous` so the log keeps the class and the stack while the client gets
            // the message. Wrapped rather than re-thrown because neither type reaching Symfony's listener is a
            // 422 on its own — `\InvalidArgumentException` becomes a 500, and so does `\ValueError`.
            throw new UnprocessableEntityHttpException($refused->getMessage(), $refused);
        }

        return $this->transaction->transactional(function () use ($settings): CompanySettingsResource {
            $this->settings->save($settings);

            // READ BACK, not `$settings`. See the class docblock: the response is what a later GET will answer.
            $stored = $this->settings->forCurrentTenant();

            return new CompanySettingsResource(
                $stored->numberPattern()->width(),
                $stored->defaultVatRoundingPoint()->value,
            );
        });
    }
}
