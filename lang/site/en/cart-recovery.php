<?php
/**
 * CloudHost247 — site content catalog fragment: English (en).
 *
 * The abandoned-cart recovery and unsubscribe pages
 * (`modules/addons/cloudhost247_cart_recovery/recover.php`). Those pages render
 * their own document — no client-area hook runs there — so they used to be
 * hardcoded English whatever language the customer was reading the site in.
 *
 * The English values reproduce the previous in-source wording exactly; a test
 * pins the catalogue against the fallback list in
 * `CloudHost247\CartRecovery\Locale` so the two cannot drift apart.
 *
 * @package    CloudHost247
 * @copyright  Copyright (c) CloudHost247 Isc
 * @license    Private
 */

return array(
    'recover_unsubscribed_title'        => 'You have been unsubscribed',
    'recover_unsubscribed_body'         => 'You will not receive any further abandoned-cart reminders from us. Your account and any existing orders are unaffected.',
    'recover_unsubscribe_invalid_title' => 'This unsubscribe link is no longer valid',
    'recover_unsubscribe_invalid_body'  => 'The link may already have been used or has expired. If you keep receiving reminders, please contact our support team.',
    'recover_expired_title'             => 'This cart link has expired',
    'recover_expired_body'              => 'For security, saved-cart links are only valid for a limited time. You can start a new order from our store at any time.',
    'recover_converted_title'           => 'This cart has already been ordered',
    'recover_converted_body'            => 'An order was already placed for this cart, so there is nothing left to restore. You can review it in your client area.',
    'recover_unavailable_title'         => 'This saved cart is no longer available',
    'recover_unavailable_body'          => 'The cart it refers to has since been emptied or closed. Please start a new cart from our store.',
    'recover_invalid_title'             => 'This cart link is not valid',
    'recover_invalid_body'              => 'We could not restore a cart from this link. Please start a new cart from our store.',
    'recover_error_title'               => 'Something went wrong',
    'recover_error_body'                => 'We could not process that link right now. Please try again shortly or start a new cart from our store.',
    'recover_return_to_store'           => 'Return to the store',
);
