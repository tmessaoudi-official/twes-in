<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Infrastructure\Http;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The time a request whose work grows with what a person handed in or chose is given: an import, its undo and the
 * restore of what the undo took each write every row of the file inside the request, and a printed selection renders
 * each draft it holds. 4,100 products took 50 s to import and 6 s to undo, 100 drafts 37 s to print, on a developer's
 * machine, where a shop's small computer may take several times that, past PHP's 30 and nginx's 60.
 * PHP's limit (none is 0) is lifted to app.long_request.max_seconds, never shortened; infra/web/nginx.conf waits as
 * long on every route whose action calls lift(), which LongRequestRoutesTest finds through the router.
 */
final readonly class LongRequest
{
    public function __construct(
        #[Autowire(param: 'app.long_request.max_seconds')]
        private int $maxSeconds,
    ) {
    }

    public function lift(): void
    {
        $limit = (int) \ini_get('max_execution_time');
        if (0 !== $limit && $limit < $this->maxSeconds) {
            set_time_limit($this->maxSeconds);
        }
    }
}
