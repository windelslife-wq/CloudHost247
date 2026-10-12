<?php
/**
 * CloudHost247 — site content catalog fragment: French (fr).
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
    'recover_unsubscribed_title'        => 'Vous êtes désabonné',
    'recover_unsubscribed_body'         => 'Vous ne recevrez plus de rappels de panier abandonné de notre part. Votre compte et vos commandes en cours ne sont pas affectés.',
    'recover_unsubscribe_invalid_title' => 'Ce lien de désabonnement n’est plus valide',
    'recover_unsubscribe_invalid_body'  => 'Le lien a peut-être déjà été utilisé ou a expiré. Si vous continuez à recevoir des rappels, contactez notre équipe d’assistance.',
    'recover_expired_title'             => 'Ce lien de panier a expiré',
    'recover_expired_body'              => 'Pour des raisons de sécurité, les liens de panier enregistré ne sont valables que pendant une durée limitée. Vous pouvez passer une nouvelle commande depuis notre boutique à tout moment.',
    'recover_converted_title'           => 'Ce panier a déjà été commandé',
    'recover_converted_body'            => 'Une commande a déjà été passée pour ce panier : il n’y a plus rien à restaurer. Vous pouvez la consulter dans votre espace client.',
    'recover_unavailable_title'         => 'Ce panier enregistré n’est plus disponible',
    'recover_unavailable_body'          => 'Le panier concerné a depuis été vidé ou fermé. Merci de créer un nouveau panier depuis notre boutique.',
    'recover_invalid_title'             => 'Ce lien de panier n’est pas valide',
    'recover_invalid_body'              => 'Nous n’avons pas pu restaurer de panier à partir de ce lien. Merci de créer un nouveau panier depuis notre boutique.',
    'recover_error_title'               => 'Une erreur est survenue',
    'recover_error_body'                => 'Nous n’avons pas pu traiter ce lien pour le moment. Réessayez dans quelques instants ou créez un nouveau panier depuis notre boutique.',
    'recover_return_to_store'           => 'Retour à la boutique',
);
