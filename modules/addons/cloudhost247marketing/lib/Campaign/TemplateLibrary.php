<?php
/**
 * The shipped template library.
 *
 * Each entry is built from the same block primitives the drag-and-drop builder
 * produces, so "start from a template" and "start from blank" land the operator
 * in exactly the same editor with the same capabilities. Templates are seeded
 * on activation and marked is_system = 1; an operator can duplicate and edit
 * them but the originals are restored by re-running the seed.
 *
 * @package Ch247Mkt
 */

namespace Ch247Mkt\Campaign;

class TemplateLibrary
{
    private const ACCENT = '#1a73e8';
    private const MUTED  = '#5f6771';

    /** @return array<string,array{name:string,category:string,description:string,design:array}> */
    public static function all()
    {
        return [
            'welcome'                  => self::welcome(),
            'newsletter'               => self::newsletter(),
            'hosting-promotion'        => self::hostingPromotion(),
            'domain-promotion'         => self::domainPromotion(),
            'vps-promotion'            => self::vpsPromotion(),
            'rdp-promotion'            => self::rdpPromotion(),
            'new-product'              => self::newProduct(),
            'special-offer'            => self::specialOffer(),
            'discount-coupon'          => self::discountCoupon(),
            'maintenance-notification' => self::maintenance(),
            'service-announcement'     => self::announcement(),
            'holiday-campaign'         => self::holiday(),
            'abandoned-cart'           => self::abandonedCart(),
            'blank'                    => self::blank(),
        ];
    }

    /* ------------------------------------------------------- templates -- */

    protected static function welcome()
    {
        return [
            'name' => 'Welcome', 'category' => 'lifecycle',
            'description' => 'Sent after signup. Introduces the account and points at the first useful action.',
            'design' => self::design([
                self::header(),
                Designer::row('col-1', [[
                    Designer::block('heading', ['text' => 'Welcome aboard, {{first_name}}', 'fontSize' => 28]),
                    Designer::block('text', ['html' => '<p>Thanks for choosing us. Your account is ready and your client area is the place to manage everything — services, invoices, domains and support.</p>']),
                    Designer::block('button', ['text' => 'Go to my client area', 'href' => '', 'align' => 'left']),
                ]]),
                Designer::row('col-2', [
                    [Designer::block('heading', ['text' => 'Set up in minutes', 'level' => 'h3', 'fontSize' => 17]), Designer::block('text', ['html' => '<p>Point your domain, install an app and get online the same day.</p>'])],
                    [Designer::block('heading', ['text' => 'We are here 24/7', 'level' => 'h3', 'fontSize' => 17]), Designer::block('text', ['html' => '<p>Open a ticket any time and a human replies, not a bot.</p>'])],
                ], ['paddingTop' => 0]),
                self::footer(),
            ]),
        ];
    }

    protected static function newsletter()
    {
        return [
            'name' => 'Newsletter', 'category' => 'newsletter',
            'description' => 'Regular multi-story update with a lead article and two short items.',
            'design' => self::design([
                self::header(),
                Designer::row('col-1', [[
                    Designer::block('heading', ['text' => 'This month at CloudHost247']),
                    Designer::block('text', ['html' => '<p>Hi {{first_name}}, here is what shipped, what changed and what is worth your attention this month.</p>']),
                    Designer::block('divider', []),
                ]]),
                Designer::row('image-text', [
                    [Designer::block('image', ['src' => '', 'alt' => 'Lead story'])],
                    [
                        Designer::block('heading', ['text' => 'Lead story headline', 'level' => 'h3', 'fontSize' => 19]),
                        Designer::block('text', ['html' => '<p>One short paragraph explaining why this matters to the reader.</p>']),
                        Designer::block('button', ['text' => 'Read more', 'align' => 'left', 'paddingY' => 10, 'paddingX' => 20, 'fontSize' => 14]),
                    ],
                ], ['paddingTop' => 0]),
                Designer::row('col-2', [
                    [Designer::block('heading', ['text' => 'Second item', 'level' => 'h3', 'fontSize' => 17]), Designer::block('text', ['html' => '<p>A sentence or two.</p>'])],
                    [Designer::block('heading', ['text' => 'Third item', 'level' => 'h3', 'fontSize' => 17]), Designer::block('text', ['html' => '<p>A sentence or two.</p>'])],
                ], ['paddingTop' => 0]),
                self::footer(),
            ]),
        ];
    }

