<?php
/**
 * Small translation adapter for CloudHost247-authored labels.
 *
 * WHMCS remains the source of truth for the active language and all core
 * billing/support translations. This adapter only fills the strings owned by
 * the overlay, avoiding a second language/session system. It intentionally
 * keeps English fallbacks so an absent translation never breaks a page.
 *
 * @package Chs\Core
 */
namespace Chs\Core;

class I18n
{
    /** @var array<string,array<string,string>> */
    private static $catalogue = [
        'de' => [
            'sign_in_required' => 'Anmeldung erforderlich', 'my_digital_services' => 'Meine digitalen Dienste',
            'domain_valuation' => 'Domainbewertung', 'domain_auctions' => 'Domain-Auktionen',
            'my_watchlist' => 'Meine Beobachtungsliste', 'sell_a_domain' => 'Domain verkaufen',
            'discount_domain_club' => 'Discount Domain Club', 'my_service_requests' => 'Meine Serviceanfragen',
            'new_service_request' => 'Neue Serviceanfrage', 'my_logo_projects' => 'Meine Logo-Projekte',
            'logo_studio' => 'Logo Studio', 'ai_website_builder' => 'KI-Website-Builder',
            'unified_inbox' => 'Einheitlicher Posteingang', 'conversation' => 'Konversation',
            'notifications' => 'Benachrichtigungen', 'dashboard' => 'Übersicht',
            'domain_auctions_nav' => 'Domain-Auktionen', 'auction_watchlist' => 'Auktions-Beobachtungsliste',
            'service_requests' => 'Serviceanfragen', 'logo_projects' => 'Logo-Projekte',
        ],
        'fr' => [
            'sign_in_required' => 'Connexion requise', 'my_digital_services' => 'Mes services numériques',
            'domain_valuation' => 'Évaluation de domaine', 'domain_auctions' => 'Enchères de domaines',
            'my_watchlist' => 'Ma liste de suivi', 'sell_a_domain' => 'Vendre un domaine',
            'discount_domain_club' => 'Club Domain Discount', 'my_service_requests' => 'Mes demandes de service',
            'new_service_request' => 'Nouvelle demande de service', 'my_logo_projects' => 'Mes projets de logo',
            'logo_studio' => 'Studio de logo', 'ai_website_builder' => 'Créateur de site avec IA',
            'unified_inbox' => 'Boîte de réception unifiée', 'conversation' => 'Conversation',
            'notifications' => 'Notifications', 'dashboard' => 'Tableau de bord',
            'domain_auctions_nav' => 'Enchères de domaines', 'auction_watchlist' => 'Liste de suivi des enchères',
            'service_requests' => 'Demandes de service', 'logo_projects' => 'Projets de logo',
        ],
        'es' => [
            'sign_in_required' => 'Inicio de sesión requerido', 'my_digital_services' => 'Mis servicios digitales',
            'domain_valuation' => 'Valoración de dominio', 'domain_auctions' => 'Subastas de dominios',
            'my_watchlist' => 'Mi lista de seguimiento', 'sell_a_domain' => 'Vender un dominio',
            'discount_domain_club' => 'Club de descuentos de dominios', 'my_service_requests' => 'Mis solicitudes de servicio',
            'new_service_request' => 'Nueva solicitud de servicio', 'my_logo_projects' => 'Mis proyectos de logotipos',
            'logo_studio' => 'Estudio de logotipos', 'ai_website_builder' => 'Creador de sitios con IA',
            'unified_inbox' => 'Bandeja de entrada unificada', 'conversation' => 'Conversación',
            'notifications' => 'Notificaciones', 'dashboard' => 'Panel de control',
            'domain_auctions_nav' => 'Subastas de dominios', 'auction_watchlist' => 'Lista de seguimiento de subastas',
            'service_requests' => 'Solicitudes de servicio', 'logo_projects' => 'Proyectos de logotipos',
        ],
        'pt' => [
            'sign_in_required' => 'É necessário iniciar sessão', 'my_digital_services' => 'Os meus serviços digitais',
            'domain_valuation' => 'Avaliação de domínio', 'domain_auctions' => 'Leilões de domínios',
            'my_watchlist' => 'A minha lista de acompanhamento', 'sell_a_domain' => 'Vender um domínio',
            'discount_domain_club' => 'Clube de descontos de domínios', 'my_service_requests' => 'Os meus pedidos de serviço',
            'new_service_request' => 'Novo pedido de serviço', 'my_logo_projects' => 'Os meus projetos de logótipo',
            'logo_studio' => 'Estúdio de logótipos', 'ai_website_builder' => 'Criador de sites com IA',
            'unified_inbox' => 'Caixa de entrada unificada', 'conversation' => 'Conversa',
            'notifications' => 'Notificações', 'dashboard' => 'Painel',
            'domain_auctions_nav' => 'Leilões de domínios', 'auction_watchlist' => 'Lista de acompanhamento de leilões',
            'service_requests' => 'Pedidos de serviço', 'logo_projects' => 'Projetos de logótipos',
        ],
        'zh' => [
            'sign_in_required' => '需要登录', 'my_digital_services' => '我的数字服务',
            'domain_valuation' => '域名估值', 'domain_auctions' => '域名拍卖',
            'my_watchlist' => '我的关注列表', 'sell_a_domain' => '出售域名',
            'discount_domain_club' => '域名优惠俱乐部', 'my_service_requests' => '我的服务请求',
            'new_service_request' => '新服务请求', 'my_logo_projects' => '我的标志项目',
            'logo_studio' => '标志工作室', 'ai_website_builder' => 'AI 网站构建器',
            'unified_inbox' => '统一收件箱', 'conversation' => '对话',
            'notifications' => '通知', 'dashboard' => '控制面板',
            'domain_auctions_nav' => '域名拍卖', 'auction_watchlist' => '拍卖关注列表',
            'service_requests' => '服务请求', 'logo_projects' => '标志项目',
        ],
        'ar' => [
            'sign_in_required' => 'تسجيل الدخول مطلوب', 'my_digital_services' => 'خدماتي الرقمية',
            'domain_valuation' => 'تقييم النطاق', 'domain_auctions' => 'مزادات النطاقات',
            'my_watchlist' => 'قائمة المتابعة', 'sell_a_domain' => 'بيع نطاق',
            'discount_domain_club' => 'نادي خصومات النطاقات', 'my_service_requests' => 'طلبات الخدمة',
            'new_service_request' => 'طلب خدمة جديد', 'my_logo_projects' => 'مشاريع الشعارات',
            'logo_studio' => 'استوديو الشعارات', 'ai_website_builder' => 'منشئ المواقع بالذكاء الاصطناعي',
            'unified_inbox' => 'صندوق الوارد الموحد', 'conversation' => 'محادثة',
            'notifications' => 'الإشعارات', 'dashboard' => 'لوحة التحكم',
            'domain_auctions_nav' => 'مزادات النطاقات', 'auction_watchlist' => 'قائمة متابعة المزادات',
            'service_requests' => 'طلبات الخدمة', 'logo_projects' => 'مشاريع الشعارات',
        ],
        'fa' => [
            'sign_in_required' => 'ورود لازم است', 'my_digital_services' => 'خدمات دیجیتال من',
            'domain_valuation' => 'ارزش‌گذاری دامنه', 'domain_auctions' => 'مزایده دامنه‌ها',
            'my_watchlist' => 'فهرست پیگیری من', 'sell_a_domain' => 'فروش دامنه',
            'discount_domain_club' => 'باشگاه تخفیف دامنه', 'my_service_requests' => 'درخواست‌های خدمات من',
            'new_service_request' => 'درخواست خدمات جدید', 'my_logo_projects' => 'پروژه‌های لوگوی من',
            'logo_studio' => 'استودیوی لوگو', 'ai_website_builder' => 'سازنده وب‌سایت هوش مصنوعی',
            'unified_inbox' => 'صندوق ورودی یکپارچه', 'conversation' => 'گفت‌وگو',
            'notifications' => 'اعلان‌ها', 'dashboard' => 'داشبورد',
            'domain_auctions_nav' => 'مزایده دامنه‌ها', 'auction_watchlist' => 'فهرست پیگیری مزایده',
            'service_requests' => 'درخواست‌های خدمات', 'logo_projects' => 'پروژه‌های لوگو',
        ],
        'he' => [
            'sign_in_required' => 'נדרשת התחברות', 'my_digital_services' => 'השירותים הדיגיטליים שלי',
            'domain_valuation' => 'הערכת דומיין', 'domain_auctions' => 'מכירות פומביות של דומיינים',
            'my_watchlist' => 'רשימת המעקב שלי', 'sell_a_domain' => 'מכירת דומיין',
            'discount_domain_club' => 'מועדון הנחות לדומיינים', 'my_service_requests' => 'בקשות השירות שלי',
            'new_service_request' => 'בקשת שירות חדשה', 'my_logo_projects' => 'פרויקטי הלוגו שלי',
            'logo_studio' => 'סטודיו לוגו', 'ai_website_builder' => 'בונה אתרים מבוסס בינה מלאכותית',
            'unified_inbox' => 'תיבת דואר מאוחדת', 'conversation' => 'שיחה',
            'notifications' => 'התראות', 'dashboard' => 'לוח בקרה',
            'domain_auctions_nav' => 'מכירות פומביות של דומיינים', 'auction_watchlist' => 'רשימת מעקב למכירות',
            'service_requests' => 'בקשות שירות', 'logo_projects' => 'פרויקטי לוגו',
        ],
    ];

