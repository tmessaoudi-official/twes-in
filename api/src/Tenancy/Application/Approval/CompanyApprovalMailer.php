<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Application\Approval;

/** What a company's owners are told of an operator's decision on it, each in its own words. */
interface CompanyApprovalMailer
{
    /** Opened for the first time. */
    public function approved(CompanyApprovedMail $mail): void;

    /** Opened again after a suspension: its access is back. */
    public function restored(CompanyApprovedMail $mail): void;

    /** Closed by an operator after it was open, with the reason and whom to ask. */
    public function suspended(CompanyDecisionMail $mail): void;

    /** Never opened: the operator did not accept it, with the reason and whom to ask. */
    public function refused(CompanyDecisionMail $mail): void;
}
