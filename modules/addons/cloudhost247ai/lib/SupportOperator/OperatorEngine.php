<?php
/**
 * AI Support Operator decision engine.
 *
 * Deterministic and dependency-free: intent regexes, public knowledge
 * retrieval, live catalog grounding, and explicit escalation rules. No
 * model calls, no external APIs, no invented facts — when the platform
 * cannot support an answer, the conversation goes to a human instead.
 */

namespace Ch247Ai\SupportOperator;

use Ch247Ai\Core\Audit;
use Ch247Ai\Core\RateLimiter;
use Ch247Ai\Core\RateLimitException;
use Ch247Ai\Core\Settings;
use Ch247Ai\Knowledge\KnowledgeService;

class OperatorEngine
{
    const FLOW_NEWSLETTER_INVITED = 'newsletter_invited';
    const FLOW_NEWSLETTER_NAME = 'newsletter_name';
    const FLOW_NEWSLETTER_EMAIL = 'newsletter_email';
    const FLOW_NEWSLETTER_CONFIRM = 'newsletter_confirm';
    const FLOW_OFFLINE_CONTACT = 'offline_contact';

    const ANSWERED = 'answered';
    const ESCALATED = 'escalated';
    const NEWSLETTER = 'newsletter';
    const NOTICE = 'notice';

    public static function intents()
    {
        return [
            'HUMAN_REQUEST' => '/\b(human|agent|real\s*person|real\s*human|support\s*agent|talk\s*to\s*someone|speak\s*to\s*someone|call\s*me|phone\s*me|contact\s*support|open\s*(a\s*)?ticket|escalat\w*|transfer\s*me|hand\s*me\s*off|not\s*a\s*robot|stop\s*bot)\b/i',
            'NEWSLETTER_REQUEST' => '/\b(subscribe|subscription|newsletter|mailing\s*list|email\s*updates?|sign\s*me\s*up|keep\s*me\s*(posted|updated|informed))\b/i',
            'SECURITY_REQUEST' => '/\b(hack\w*|breach\w*|compromis\w*|phishing|malware|suspicious\s*(login|activity|email)|unauthori[sz]ed|stolen|fraud|scam|2fa|two[\s-]*factor|someone\s*(in|on)\s*my\s*account)\b/i',
            'REFUND_REQUEST' => '/\b(refund|money\s*back|charge\s*back|chargeback|reverse\s*(the\s*)?(payment|charge)|cancel\s*(my\s*)?(order|subscription|service|account)\s*(and|for|to)?.*(refund|money)|get\s*my\s*money)\b/i',
            'COMPLAINT' => '/\b(complain\w*|complaint|terrible|awful|horrible|worst|disgust\w*|unacceptable|angry|furious|upset|ripoff|rip\s*off|scam|report\s*you|manager|supervisor|sue\b|lawsuit|lawyer|attorney|ombudsman|regulator|terrible\s*service)\b/i',
            'SERVER_INCIDENT' => '/\b(server\s*(is\s*)?(down|offline|not\s*responding|unreachable|crashed)|website\s*(is\s*)?down|site\s*(is\s*)?down|outage|downtime|cannot\s*(reach|access|connect)|connection\s*(refused|timed?\s*out|timeout)|cannot\s*ssh|cannot\s*rdp|rdp\s*(not|isn.t)\s*working|vps\s*(down|offline|unreachable|not\s*working)|service\s*(down|offline|unreachable)|everything\s*(is\s*)?down|all\s*(my\s*)?(sites?|servers?)\s*(are\s*)?down|ddos|dDoS)\b/i',
            'BILLING_REQUEST' => '/\b(invoice|billing|bill|payment|pay|paid|charge[ds]?|charged|overcharg\w*|credit\s*card|card\s*(declined|expired)|transaction|receipt|balance|due|overdue|suspend\w*|unsuspend|renew|renewal|proforma|quotation)\b/i',
            'ACCOUNT_ACTION' => '/\b(change|update|modify|edit|cancel|delete|remove|upgrade|downgrade|transfer|move|reset|close)\s+(my|the|this|that|an?)\s+\w*\s*(account|service|hosting|domain|server|vps|email|password|subscription|plan)\b/i',
            'ACCOUNT_REQUEST' => '/\b(my|mine)\s+(account|invoice|service|domain|server|vps|hosting|email|order|ticket|payment|subscription|plan)\b/i',
            'PRICE_REQUEST' => '/\b(how\s*much|price|pricing|cost|costs|quote|cheap|expensive|fee|rate|monthly|annually|per\s*month|per\s*year|\$\s*\d|plan\s*price)\b/i',
            'CONTACT_REQUEST' => '/\b(contact|phone|email\s*address|support\s*hours|opening\s*hours|where\s*are\s*you|location|address|whatsapp|telegram|live\s*chat|call\s*center)\b/i',
            'GREETING' => '/^(hi|hey|hello|good\s*(morning|afternoon|evening|day)|morning|yo|sup|howdy|hiya|greetings)\b/i',
            'CAPABILITY' => '/\b(what\s*can\s*you\s*do|who\s*are\s*you|your\s*name|are\s*you\s*(a\s*)?(bot|robot|ai|human|real)|help(\s*me)?\s*\??$|what\s*do\s*you\s*do|how\s*can\s*you\s*help|what\s*is\s*this|how\s*does\s*this\s*work)\b/i',
        ];
    }