    protected static function hostingPromotion()
    {
        return [
            'name' => 'Hosting promotion', 'category' => 'promotion',
            'description' => 'Plan-led promotion for shared or cPanel hosting with a pricing block.',
            'design' => self::design([
                self::header(),
                Designer::row('full-width', [[
                    Designer::block('heading', ['text' => 'Fast, managed hosting from day one', 'align' => 'center', 'fontSize' => 28, 'color' => '#ffffff']),
                    Designer::block('text', ['html' => '<p>NVMe storage, free SSL, daily backups and a 99.9% uptime commitment.</p>', 'align' => 'center', 'color' => '#dbe7ff']),
                ]], ['backgroundColor' => '#0b2545', 'paddingTop' => 40, 'paddingBottom' => 40, 'paddingLeft' => 28, 'paddingRight' => 28]),
                Designer::row('col-2', [
                    [Designer::block('pricing', ['plan' => 'Starter', 'price' => '$3', 'period' => '/month', 'features' => "1 website\n10 GB NVMe\nFree SSL", 'buttonText' => 'Choose Starter'])],
                    [Designer::block('pricing', ['plan' => 'Business', 'price' => '$9', 'period' => '/month', 'features' => "Unlimited sites\n100 GB NVMe\nDaily backups", 'buttonText' => 'Choose Business'])],
                ]),
                self::footer(),
            ]),
        ];
    }

    protected static function domainPromotion()
    {
        return [
            'name' => 'Domain promotion', 'category' => 'promotion',
            'description' => 'TLD pricing push with a search call-to-action.',
            'design' => self::design([
                self::header(),
                Designer::row('col-1', [[
                    Designer::block('heading', ['text' => 'Claim the name before someone else does', 'align' => 'center']),
                    Designer::block('text', ['html' => '<p>Register, transfer or renew — free WHOIS privacy on every eligible domain.</p>', 'align' => 'center']),
                    Designer::block('button', ['text' => 'Search domains', 'align' => 'center']),
                ]]),
                Designer::row('col-3', [
                    [Designer::block('pricing', ['plan' => '.com', 'price' => '$9', 'period' => '/yr', 'features' => '', 'buttonText' => ''])],
                    [Designer::block('pricing', ['plan' => '.net', 'price' => '$11', 'period' => '/yr', 'features' => '', 'buttonText' => ''])],
                    [Designer::block('pricing', ['plan' => '.io', 'price' => '$32', 'period' => '/yr', 'features' => '', 'buttonText' => ''])],
                ], ['paddingTop' => 0]),
                self::footer(),
            ]),
        ];
    }

    protected static function vpsPromotion()
    {
        return [
            'name' => 'VPS promotion', 'category' => 'promotion',
            'description' => 'Specification-led VPS offer for technical buyers.',
            'design' => self::design([
                self::header(),
                Designer::row('col-1', [[
                    Designer::block('heading', ['text' => 'Root access. Dedicated resources. Minutes to deploy.']),
                    Designer::block('text', ['html' => '<p>{{first_name}}, if you have outgrown shared hosting, a VPS gives you guaranteed CPU and RAM without managing hardware.</p>']),
                ]]),
                Designer::row('col-2', [
                    [Designer::block('pricing', ['plan' => 'VPS 4', 'price' => '$24', 'period' => '/mo', 'features' => "4 vCPU\n8 GB RAM\n200 GB NVMe\n1 Gbps", 'buttonText' => 'Deploy VPS 4'])],
                    [Designer::block('pricing', ['plan' => 'VPS 8', 'price' => '$44', 'period' => '/mo', 'features' => "8 vCPU\n16 GB RAM\n400 GB NVMe\n1 Gbps", 'buttonText' => 'Deploy VPS 8'])],
                ], ['paddingTop' => 0]),
                self::footer(),
            ]),
        ];
    }

