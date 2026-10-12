<?php
/**
 * CloudHost247 — site content catalog fragment: Portuguese (Brazil) (pt-BR).
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
    'recover_unsubscribed_title'        => 'Você cancelou a inscrição',
    'recover_unsubscribed_body'         => 'Você não receberá mais lembretes de carrinho abandonado da nossa parte. Sua conta e seus pedidos existentes não são afetados.',
    'recover_unsubscribe_invalid_title' => 'Este link de cancelamento não é mais válido',
    'recover_unsubscribe_invalid_body'  => 'O link pode já ter sido usado ou ter expirado. Se você continuar recebendo lembretes, entre em contato com nossa equipe de suporte.',
    'recover_expired_title'             => 'Este link de carrinho expirou',
    'recover_expired_body'              => 'Por segurança, os links de carrinho salvo são válidos apenas por tempo limitado. Você pode iniciar um novo pedido na nossa loja a qualquer momento.',
    'recover_converted_title'           => 'Este carrinho já foi pedido',
    'recover_converted_body'            => 'Já foi feito um pedido para este carrinho, então não há nada a restaurar. Você pode consultá-lo na sua área de cliente.',
    'recover_unavailable_title'         => 'Este carrinho salvo não está mais disponível',
    'recover_unavailable_body'          => 'O carrinho ao qual ele se refere foi esvaziado ou fechado desde então. Inicie um novo carrinho na nossa loja.',
    'recover_invalid_title'             => 'Este link de carrinho não é válido',
    'recover_invalid_body'              => 'Não conseguimos restaurar um carrinho a partir deste link. Inicie um novo carrinho na nossa loja.',
    'recover_error_title'               => 'Algo deu errado',
    'recover_error_body'                => 'Não conseguimos processar esse link agora. Tente novamente em instantes ou inicie um novo carrinho na nossa loja.',
    'recover_return_to_store'           => 'Voltar à loja',
);