    public static function language()
    {
        $candidates = [];
        if (isset($_SESSION['Language'])) $candidates[] = $_SESSION['Language'];
        if (isset($_COOKIE['WHMCSLanguage'])) $candidates[] = $_COOKIE['WHMCSLanguage'];
        if (isset($_GET['language'])) $candidates[] = $_GET['language'];
        if (isset($GLOBALS['language'])) $candidates[] = $GLOBALS['language'];
        foreach ($candidates as $candidate) {
            $code = strtolower((string) $candidate);
            $code = str_replace('_', '-', $code);
            if (strpos($code, 'portuguese') === 0) return 'pt';
            if (strpos($code, 'chinese') === 0) return 'zh';
            if (strpos($code, 'arabic') === 0) return 'ar';
            if (strpos($code, 'farsi') === 0 || strpos($code, 'persian') === 0) return 'fa';
            if (strpos($code, 'hebrew') === 0) return 'he';
            $code = substr($code, 0, 2);
            if (isset(self::$catalogue[$code])) return $code;
        }
        return 'en';
    }

    public static function text($key, $fallback)
    {
        // Prefer the language already loaded by WHMCS/HostX. The override
        // files use $_LANG, while newer WHMCS versions expose Lang::trans().
        $whmcsKey = 'ch247_' . $key;
        global $_LANG;
        if (isset($_LANG) && is_array($_LANG) && isset($_LANG[$whmcsKey]) && $_LANG[$whmcsKey] !== '') {
            return (string) $_LANG[$whmcsKey];
        }
        if (class_exists('WHMCS\\Language\\Lang') && method_exists('WHMCS\\Language\\Lang', 'trans')) {
            try {
                $translated = \WHMCS\Language\Lang::trans($whmcsKey);
                if ($translated !== $whmcsKey && $translated !== '') return (string) $translated;
            } catch (\Throwable $e) {
                // An overlay must retain its fallback if an older WHMCS build
                // does not expose this translation key through Lang::trans().
            }
        }
        $language = self::language();
        return isset(self::$catalogue[$language][$key]) ? self::$catalogue[$language][$key] : $fallback;
    }
}