    public static function detectIntent($text)
    {
        foreach (self::intents() as $intent => $regex) {
            if (preg_match($regex, (string) $text)) {
                return $intent;
            }
        }
        return 'UNKNOWN';
    }

    public static function enabled()
    {
        return Settings::bool('support_operator_enabled', false);
    }

    public static function rateConfig()
    {
        return [
            'max' => max(1, Settings::int('support_rate_max', 20)),
            'window' => max(60, Settings::int('support_rate_window', 300)),
        ];
    }

    /**
     * Handle one visitor message. $ctx: client row (or null), client_id,
     * actor ('client'|'guest'), ip. Returns ['action','reply','citations',
     * 'escalation_reason'?] and persists both messages.
     */
    public static function handle(array $conv, $text, array $ctx)
    {
        $convId = (int) $conv['id'];
        $max = max(200, Settings::int('support_max_message', 2000));
        $text = trim(mb_substr((string) $text, 0, $max));
        if ($text === '') {
            return self::notice($convId, 'Please type a message first.');
        }

        $rate = self::rateConfig();
        $bucket = !empty($ctx['client_id'])
            ? RateLimiter::bucketForClient((int) $ctx['client_id'])
            : RateLimiter::bucketForCurrentRequest();
        try {
            RateLimiter::hitOrFail('support_chat', $bucket, $rate['max'], $rate['window']);
        } catch (RateLimitException $e) {
            return self::notice($convId,
                'You are sending messages too quickly. Please wait a few minutes and try again.');
        }

        $status = $conv['status'];
        if ($status === ConversationService::STATUS_WAITING_FOR_HUMAN
            || $status === ConversationService::STATUS_HUMAN_ACTIVE
        ) {
            ConversationService::addMessage($convId, ConversationService::AUTHOR_CUSTOMER, $text);
            return self::notice($convId,
                'Thanks — a CloudHost247 support agent has your conversation and will reply here shortly. '
                . 'Your message has been added to the thread.');
        }
        if ($status === ConversationService::STATUS_RESOLVED || $status === ConversationService::STATUS_CLOSED) {
            ConversationService::setStatus($convId, ConversationService::STATUS_AI_ACTIVE);
            ConversationService::addMessage($convId, ConversationService::AUTHOR_SYSTEM, 'Conversation reopened by visitor.');
            $conv['status'] = ConversationService::STATUS_AI_ACTIVE;
            Audit::record($ctx['actor'] ?? 'guest', (int) ($ctx['client_id'] ?? 0), 'ai.support.reopened', [
                'entity_type' => 'support_conversation',
                'entity_id' => $convId,
            ]);
        }

        ConversationService::addMessage($convId, ConversationService::AUTHOR_CUSTOMER, $text);
        $conv = ConversationService::find($convId);

        // An in-progress collection flow owns the next message.
        $flowResult = self::handleFlow($conv, $text, $ctx);
        if ($flowResult !== null) {
            return $flowResult;
        }

        $intent = self::detectIntent($text);
        switch ($intent) {
            case 'HUMAN_REQUEST':
                return self::escalateWithContact($conv, EscalationService::USER_REQUESTED_HUMAN, $text, $ctx,
                    'Of course — connecting you with CloudHost247 Support now.');
            case 'NEWSLETTER_REQUEST':
                return self::startNewsletter($conv);
            case 'SECURITY_REQUEST':
                return self::escalateWithContact($conv, EscalationService::SECURITY_RELATED, $text, $ctx,
                    'That sounds like a security matter, so I am bringing in CloudHost247 Support right away — please do not share passwords or codes in this chat.');
            case 'REFUND_REQUEST':
                return self::escalateWithContact($conv, EscalationService::REFUND_REQUEST, $text, $ctx,
                    'Refund decisions need a human review, so I am passing this to CloudHost247 Support with our full conversation attached.');
            case 'COMPLAINT':
                return self::escalateWithContact($conv, EscalationService::COMPLAINT, $text, $ctx,
                    'I am sorry you have had this experience — I am bringing in CloudHost247 Support immediately so a person can make this right.');
            case 'SERVER_INCIDENT':
                return self::escalateWithContact($conv, EscalationService::SERVER_SUPPORT_REQUIRED, $text, $ctx,
                    'Service problems need hands on the infrastructure, so I am escalating this to CloudHost247 Support right away.');
            case 'BILLING_REQUEST':
                return self::escalateWithContact($conv, EscalationService::BILLING_SUPPORT_REQUIRED, $text, $ctx,
                    'Billing questions touch your account records, which I cannot see from this chat — I am passing you to CloudHost247 Support with everything you have told me.');
            case 'ACCOUNT_ACTION':
            case 'ACCOUNT_REQUEST':
                return self::escalateWithContact($conv, EscalationService::ACCOUNT_SPECIFIC_REQUEST, $text, $ctx,
                    'That needs access to your account, which I do not have from this chat — I am passing you to CloudHost247 Support with our full conversation attached.');
            case 'PRICE_REQUEST':
                return self::answerPrice($conv, $text, $ctx);
            case 'CONTACT_REQUEST':
                return self::answerFromTopic($conv, 'contact', $ctx);
            case 'GREETING':
                return self::answerGreeting($conv);
            case 'CAPABILITY':
                return self::answerCapabilities($conv);
            default:
                return self::answerFromKnowledge($conv, $text, $ctx);
        }
    }

