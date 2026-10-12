<?php
/**
 * CloudHost247 — site content catalog fragment: German (de).
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
    'recover_unsubscribed_title'        => 'Abmeldung erfolgreich',
    'recover_unsubscribed_body'         => 'Sie erhalten von uns keine weiteren Erinnerungen an abgebrochene Warenkörbe. Ihr Konto und Ihre bestehenden Bestellungen sind nicht betroffen.',
    'recover_unsubscribe_invalid_title' => 'Dieser Abmeldelink ist nicht mehr gültig',
    'recover_unsubscribe_invalid_body'  => 'Der Link wurde möglicherweise bereits verwendet oder ist abgelaufen. Wenn Sie weiterhin Erinnerungen erhalten, wenden Sie sich bitte an unser Support-Team.',
    'recover_expired_title'             => 'Dieser Warenkorb-Link ist abgelaufen',
    'recover_expired_body'              => 'Aus Sicherheitsgründen sind Links zu gespeicherten Warenkörben nur für einen begrenzten Zeitraum gültig. Sie können jederzeit eine neue Bestellung in unserem Shop beginnen.',
    'recover_converted_title'           => 'Dieser Warenkorb wurde bereits bestellt',
    'recover_converted_body'            => 'Für diesen Warenkorb liegt bereits eine Bestellung vor, daher gibt es nichts mehr wiederherzustellen. Sie können sie in Ihrem Kundenbereich einsehen.',
    'recover_unavailable_title'         => 'Dieser gespeicherte Warenkorb ist nicht mehr verfügbar',
    'recover_unavailable_body'          => 'Der betreffende Warenkorb wurde inzwischen geleert oder geschlossen. Bitte starten Sie einen neuen Warenkorb in unserem Shop.',
    'recover_invalid_title'             => 'Dieser Warenkorb-Link ist nicht gültig',
    'recover_invalid_body'              => 'Wir konnten aus diesem Link keinen Warenkorb wiederherstellen. Bitte starten Sie einen neuen Warenkorb in unserem Shop.',
    'recover_error_title'               => 'Etwas ist schiefgelaufen',
    'recover_error_body'                => 'Wir konnten diesen Link momentan nicht verarbeiten. Bitte versuchen Sie es in Kürze erneut oder starten Sie einen neuen Warenkorb in unserem Shop.',
    'recover_return_to_store'           => 'Zurück zum Shop',
);
