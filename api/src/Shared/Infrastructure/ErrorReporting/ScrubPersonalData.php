<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Infrastructure\ErrorReporting;

use App\Shared\Infrastructure\Logging\SecretTokens;
use Sentry\Event;
use Sentry\EventHint;

/**
 * What an error report keeps before it leaves for the error tracker (docs/START.md § 15): where it happened and in
 * what shape, never who or with what. As the audit trail keeps field names and never values, a report keeps the
 * request's method and path (a link's token masked in it, SecretTokens), the exception's type, its frames and its message with every value in it masked: an
 * address, anything quoted, any run of six digits or more (a phone, an account, a tax number), and the values a
 * database error details after its column names (`Key (name)=(…)`, `Failing row contains (…)`), which hold whatever
 * the row held, parentheses included, so they are cut to the end of the detail. The person, the body,
 * the query, the cookies, the headers, the client address, the breadcrumbs and the frames' variables are dropped.
 * Named in config/packages/sentry.yaml as the SDK's before_send.
 */
final class ScrubPersonalData
{
    private const array MASKS = [
        '/(\bKey \([^)]*\)=)\(.*?\)(?= already exists| is not present| is still referenced| conflicts with|\.?[ \t]*$)/mu' => '$1([values])',
        '/(\bFailing row contains )\(.*\)(?=\.?[ \t]*$)/mu' => '$1([values])',
        '/[^\s@<>"\'«»()]+@[^\s@<>"\'«»()]+\.[^\s@<>"\'«»()]+/u' => '[address]',
        '/"[^"]*"/u' => '"[value]"',
        '/«\s*[^»]*?\s*»/u' => '« [value] »',
        "/'[^']*'/u" => "'[value]'",
        '/\d{6,}/' => '[number]',
    ];

    public function __invoke(Event $event, ?EventHint $hint): Event
    {
        $request = $event->getRequest();
        $kept = [];
        if (\is_string($request['url'] ?? null)) {
            $kept['url'] = SecretTokens::mask(explode('?', explode('#', $request['url'], 2)[0], 2)[0]);
        }
        if (\is_string($request['method'] ?? null)) {
            $kept['method'] = $request['method'];
        }
        $event->setRequest($kept);
        $event->setUser(null);
        $event->setExtra([]);
        $event->setBreadcrumb([]);

        $message = $event->getMessage();
        if (null !== $message) {
            $formatted = $event->getMessageFormatted();
            $event->setMessage(self::mask($message), [], null === $formatted ? null : self::mask($formatted));
        }
        foreach ($event->getExceptions() as $exception) {
            $exception->setValue(self::mask($exception->getValue()));
            foreach ($exception->getStacktrace()?->getFrames() ?? [] as $frame) {
                $frame->setVars([]);
            }
        }

        return $event;
    }

    /** The text with every link's token, address, quoted value and long run of digits masked, as a report keeps it. */
    public static function mask(string $text): string
    {
        return preg_replace(array_keys(self::MASKS), array_values(self::MASKS), SecretTokens::mask($text)) ?? '[unreadable]';
    }
}