    // ------------------------------------------------------------------
    // Collection flows (newsletter signup, offline contact capture)
    // ------------------------------------------------------------------

    private static function handleFlow(array $conv, $text, array $ctx)
    {
        $convId = (int) $conv['id'];
        $flow = (string) ($conv['flow'] ?: '');
        if ($flow === '') {
            return null;
        }
        if ($flow === self::FLOW_NEWSLETTER_INVITED) {
            if (preg_match('/\b(yes|yeah|yep|sure|ok(ay)?|please|subscribe|sign\s*me\s*up|do\s*it)\b/i', $text)
                && !preg_match('/\b(no|not? |don.t|cancel|stop|never)\b/i', $text)
            ) {
                return self::startNewsletter($conv);
            }
            if (preg_match('/\b(no|not?\b|don.t|cancel|stop|never|no\s*thanks?)\b/i', $text)) {
                ConversationService::setFlow($convId, null);
                return self::say($conv, self::ANSWERED, 'No problem — what else can I help with?', $ctx);
            }
            ConversationService::setFlow($convId, null);
            return null; // treat as a fresh question below
        }
        if ($flow === self::FLOW_NEWSLETTER_NAME) {
            $name = trim(preg_replace('/\s+/', ' ', $text));
            ConversationService::setFlow($convId, self::FLOW_NEWSLETTER_EMAIL, json_encode(['name' => $name]));
            return self::say($conv, self::NEWSLETTER,
                'Thanks, ' . $name . '! What email address should the newsletter go to?', $ctx,
                ['flow' => self::FLOW_NEWSLETTER_EMAIL]);
        }
        if ($flow === self::FLOW_NEWSLETTER_EMAIL) {
            if (preg_match('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', $text, $m)) {
                $data = json_decode((string) $conv['flow_data'], true) ?: [];
                $data['email'] = strtolower($m[0]);
                ConversationService::setFlow($convId, self::FLOW_NEWSLETTER_CONFIRM, json_encode($data));
                return self::say($conv, self::NEWSLETTER,
                    'Just to confirm — subscribe ' . $data['email'] . ' to the CloudHost247 newsletter? Reply YES to confirm.', $ctx,
                    ['flow' => self::FLOW_NEWSLETTER_CONFIRM]);
            }
            return self::say($conv, self::NEWSLETTER,
                'That does not look like a valid email address — could you double-check it?', $ctx,
                ['flow' => self::FLOW_NEWSLETTER_EMAIL]);
        }
        if ($flow === self::FLOW_NEWSLETTER_CONFIRM) {
            if (preg_match('/\b(yes|yeah|yep|confirm|sure|ok(ay)?|do\s*it|subscribe)\b/i', $text)) {
                $data = json_decode((string) $conv['flow_data'], true) ?: [];
                $email = isset($data['email']) ? $data['email'] : '';
                $name = isset($data['name']) ? $data['name'] : ($conv['guest_name'] ?: '');
                $result = NewsletterBridge::subscribe($name, $email);
                ConversationService::setFlow($convId, null);
                Audit::record($ctx['actor'] ?? 'guest', (int) ($ctx['client_id'] ?? 0), 'ai.support.newsletter_subscribed', [
                    'entity_type' => 'support_conversation',
                    'entity_id' => $convId,
                    'context' => ['status' => $result['status']],
                ]);
                $reply = $result['status'] === 'invalid'
                    ? 'Hmm, that email address did not validate — let us start over. What is your name?'
                    : 'You are subscribed — welcome aboard! Watch your inbox for CloudHost247 news. What else can I help with?';
                if ($result['status'] === 'invalid') {
                    ConversationService::setFlow($convId, self::FLOW_NEWSLETTER_NAME);
                }
                return self::say($conv, self::NEWSLETTER, $reply, $ctx, ['newsletter' => $result['status']]);
            }
            ConversationService::setFlow($convId, null);
            return self::say($conv, self::NEWSLETTER, 'No problem — I have cancelled the signup. What else can I help with?', $ctx);
        }
        if ($flow === self::FLOW_OFFLINE_CONTACT) {
            if (preg_match('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', $text, $m)) {
                $email = strtolower($m[0]);
                $name = trim(str_replace($m[0], ' ', $text));
                $name = trim(preg_replace('/\s+/', ' ', $name));
                if ($name === '') {
                    $name = $conv['guest_name'] !== '' ? $conv['guest_name'] : 'Website visitor';
                }
                ConversationService::setContact($convId, $name, $email);
                $data = json_decode((string) $conv['flow_data'], true) ?: [];
                $reason = isset($data['reason']) ? $data['reason'] : EscalationService::OTHER;
                $intro = isset($data['intro']) ? $data['intro'] : '';
                ConversationService::setFlow($convId, null);
                $conv = ConversationService::find($convId);
                return self::doEscalate($conv, $reason, $text, $ctx, $intro);
            }
            return self::say($conv, self::ESCALATED,
                'I still need an email address so Support can reach you — what is the best email?', $ctx,
                ['flow' => self::FLOW_OFFLINE_CONTACT]);
        }
        ConversationService::setFlow($convId, null);
        return null;
    }