    protected static function rdpPromotion()
    {
        return [
            'name' => 'RDP promotion', 'category' => 'promotion',
            'description' => 'Windows RDP offer with use-case framing.',
            'design' => self::design([
                self::header(),
                Designer::row('col-1', [[
                    Designer::block('heading', ['text' => 'A Windows desktop in the cloud, always on']),
                    Designer::block('text', ['html' => '<p>Run trading tools, automation or remote work from any device. Admin access, instant setup.</p>']),
                    Designer::block('product', ['title' => 'Windows RDP — Standard', 'body' => '4 vCPU, 8 GB RAM, 120 GB SSD, unlimited bandwidth, full administrator rights.', 'price' => 'from $18/month', 'buttonText' => 'Get RDP access']),
                ]]),
                self::footer(),
            ]),
        ];
    }

    protected static function newProduct()
    {
        return [
            'name' => 'New product launch', 'category' => 'announcement',
            'description' => 'Announce something new with a hero image and single call to action.',
            'design' => self::design([
                self::header(),
                Designer::row('col-1', [[
                    Designer::block('image', ['src' => '', 'alt' => 'Product hero']),
                    Designer::block('heading', ['text' => 'Introducing something new', 'align' => 'center', 'fontSize' => 28]),
                    Designer::block('text', ['html' => '<p>Explain in two sentences what it does and who it is for. Avoid listing every feature.</p>', 'align' => 'center']),
                    Designer::block('button', ['text' => 'See what is new', 'align' => 'center']),
                ]]),
                self::footer(),
            ]),
        ];
    }

    protected static function specialOffer()
    {
        return [
            'name' => 'Special offer', 'category' => 'promotion',
            'description' => 'Time-boxed offer with urgency and one action.',
            'design' => self::design([
                self::header(),
                Designer::row('full-width', [[
                    Designer::block('heading', ['text' => '48 hours only', 'align' => 'center', 'fontSize' => 15, 'color' => '#ffd166']),
                    Designer::block('heading', ['text' => 'Save 40% on your next order', 'align' => 'center', 'fontSize' => 30, 'color' => '#ffffff']),
                ]], ['backgroundColor' => '#111827', 'paddingTop' => 36, 'paddingBottom' => 36]),
                Designer::row('col-1', [[
                    Designer::block('text', ['html' => '<p>{{first_name}}, this applies to new hosting, VPS and RDP orders. Renewals bill at the standard rate.</p>', 'align' => 'center']),
                    Designer::block('button', ['text' => 'Claim the discount', 'align' => 'center']),
                    Designer::block('text', ['html' => '<p><small>Offer ends at 23:59 UTC. One use per account.</small></p>', 'align' => 'center', 'color' => self::MUTED]),
                ]]),
                self::footer(),
            ]),
        ];
    }

    protected static function discountCoupon()
    {
        return [
            'name' => 'Discount / coupon', 'category' => 'promotion',
            'description' => 'Coupon code front and centre.',
            'design' => self::design([
                self::header(),
                Designer::row('col-1', [[
                    Designer::block('heading', ['text' => 'A code, just for you', 'align' => 'center']),
                    Designer::block('text', ['html' => '<p>Apply it at checkout on any new service.</p>', 'align' => 'center']),
                    Designer::block('coupon', ['code' => 'SAVE20', 'headline' => 'Use this code at checkout', 'subtext' => 'Valid for 14 days']),
                    Designer::block('button', ['text' => 'Shop now', 'align' => 'center']),
                ]]),
                self::footer(),
            ]),
        ];
    }

    protected static function maintenance()
    {
        return [
            'name' => 'Maintenance notification', 'category' => 'operational',
            'description' => 'Planned-work notice. Factual, no marketing.',
            'design' => self::design([
                self::header(),
                Designer::row('col-1', [[
                    Designer::block('heading', ['text' => 'Planned maintenance notice']),
                    Designer::block('text', ['html' => '<p>Hello {{first_name}},</p><p>We will carry out planned maintenance on the following window:</p><p><strong>Start:</strong> 00:00 UTC<br /><strong>End:</strong> 02:00 UTC<br /><strong>Expected impact:</strong> brief network interruption of up to 5 minutes.</p><p>No action is required from you. We will post updates on our status page.</p>']),
                    Designer::block('button', ['text' => 'View status page', 'align' => 'left']),
                ]]),
                self::footer(),
            ]),
        ];
    }

