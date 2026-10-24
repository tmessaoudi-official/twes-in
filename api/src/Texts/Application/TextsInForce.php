<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Texts\Application;

use Symfony\Component\Uid\Uuid;

/**
 * Which company's wording what the API writes itself takes: a printed document or a mail about a company is written for
 * it, and its own texts win there over the installation's; anything else takes the installation's. Named at each place
 * that writes, never read from a request, since the worker writes mails and documents outside any request.
 */
interface TextsInForce
{
    /**
     * @template T
     *
     * @param callable(): T $write what is written, a template rendered or a mail composed
     *
     * @return T
     */
    public function for(?Uuid $companyId, callable $write): mixed;
}