    private static function startNewsletter(array $conv)
    {
        $convId = (int) $conv['id'];
        if ($conv['guest_name'] !== '') {
            ConversationService::setFlow($convId, self::FLOW_NEWSLETTER_EMAIL,
                json_encode(['name' => $conv['guest_name']]));
            return self::say($conv, self::NEWSLETTER,
                'Great — I will sign you up for the CloudHost247 newsletter. What email address should it go to?',
                [], ['flow' => self::FLOW_NEWSLETTER_EMAIL]);
        }
        ConversationService::setFlow($convId, self::FLOW_NEWSLETTER_NAME);
        return self::say($conv, self::NEWSLETTER,
            'Great — I will sign you up for the CloudHost247 newsletter. First, what is your name?',
            [], ['flow' => self::FLOW_NEWSLETTER_NAME]);
    }

    // ------------------------------------------------------------------
    // Escalation (with contact capture for unknown guests)
    // ------------------------------------------------------------------

    private static function escalateWithContact(array $conv, $reason, $text, array $ctx, $intro)
    {
        $convId = (int) $conv['id'];
        $hasContact = (int) $conv['client_id'] > 0
            || (strpos((string) $conv['guest_email'], '@') !== false);
        if (!$hasContact) {
            ConversationService::setFlow($convId, self::FLOW_OFFLINE_CONTACT,
                json_encode(['reason' => $reason, 'intro' => $intro]));
            return self::say($conv, self::ESCALATED,
                $intro . ' So Support can reach you, what is your name and email address?',
                $ctx, ['flow' => self::FLOW_OFFLINE_CONTACT, 'escalation_reason' => $reason]);
        }
        return self::doEscalate($conv, $reason, $text, $ctx, $intro);
    }

