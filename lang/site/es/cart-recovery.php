<?php
/**
 * CloudHost247 — site content catalog fragment: Spanish (es).
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
    'recover_unsubscribed_title'        => 'Se ha cancelado su suscripción',
    'recover_unsubscribed_body'         => 'Ya no recibirá más recordatorios de carrito abandonado de nuestra parte. Su cuenta y sus pedidos existentes no se ven afectados.',
    'recover_unsubscribe_invalid_title' => 'Este enlace de baja ya no es válido',
    'recover_unsubscribe_invalid_body'  => 'Puede que el enlace ya se haya usado o haya caducado. Si sigue recibiendo recordatorios, contacte con nuestro equipo de soporte.',
    'recover_expired_title'             => 'Este enlace de carrito ha caducado',
    'recover_expired_body'              => 'Por seguridad, los enlaces de carrito guardado solo son válidos durante un tiempo limitado. Puede iniciar un pedido nuevo desde nuestra tienda en cualquier momento.',
    'recover_converted_title'           => 'Este carrito ya se ha pedido',
    'recover_converted_body'            => 'Ya se realizó un pedido con este carrito, así que no queda nada por restaurar. Puede revisarlo en su área de cliente.',
    'recover_unavailable_title'         => 'Este carrito guardado ya no está disponible',
    'recover_unavailable_body'          => 'El carrito al que se refiere se ha vaciado o cerrado desde entonces. Cree un carrito nuevo desde nuestra tienda.',
    'recover_invalid_title'             => 'Este enlace de carrito no es válido',
    'recover_invalid_body'              => 'No hemos podido restaurar ningún carrito desde este enlace. Cree un carrito nuevo desde nuestra tienda.',
    'recover_error_title'               => 'Algo ha ido mal',
    'recover_error_body'                => 'No hemos podido procesar ese enlace en este momento. Inténtelo de nuevo en unos minutos o cree un carrito nuevo desde nuestra tienda.',
    'recover_return_to_store'           => 'Volver a la tienda',
);
