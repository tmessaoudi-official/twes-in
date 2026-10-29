<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Architecture;

use App\Shared\Infrastructure\Submissions\Submissions;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Routing\RouterInterface;

/**
 * One submission, one write (docs/SPEC.md § 3): every POST the API answers says what a second sending of it does, and
 * the writes that add carry the `Idempotency-Key` guard (`Submissions::ROUTE_DEFAULT`), so a payment, a receipt or a
 * file's rows sent twice are made once. A new POST is red here until its stance is written down.
 */
final class SubmissionStancesTest extends KernelTestCase
{
    /** Adds again on each sending, bounded by nothing a second sending would meet: held to its key. */
    private const string ADDITIVE = 'additive';

    /**
     * Makes a new record on each sending, which the person sees in its list and deletes with its undo; not held to a key
     * until the creates are ruled (docs/SPEC.md § 7).
     */
    private const string CREATE = 'create';

    /** Makes a new record named by a key of its own, which a second sending finds taken and is refused. */
    private const string CREATE_UNIQUE = 'create-unique';

    /** Moves a record from one state to the next, which a second sending finds already moved and is refused. */
    private const string TRANSITION = 'transition';

    /** Sets a value as a whole, which a second sending sets to the same. */
    private const string SETS = 'sets';

    /** The session's own exchanges: signing in and out, a second factor, a passkey, a proof of who is at the screen. */
    private const string SESSION = 'session';

    /** Reads, a preview or a check, and writes nothing. */
    private const string READ = 'read';

    /** Keeps nothing: relays a scan or an echo between a phone and its tab, or logs a browser's error. */
    private const string OTHER = 'other';