    private static function doEscalate(array $conv, $reason, $text, array $ctx, $intro)
    {
        $convId = (int) $conv['id'];
        $result = EscalationService::escalate(ConversationService::find($convId), $reason, $text);
        if ($result['ticket_id'] > 0 && $result['online']) {
            $reply = $intro . ' Your request is with CloudHost247 Support as ticket #'
                . $result['ticket_id'] . ' — an agent will reply here shortly.';
        } elseif ($result['ticket_id'] > 0) {
            $reply = $intro . ' Nobody is online right now, so I have filed your request as ticket #'
                . $result['ticket_id'] . ' — the team will follow up by email.';
        } else {
            $reply = $intro . ' I ran into a problem filing the ticket automatically — please open a support ticket from your client area and mention this chat, and the team will pick it up.';
        }
        Audit::record($ctx['actor'] ?? 'guest', (int) ($ctx['client_id'] ?? 0), 'ai.support.transfer_completed', [
            'entity_type' => 'support_conversation',
            'entity_id' => $convId,
            'context' => ['reason' => $reason, 'ticket_id' => $result['ticket_id']],
        ]);
        return self::say($conv, self::ESCALATED, $reply, $ctx, [
            'escalation_reason' => $reason,
            'ticket_id' => $result['ticket_id'],
        ]);
    }

    // ------------------------------------------------------------------
    // Answers
    // ------------------------------------------------------------------

    private static function answerGreeting(array $conv)
    {
        $convId = (int) $conv['id'];
        $reply = 'Hello! I am the CloudHost247 AI Support Operator. I can answer questions about our services, help with the client area, '
            . 'check live plan pricing, or connect you with a human on the support team — just ask.';
        $extra = [];
        if ($conv['flow'] === null && $conv['message_count'] <= 2) {
            ConversationService::setFlow($convId, self::FLOW_NEWSLETTER_INVITED);
            $reply .= ' Would you like CloudHost247 news and offers by email while you are here?';
            $extra = ['flow' => self::FLOW_NEWSLETTER_INVITED];
        }
        return self::say($conv, self::ANSWERED, $reply, [], $extra);
    }

    private static function answerCapabilities(array $conv)
    {
        return self::say($conv, self::ANSWERED,
            'I am the CloudHost247 AI Support Operator — I answer questions from our knowledge base and the live product catalog. '
            . 'I can explain services, outline client-area steps, check plan pricing, and sign you up for the newsletter. '
            . 'I cannot see or change account records, and I never guess: anything needing account access or a judgment call goes straight to a human on CloudHost247 Support, '
            . 'with our whole conversation attached. Just say "talk to a human" any time.');
    }

    private static function answerPrice(array $conv, $text, array $ctx)
    {
        $answer = CatalogGrounding::answer($text);
        if ($answer === null) {
            return self::escalateWithContact($conv, EscalationService::OTHER, $text, $ctx,
                'I could not find that in the live product catalog, so rather than guess I am passing you to CloudHost247 Support for current pricing.');
        }
        return self::say($conv, self::ANSWERED, $answer['text'], $ctx, ['products' => $answer['products']]);
    }