    protected static function announcement()
    {
        return [
            'name' => 'Service announcement', 'category' => 'operational',
            'description' => 'Policy, pricing or platform change notice.',
            'design' => self::design([
                self::header(),
                Designer::row('col-1', [[
                    Designer::block('heading', ['text' => 'An important update to your service']),
                    Designer::block('text', ['html' => '<p>Hello {{first_name}},</p><p>State the change in the first sentence. Then explain what it means for the reader, when it takes effect, and what they need to do — if anything.</p>']),
                    Designer::block('divider', []),
                    Designer::block('text', ['html' => '<p>Questions? Reply to this email or open a ticket and we will help.</p>', 'color' => self::MUTED]),
                ]]),
                self::footer(),
            ]),
        ];
    }

    protected static function holiday()
    {
        return [
            'name' => 'Holiday campaign', 'category' => 'promotion',
            'description' => 'Seasonal greeting with a soft offer.',
            'design' => self::design([
                self::header(),
                Designer::row('full-width', [[
                    Designer::block('heading', ['text' => 'Season&#39;s greetings from all of us', 'align' => 'center', 'fontSize' => 27, 'color' => '#ffffff']),
                    Designer::block('text', ['html' => '<p>Thank you for being with us this year, {{first_name}}.</p>', 'align' => 'center', 'color' => '#e6f4ea']),
                ]], ['backgroundColor' => '#14532d', 'paddingTop' => 40, 'paddingBottom' => 40]),
                Designer::row('col-1', [[
                    Designer::block('text', ['html' => '<p>Our support desk stays open throughout the holidays. If you are planning anything new for the new year, here is a little help getting started.</p>', 'align' => 'center']),
                    Designer::block('coupon', ['code' => 'NEWYEAR', 'headline' => 'Holiday discount', 'subtext' => 'Valid until 31 January']),
                ]]),
                self::footer(),
            ]),
        ];
    }

    protected static function abandonedCart()
    {
        return [
            'name' => 'Abandoned cart', 'category' => 'lifecycle',
            'description' => 'Recover an incomplete order. Pairs with an automation trigger.',
            'design' => self::design([
                self::header(),
                Designer::row('col-1', [[
                    Designer::block('heading', ['text' => 'You left something behind']),
                    Designer::block('text', ['html' => '<p>Hi {{first_name}}, your order was not completed. We have kept it for you — picking up where you left off takes under a minute.</p>']),
                    Designer::block('product', ['title' => '{{service_name}}', 'body' => 'Still available at the price you saw.', 'buttonText' => 'Complete my order']),
                    Designer::block('text', ['html' => '<p><small>If you changed your mind, no problem — you can ignore this email.</small></p>', 'color' => self::MUTED]),
                ]]),
                self::footer(),
            ]),
        ];
    }

    protected static function blank()
    {
        return [
            'name' => 'Blank', 'category' => 'custom',
            'description' => 'An empty canvas with a compliant footer already in place.',
            'design' => self::design([
                Designer::row('col-1', [[
                    Designer::block('text', ['html' => '<p>Start writing, or drag a block in from the left.</p>']),
                ]]),
                self::footer(),
            ]),
        ];
    }

    /* --------------------------------------------------------- pieces -- */

    protected static function header()
    {
        return Designer::row('col-1', [[
            Designer::block('logo', ['src' => '', 'alt' => '{{company_name}}', 'width' => 150, 'align' => 'left']),
        ]], ['paddingBottom' => 0]);
    }

    /**
     * Every shipped template carries a compliant footer: company name, postal
     * address and an unsubscribe link. Removing it is possible but preflight
     * will block the send.
     */
    protected static function footer()
    {
        return Designer::row('col-1', [[
            Designer::block('divider', ['paddingTop' => 0]),
            Designer::block('social', []),
            Designer::block('footer', [
                'companyName'     => '{{company_name}}',
                'address'         => '{{physical_address}}',
                'showUnsubscribe' => true,
            ]),
        ]], ['backgroundColor' => '#f7f8fa', 'paddingTop' => 8]);
    }

    protected static function design(array $rows)
    {
        return Designer::normalize([
            'settings' => array_merge(BlockLibrary::DESIGN_DEFAULTS, ['linkColor' => self::ACCENT]),
            'rows'     => $rows,
        ]);
    }
}