    /** Each POST by its path under /api, with what a second sending of it does. */
    private const array STANCES = [
        '/companies/{companyId}/imports/{subject}' => self::ADDITIVE,
        '/companies/{companyId}/invoices/from-delivery-notes' => self::ADDITIVE,
        '/companies/{companyId}/invoices/{invoiceId}/apply-credit' => self::ADDITIVE,
        '/companies/{companyId}/invoices/{invoiceId}/delivery-notes' => self::ADDITIVE,
        '/companies/{companyId}/invoices/{invoiceId}/instruments' => self::ADDITIVE,
        '/companies/{companyId}/invoices/{invoiceId}/overpayments' => self::ADDITIVE,
        '/companies/{companyId}/invoices/{invoiceId}/deliveries' => self::ADDITIVE,
        '/companies/{companyId}/invoices/{invoiceId}/payments' => self::ADDITIVE,
        '/companies/{companyId}/purchase-orders/{purchaseOrderId}/receipts' => self::ADDITIVE,
        '/companies/{companyId}/quotes/{quoteId}/delivery-notes' => self::ADDITIVE,
        '/companies/{companyId}/quotes/{quoteId}/deposit-invoices' => self::ADDITIVE,
        '/companies/{companyId}/stock-movements' => self::ADDITIVE,
        '/companies/{companyId}/stock-receipts' => self::ADDITIVE,
        '/companies/{companyId}/customers/{customerId}/contacts' => self::CREATE,
        '/companies/{companyId}/delivery-notes' => self::CREATE,
        '/companies/{companyId}/expenses' => self::CREATE,
        '/companies/{companyId}/expenses/{expenseId}/attachments' => self::CREATE,
        '/companies/{companyId}/invoices' => self::CREATE,
        '/companies/{companyId}/invoices/{invoiceId}/credit-notes' => self::CREATE,
        '/companies/{companyId}/invoices/{invoiceId}/duplicate' => self::CREATE,
        '/companies/{companyId}/products' => self::CREATE,
        '/companies/{companyId}/products/{productId}/photos' => self::CREATE,
        '/companies/{companyId}/purchase-orders' => self::CREATE,
        '/companies/{companyId}/quotes' => self::CREATE,
        '/companies/{companyId}/quotes/{quoteId}/attachments' => self::CREATE,
        '/companies/{companyId}/recurring-expenses' => self::CREATE,
        '/companies/{companyId}/recurring-invoices' => self::CREATE,
        '/companies/{companyId}/stock-floors/{floorId}/drawing-changes' => self::CREATE,
        '/companies/{companyId}/stock-floors/{floorId}/structures' => self::CREATE,
        '/companies/{companyId}/stock-movements/{movementId}/attachments' => self::CREATE,
        '/platform/legal-texts/{page}/{language}/versions' => self::CREATE,
        '/scan-pairings/{id}/photos' => self::CREATE,
        '/companies' => self::CREATE_UNIQUE,
        '/companies/{companyId}/custom-fields' => self::CREATE_UNIQUE,
        '/companies/{companyId}/customer-groups' => self::CREATE_UNIQUE,
        '/companies/{companyId}/customers' => self::CREATE_UNIQUE,
        '/companies/{companyId}/data-erasures' => self::CREATE_UNIQUE,
        '/companies/{companyId}/establishments' => self::CREATE_UNIQUE,
        '/companies/{companyId}/expense-categories' => self::CREATE_UNIQUE,
        '/companies/{companyId}/price-lists' => self::CREATE_UNIQUE,
        '/companies/{companyId}/product-categories' => self::CREATE_UNIQUE,
        '/companies/{companyId}/roles' => self::CREATE_UNIQUE,
        '/companies/{companyId}/stock-drawings/{drawingId}/repeat' => self::CREATE_UNIQUE,
        '/companies/{companyId}/stock-floors' => self::CREATE_UNIQUE,
        '/companies/{companyId}/stock-locations' => self::CREATE_UNIQUE,
        '/companies/{companyId}/subscription/payments' => self::CREATE_UNIQUE,
        '/companies/{companyId}/tax-components' => self::CREATE_UNIQUE,
        '/companies/{companyId}/units' => self::CREATE_UNIQUE,
        '/companies/{companyId}/vendors' => self::CREATE_UNIQUE,
        '/companies/{companyId}/close-down' => self::TRANSITION,
        '/companies/{companyId}/data-erasures/{erasureId}/undo' => self::TRANSITION,
        '/companies/{companyId}/delivery-notes/{deliveryNoteId}/cancel' => self::TRANSITION,
        '/companies/{companyId}/delivery-notes/{deliveryNoteId}/deliver' => self::TRANSITION,
        '/companies/{companyId}/delivery-notes/{deliveryNoteId}/validate' => self::TRANSITION,
        '/companies/{companyId}/delivery-notes/{deliveryNoteId}/validate-and-deliver' => self::TRANSITION,
        '/companies/{companyId}/expenses/{expenseId}/pay' => self::TRANSITION,
        '/companies/{companyId}/expenses/{expenseId}/record' => self::TRANSITION,
        '/companies/{companyId}/import-runs/{runId}/undo' => self::TRANSITION,
        '/companies/{companyId}/invoices/bulk-payments' => self::TRANSITION,
        '/companies/{companyId}/invoices/{invoiceId}/cancel' => self::TRANSITION,
        '/companies/{companyId}/invoices/{invoiceId}/instruments/{instrumentId}/cash' => self::TRANSITION,
        '/companies/{companyId}/invoices/{invoiceId}/instruments/{instrumentId}/deposit' => self::TRANSITION,
        '/companies/{companyId}/invoices/{invoiceId}/instruments/{instrumentId}/unpaid' => self::TRANSITION,
        '/companies/{companyId}/invoices/{invoiceId}/issue' => self::TRANSITION,
        '/companies/{companyId}/products/{productId}/price-review' => self::TRANSITION,
        '/companies/{companyId}/purchase-orders/{purchaseOrderId}/cancel' => self::TRANSITION,
        '/companies/{companyId}/purchase-orders/{purchaseOrderId}/close' => self::TRANSITION,
        '/companies/{companyId}/purchase-orders/{purchaseOrderId}/send' => self::TRANSITION,
        '/companies/{companyId}/quotes/{quoteId}/accept' => self::TRANSITION,
        '/companies/{companyId}/quotes/{quoteId}/cancel' => self::TRANSITION,
        '/companies/{companyId}/quotes/{quoteId}/invoice' => self::TRANSITION,
        '/companies/{companyId}/quotes/{quoteId}/invoices/{invoiceId}' => self::TRANSITION,
        '/companies/{companyId}/quotes/{quoteId}/refuse' => self::TRANSITION,
        '/companies/{companyId}/quotes/{quoteId}/send' => self::TRANSITION,
        '/companies/{companyId}/record-merges' => self::TRANSITION,
        '/companies/{companyId}/record-removals' => self::TRANSITION,
        '/companies/{companyId}/record-removals/{removalId}/restore' => self::TRANSITION,
        '/companies/{companyId}/reopen' => self::TRANSITION,
        '/companies/{companyId}/scan-pairings/{id}/photos/{photoId}/take' => self::TRANSITION,
        '/companies/{companyId}/stock-move-failures/{failureId}/replay' => self::TRANSITION,
        '/companies/{companyId}/stock-movements/{movementId}/cost' => self::TRANSITION,
        '/platform/payment-declarations/{declarationId}/confirm' => self::TRANSITION,
        '/platform/payment-declarations/{declarationId}/reject' => self::TRANSITION,
        '/companies/{companyId}/customer-screen/lock' => self::SETS,
        '/companies/{companyId}/delivery-notes/{deliveryNoteId}/receiver' => self::SETS,
        '/companies/{companyId}/invoices/{invoiceId}/receiver' => self::SETS,
        '/companies/{companyId}/quotes/{quoteId}/receiver' => self::SETS,
        '/companies/{companyId}/purchase-orders/{purchaseOrderId}/receipts/{receiptId}/receiver' => self::SETS,
        '/companies/{companyId}/expenses/{expenseId}/attachments/{attachmentId}/restore' => self::SETS,
        '/companies/{companyId}/expenses/{expenseId}/withholding-operation' => self::SETS,
        '/companies/{companyId}/logo' => self::SETS,
        '/companies/{companyId}/members' => self::SETS,
        '/companies/{companyId}/members/{userId}/unlock' => self::SETS,
        '/companies/{companyId}/products/{productId}/photos/order' => self::SETS,
        '/companies/{companyId}/products/{productId}/photos/{photoId}/main' => self::SETS,
        '/companies/{companyId}/products/{productId}/photos/{photoId}/restore' => self::SETS,
        '/companies/{companyId}/quotes/{quoteId}/attachments/{attachmentId}/restore' => self::SETS,
        '/companies/{companyId}/scan-pairings' => self::SETS,
        '/companies/{companyId}/scan-pairings/{id}/heartbeat' => self::SETS,
        '/companies/{companyId}/stock-counts' => self::SETS,
        '/companies/{companyId}/stock-floors/{floorId}/drawings' => self::SETS,
        '/companies/{companyId}/stock-lots/{lotId}/release' => self::SETS,
        '/companies/{companyId}/stock-movements/{movementId}/attachments/{attachmentId}/restore' => self::SETS,
        '/companies/{companyId}/support-access' => self::SETS,
        '/me/company' => self::SETS,
        '/me/notifications/read-all' => self::SETS,
        '/me/notifications/{id}/read' => self::SETS,
        '/notification-preferences/stop' => self::SETS,
        '/platform/accounts/{userId}/deactivate' => self::SETS,
        '/platform/accounts/{userId}/end-sessions' => self::SETS,
        '/platform/accounts/{userId}/reactivate' => self::SETS,
        '/platform/accounts/{userId}/unlock' => self::SETS,
        '/platform/companies/{companyId}/approve' => self::SETS,
        '/platform/companies/{companyId}/owners' => self::SETS,
        '/platform/companies/{companyId}/reject' => self::SETS,
        '/platform/legal-texts/{page}/{language}/validate' => self::SETS,
        '/auth/demo' => self::SESSION,
        '/auth/login' => self::SESSION,
        '/auth/logout' => self::SESSION,
        '/auth/mfa/authenticator/replacement' => self::SESSION,
        '/auth/mfa/authenticator/replacement/confirm' => self::SESSION,
        '/auth/mfa/enrolment' => self::SESSION,
        '/auth/mfa/enrolment/confirm' => self::SESSION,
        '/auth/mfa/passkey-login' => self::SESSION,
        '/auth/mfa/passkey-login/options' => self::SESSION,
        '/auth/mfa/passkeys' => self::SESSION,
        '/auth/mfa/passkeys/options' => self::SESSION,
        '/auth/mfa/recovery-codes' => self::SESSION,
        '/auth/mfa/recovery-codes/passkey' => self::SESSION,
        '/auth/mfa/recovery-codes/passkey/options' => self::SESSION,
        '/auth/mfa/verify' => self::SESSION,
        '/auth/passkey-sign-in' => self::SESSION,
        '/auth/passkey-sign-in/options' => self::SESSION,
        '/auth/password/forgot' => self::SESSION,
        '/auth/password/reset' => self::SESSION,
        '/auth/step-up' => self::SESSION,
        '/auth/step-up/passkey' => self::SESSION,
        '/auth/step-up/passkey/options' => self::SESSION,
        '/invitations/{token}/accept' => self::SESSION,
        '/scan-pairings/claim' => self::SESSION,
        '/scan-pairings/{id}/realtime-token' => self::SESSION,
        '/signup' => self::SESSION,
        '/signup/{token}/complete' => self::SESSION,
        '/companies/{companyId}/delivery-notes/preview' => self::READ,
        '/companies/{companyId}/delivery-notes/{deliveryNoteId}/preview' => self::READ,
        '/companies/{companyId}/invoices/bulk-check' => self::READ,
        '/companies/{companyId}/invoices/from-delivery-notes/check' => self::READ,
        '/companies/{companyId}/invoices/preview' => self::READ,
        '/companies/{companyId}/invoices/{invoiceId}/preview' => self::READ,
        '/companies/{companyId}/price-preview' => self::READ,
        '/companies/{companyId}/quotes/preview' => self::READ,
        '/companies/{companyId}/quotes/{quoteId}/preview' => self::READ,
        '/client-errors' => self::OTHER,
        '/companies/{companyId}/scan-pairings/{id}/echo' => self::OTHER,
        '/scan-pairings/{id}/choices' => self::OTHER,
        '/scan-pairings/{id}/scans' => self::OTHER,
    ];