    private static function answerFromTopic(array $conv, $topic, array $ctx)
    {
        $rows = KnowledgeService::search($topic, 10);
        $best = self::pickPublic($rows);
        if ($best === null) {
            return self::escalateWithContact($conv, EscalationService::OTHER, $topic, $ctx,
                'Let me connect you with CloudHost247 Support, who can give you the current contact details.');
        }
        return self::say($conv, self::ANSWERED,
            self::excerpt($best) . "\n\nSource: " . $best['title'], $ctx,
            ['knowledge_id' => (int) $best['id']]);
    }

    private static function answerFromKnowledge(array $conv, $text, array $ctx)
    {
        $terms = CatalogGrounding::terms($text);
        $candidates = [];
        foreach (KnowledgeService::search(implode(' ', array_slice($terms, 0, 6)), 10) as $row) {
            $candidates[$row['id']] = $row;
        }
        foreach (KnowledgeService::search($text, 10) as $row) {
            $candidates[$row['id']] = $row;
        }
        $best = null;
        $bestScore = 0;
        foreach ($candidates as $row) {
            if (($row['visibility'] ?? '') !== 'public') {
                continue;
            }
            $score = self::score($row, $terms);
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $row;
            }
        }
        // Conservative: at least two distinct term hits AND a title/tag hit,
        // otherwise the operator refuses to answer and escalates.
        if ($best === null || $bestScore < 4 || !self::titleOrTagHit($best, $terms)) {
            return self::escalateWithContact($conv, EscalationService::AI_UNABLE_TO_ANSWER, $text, $ctx,
                'I do not have a reliable answer for that in my knowledge base, so rather than guess I am passing you to CloudHost247 Support.');
        }
        return self::say($conv, self::ANSWERED,
            self::excerpt($best) . "\n\nSource: " . $best['title'] . ' — need a human to go deeper? Just ask.', $ctx,
            ['knowledge_id' => (int) $best['id']]);
    }

    private static function score(array $row, array $terms)
    {
        $titleTags = strtolower($row['title'] . ' ' . ($row['tags'] ?? ''));
        $body = strtolower($row['body']);
        $score = 0;
        foreach ($terms as $term) {
            if (strpos($titleTags, $term) !== false) {
                $score += 2;
            } elseif (strpos($body, $term) !== false) {
                $score += 1;
            }
        }
        return $score;
    }

    private static function titleOrTagHit(array $row, array $terms)
    {
        $titleTags = strtolower($row['title'] . ' ' . ($row['tags'] ?? ''));
        foreach ($terms as $term) {
            if (strpos($titleTags, $term) !== false) {
                return true;
            }
        }
        return false;
    }

    private static function pickPublic(array $rows)
    {
        foreach ($rows as $row) {
            if (($row['visibility'] ?? '') === 'public') {
                return $row;
            }
        }
        return null;
    }

    private static function excerpt(array $row)
    {
        $body = trim((string) $row['body']);
        if (mb_strlen($body) > 700) {
            $body = mb_substr($body, 0, 700) . '…';
        }
        return $body;
    }

    /** Persist the AI reply and audit it; returns the portal result shape. */
    private static function say(array $conv, $action, $reply, array $ctx = [], array $meta = [])
    {
        $convId = (int) $conv['id'];
        $citations = [];
        if (!empty($meta['knowledge_id'])) {
            $citations[] = 'knowledge:' . (int) $meta['knowledge_id'];
        }
        if (!empty($meta['products'])) {
            foreach ((array) $meta['products'] as $product) {
                $citations[] = 'catalog:' . $product;
            }
        }
        ConversationService::addMessage($convId, ConversationService::AUTHOR_AI, $reply, $meta + ['citations' => $citations]);
        Audit::record($ctx['actor'] ?? 'guest', (int) ($ctx['client_id'] ?? 0), 'ai.support.answered', [
            'entity_type' => 'support_conversation',
            'entity_id' => $convId,
            'context' => ['action' => $action] + $meta,
        ]);
        return [
            'action' => $action,
            'reply' => $reply,
            'citations' => $citations,
            'escalation_reason' => isset($meta['escalation_reason']) ? $meta['escalation_reason'] : '',
        ];
    }

    private static function notice($convId, $reply)
    {
        return ['action' => self::NOTICE, 'reply' => $reply, 'citations' => [], 'escalation_reason' => ''];
    }
}
