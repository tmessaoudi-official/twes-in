<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Application;

use App\Module\Customers\Application\CustomerNotFound;
use App\Module\Customers\Domain\CustomerRepository;
use App\Module\Customers\Domain\CustomerSnapshot;
use App\Settings\Application\DocumentFormats;
use App\Settings\Application\ReadSetting;
use App\Settings\Application\SettingContext;
use App\Shared\Application\PdfRenderer;
use App\Shared\Application\PdfRenderingFailed;
use App\Tenancy\Domain\Company;
use App\Tenancy\Domain\EstablishmentRepository;
use App\Tenancy\Domain\SellerSnapshot;
use Symfony\Component\Uid\Uuid;

/**
 * A customer's statement of account as a PDF, worked out and rendered on request: it is a picture of the account on
 * the day it is asked for, so unlike an issued invoice it is never stored. The language is the one the customer's
 * documents are written in, the formats the company's.
 */
final readonly class PrintStatement
{
    public function __construct(
        private StatementOfAccount $statement,
        private CustomerRepository $customers,
        private EstablishmentRepository $establishments,
        private StatementTemplate $template,
        private PdfRenderer $renderer,
        private ReadSetting $settings,
    ) {
    }

    /**
     * @throws CustomerNotFound
     * @throws InvalidStatementPeriod
     * @throws PdfRenderingFailed
     */
    public function pdf(Company $company, Uuid $customerId, ?\DateTimeImmutable $from, ?\DateTimeImmutable $to): PrintedStatement
    {
        $statement = $this->statement->handle($company, $customerId, $from, $to);
        $customer = $this->customers->ofIdInCompany($customerId, $company->getId()) ?? throw new CustomerNotFound();
        $context = new SettingContext($company, customerGroupId: $customer->getGroup()?->getId(), customerId: $customer->getId());
        $print = DocumentFormats::print($this->settings, $context, $company);
        $language = $this->settings->value($context, 'document.language');
        $establishment = $this->establishments->ofCompany($company->getId())[0] ?? throw new \LogicException('A company always has an establishment.');

        return new PrintedStatement(
            \sprintf('statement-%s.pdf', str_replace('/', '-', $customer->getNumber())),
            $this->renderer->render($this->template->html(new StatementPage(
                $company,
                $statement,
                CustomerSnapshot::of($customer),
                SellerSnapshot::of($company, $establishment),
                \is_string($language) ? $language : 'fr',
                $print->dateFormat,
                $print->numberFormat,
            ))),
        );
    }
}