    public function testEveryPostSaysWhatASecondSendingDoesAndEveryWriteThatAddsIsHeldToItsKey(): void
    {
        self::bootKernel();
        $found = [];
        $wrong = [];
        foreach (static::getContainer()->get(RouterInterface::class)->getRouteCollection()->all() as $name => $route) {
            $methods = $route->getMethods();
            if (!str_starts_with($route->getPath(), '/api/') || ([] !== $methods && !\in_array('POST', $methods, true))) {
                continue;
            }
            if ([] === $methods) {
                $wrong[] = "$name answers every method: name the methods it takes";

                continue;
            }
            $path = (string) preg_replace('/\.\{_format\}$/', '', substr($route->getPath(), \strlen('/api')));
            $found[] = $path;
            $stance = self::STANCES[$path] ?? null;
            $held = true === $route->getDefault(Submissions::ROUTE_DEFAULT);
            if (null === $stance) {
                $wrong[] = "POST $path: say here what a second sending of it does";
            } elseif (self::ADDITIVE === $stance && !$held) {
                $wrong[] = "POST $path adds on each sending: give it the route default Submissions::ROUTE_DEFAULT";
            } elseif (self::ADDITIVE !== $stance && $held) {
                $wrong[] = "POST $path is held to a key but listed as $stance: list it as additive, or drop the default";
            }
        }
        foreach (array_diff(array_keys(self::STANCES), $found) as $gone) {
            $wrong[] = "POST $gone is listed but no longer answered: strike it off";
        }

        self::assertGreaterThan(130, \count($found), 'the API answers some 140 POSTs: a count reading fewer has stopped finding them');
        self::assertSame([], $wrong, implode("\n", $wrong));
    }
}
