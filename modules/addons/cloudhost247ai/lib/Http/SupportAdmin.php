<?php
/**
 * Admin surface for the AI Support Operator.
 *
 * Kept in its own class so the operator's admin UI stays reviewable in one
 * place; AdminPortal delegates the `support*` sections and `support*`
 * posts here. Grants follow the module model: viewing conversations or
 * newsletter emails needs ai.client.read (customer PII), while replying,
 * assigning and changing status need ai.manage. Presence heartbeat is
 * view-level — it only says "I am here".
 */

namespace Ch247Ai\Http;

use Ch247Ai\Core\Audit;
use Ch247Ai\Core\Csrf;
use Ch247Ai\Core\Db;
use Ch247Ai\Core\ForbiddenException;
use Ch247Ai\Core\Identity;
use Ch247Ai\Core\Rbac;
use Ch247Ai\SupportOperator\ConversationService;
use Ch247Ai\SupportOperator\EscalationService;
use Ch247Ai\SupportOperator\NewsletterBridge;
use Ch247Ai\SupportOperator\PresenceService;

class SupportAdmin
{
    private $moduleLink;

    public function __construct($moduleLink)
    {
        $this->moduleLink = $moduleLink;
    }

    /** Handle a support POST. Returns a success notice; throws on failure. */
    public static function handle($postAction)
    {
        $adminId = (int) Identity::adminId();
        if (!$adminId) {
            throw new ForbiddenException('Admin session required.');
        }
        switch ($postAction) {
            case 'support_reply':
                return self::reply($adminId);
            case 'support_assign':
                return self::assign($adminId);
            case 'support_status':
                return self::changeStatus($adminId);
            case 'support_presence':
                return self::presence($adminId);
            case 'support_newsletter_unsub':
                return self::unsubscribe($adminId);
            default:
                throw new ForbiddenException('Unknown support action.');
        }
    }

    private static function needManage()
    {
        if (!Rbac::adminCan(Rbac::AI_MANAGE)) {
            throw new ForbiddenException('You need the AI manage permission for this.');
        }
    }

    private static function reply($adminId)
    {
        self::needManage();
        $id = (int) ($_POST['id'] ?? 0);
        $text = trim((string) ($_POST['reply'] ?? ''));
        if ($id <= 0 || ConversationService::find($id) === null) {
            throw new ForbiddenException('Conversation not found.');
        }
        if ($text === '') {
            throw new ForbiddenException('Type a reply first.');
        }
        EscalationService::agentReply($id, $adminId, $text);
        return 'Reply sent to the visitor.';
    }

    private static function assign($adminId)
    {
        self::needManage();
        $id = (int) ($_POST['id'] ?? 0);
        $to = (int) ($_POST['admin_id'] ?? 0);
        if ($id <= 0 || ConversationService::find($id) === null) {
            throw new ForbiddenException('Conversation not found.');
        }
        $admins = Db::query('SELECT id FROM tbladmins WHERE id = ?', [$to]);
        if (!$admins) {
            throw new ForbiddenException('Unknown admin.');
        }
        Db::update('support_conversations', ['id' => $id], ['assigned_admin_id' => $to]);
        ConversationService::addMessage($id, ConversationService::AUTHOR_SYSTEM, 'Assigned to admin #' . $to . '.');
        Audit::admin($adminId, 'ai.support.assigned', [
            'entity_type' => 'support_conversation',
            'entity_id' => $id,
            'context' => ['assigned_admin_id' => $to],
        ]);
        return 'Conversation assigned to admin #' . $to . '.';
    }

    private static function changeStatus($adminId)
    {
        self::needManage();
        $id = (int) ($_POST['id'] ?? 0);
        $to = (string) ($_POST['to'] ?? '');
        $map = [
            'resolved' => ConversationService::STATUS_RESOLVED,
            'closed' => ConversationService::STATUS_CLOSED,
            'ai_active' => ConversationService::STATUS_AI_ACTIVE,
        ];
        if ($id <= 0 || ConversationService::find($id) === null) {
            throw new ForbiddenException('Conversation not found.');
        }
        if (!isset($map[$to])) {
            throw new ForbiddenException('Unknown status.');
        }
        ConversationService::setFlow($id, null);
        ConversationService::setStatus($id, $map[$to]);
        $note = $to === 'ai_active' ? 'Returned to the AI operator.' : 'Marked ' . $to . ' by admin #' . $adminId . '.';
        ConversationService::addMessage($id, ConversationService::AUTHOR_SYSTEM, $note);
        Audit::admin($adminId, 'ai.support.status', [
            'entity_type' => 'support_conversation',
            'entity_id' => $id,
            'context' => ['to' => $map[$to]],
        ]);
        return 'Conversation updated.';
    }

    private static function presence($adminId)
    {
        if (!Rbac::adminCan(Rbac::AI_READ) && !Rbac::adminCan(Rbac::AI_CLIENT_READ)) {
            throw new ForbiddenException('You need AI read access to set presence.');
        }
        $status = (string) ($_POST['status'] ?? 'online');
        if (!in_array($status, ['online', 'busy', 'offline'], true)) {
            $status = 'online';
        }
        PresenceService::heartbeat($adminId, $status);
        return 'Presence set to ' . $status . '.';
    }

    private static function unsubscribe($adminId)
    {
        self::needManage();
        $email = trim((string) ($_POST['email'] ?? ''));
        if ($email === '' || !NewsletterBridge::unsubscribe($email)) {
            throw new ForbiddenException('Subscription not found.');
        }
        Audit::admin($adminId, 'ai.support.newsletter_unsubscribed', [
            'entity_type' => 'support_newsletter',
            'entity_id' => 0,
            'context' => [],
        ]);
        return 'Subscription removed.';
    }

    // ------------------------------------------------------------------
    // Rendering
    // ------------------------------------------------------------------

    public function render($section)
    {
        ob_start();
        echo '<p><a href="' . $this->u('dashboard') . '">&larr; Back to dashboard</a></p>';
        echo '<ul class="nav nav-pills" style="margin-bottom:20px">'
            . '<li class="' . ($section === 'support' ? 'active' : '') . '"><a href="' . $this->u('support') . '">Conversations</a></li>'
            . '<li class="' . ($section === 'support-newsletter' ? 'active' : '') . '"><a href="' . $this->u('support-newsletter') . '">Newsletter</a></li>'
            . '</ul>';
        if ($section === 'support-view') {
            $this->view();
        } elseif ($section === 'support-newsletter') {
            $this->newsletter();
        } else {
            $this->overview();
        }
        return ob_get_clean();
    }

    private function canView()
    {
        return Rbac::adminCan(Rbac::AI_CLIENT_READ);
    }

    private function overview()
    {
        echo '<h2>AI Support Operator</h2>';
        if (!$this->canView()) {
            echo '<div class="alert alert-danger">You need the ai.client.read permission to view support conversations.</div>';
            return;
        }
        $counts = ConversationService::overview();
        $avail = PresenceService::availability();
        $pill = $avail['status'] === 'online' ? 'success' : ($avail['status'] === 'busy' ? 'warning' : 'default');
        echo '<p>Visitor-facing availability: <span class="label label-' . $pill . '">' . $this->e($avail['status']) . '</span>'
            . ' <span class="text-muted small">' . (int) $avail['fresh_count'] . ' fresh heartbeat(s)</span></p>';
        if (Rbac::adminCan(Rbac::AI_READ) || Rbac::adminCan(Rbac::AI_CLIENT_READ)) {
            echo '<form method="post" class="form-inline" style="margin-bottom:14px">' . Csrf::field()
                . '<input type="hidden" name="ch247ai_action" value="support_presence">'
                . '<span class="small text-muted">My presence:</span> '
                . '<button class="btn btn-xs btn-success" name="status" value="online">Online</button> '
                . '<button class="btn btn-xs btn-warning" name="status" value="busy">Busy</button> '
                . '<button class="btn btn-xs btn-default" name="status" value="offline">Offline</button></form>';
        }
        echo '<div class="row">'
            . $this->stat('Conversations', $counts['total'])
            . $this->stat('With AI', $counts['ai_active'])
            . $this->stat('Waiting for human', $counts['waiting_for_human'])
            . $this->stat('With agents', $counts['human_active'])
            . $this->stat('Resolved', $counts['resolved'])
            . $this->stat('Newsletter', $counts['newsletter'])
            . '</div>';

        $status = (string) ($_GET['status'] ?? '');
        $query = trim((string) ($_GET['q'] ?? ''));
        $page = max(1, (int) ($_GET['p'] ?? 1));
        echo '<form method="get" class="form-inline" style="margin:12px 0"><input type="hidden" name="module" value="cloudhost247ai">'
            . '<input type="hidden" name="action" value="support">'
            . '<select class="form-control input-sm" name="status"><option value="">All statuses</option>';
        foreach (ConversationService::STATUSES as $option) {
            echo '<option value="' . $option . '"' . ($status === $option ? ' selected' : '') . '>' . $option . '</option>';
        }
        echo '</select> <input class="form-control input-sm" name="q" value="' . $this->e($query) . '" placeholder="Name, email, client #, public id">'
            . ' <button class="btn btn-default btn-sm">Filter</button></form>';

        $list = ConversationService::adminList($status, $query, $page);
        echo '<div class="table-responsive"><table class="table table-striped"><thead><tr>'
            . '<th>Started</th><th>Visitor</th><th>Status</th><th>Reason</th><th>Ticket</th><th>Agent</th><th>Msgs</th><th>Last</th><th></th>'
            . '</tr></thead><tbody>';
        $labels = EscalationService::labels();
        foreach ($list['rows'] as $row) {
            $visitor = $row['guest_name'] !== '' ? $row['guest_name'] : '—';
            if ((int) $row['client_id'] > 0) {
                $visitor .= ' <span class="text-muted small">(client #' . (int) $row['client_id'] . ')</span>';
            } elseif ($row['guest_email'] !== '') {
                $visitor .= '<br><small class="text-muted">' . $this->e($row['guest_email']) . '</small>';
            }
            echo '<tr><td class="small">' . $this->e((string) $row['created_at']) . '</td>'
                . '<td>' . $visitor . '</td>'
                . '<td>' . $this->statusPill($row['status']) . '</td>'
                . '<td class="small">' . ($row['escalation_reason'] !== '' && isset($labels[$row['escalation_reason']]) ? $this->e($labels[$row['escalation_reason']]) : '—') . '</td>'
                . '<td>' . ((int) $row['ticket_id'] > 0 ? '#' . (int) $row['ticket_id'] : '—') . '</td>'
                . '<td>' . ((int) $row['assigned_admin_id'] > 0 ? '#' . (int) $row['assigned_admin_id'] : '—') . '</td>'
                . '<td>' . (int) $row['message_count'] . '</td>'
                . '<td class="small">' . $this->e((string) ($row['last_message_at'] ?: $row['updated_at'])) . '</td>'
                . '<td><a class="btn btn-xs btn-primary" href="' . $this->u('support-view', ['id' => (int) $row['id']]) . '">Open</a></td></tr>';
        }
        if ($list['rows'] === []) {
            echo '<tr><td colspan="9" class="text-muted">No conversations match. New chats appear here as visitors talk to the operator.</td></tr>';
        }
        echo '</tbody></table></div>';
        $pages = max(1, (int) ceil($list['total'] / $list['per_page']));
        if ($pages > 1) {
            echo '<p>';
            if ($page > 1) {
                echo '<a class="btn btn-xs btn-default" href="' . $this->u('support', ['status' => $status, 'q' => $query, 'p' => $page - 1]) . '">&larr; Prev</a> ';
            }
            echo '<span class="small text-muted">Page ' . $page . ' of ' . $pages . ' (' . $list['total'] . ' conversations)</span> ';
            if ($page < $pages) {
                echo '<a class="btn btn-xs btn-default" href="' . $this->u('support', ['status' => $status, 'q' => $query, 'p' => $page + 1]) . '">Next &rarr;</a>';
            }
            echo '</p>';
        }
    }

    private function view()
    {
        echo '<h2>Support conversation</h2>';
        if (!$this->canView()) {
            echo '<div class="alert alert-danger">You need the ai.client.read permission to view support conversations.</div>';
            return;
        }
        $id = (int) ($_GET['id'] ?? 0);
        $conv = $id > 0 ? ConversationService::find($id) : null;
        if ($conv === null) {
            echo '<div class="alert alert-warning">Conversation not found. <a href="' . $this->u('support') . '">Back to the list</a>.</div>';
            return;
        }
        $manage = Rbac::adminCan(Rbac::AI_MANAGE);
        $labels = EscalationService::labels();
        echo '<p><a href="' . $this->u('support') . '">&larr; All conversations</a> &nbsp;'
            . $this->statusPill($conv['status']) . ' &nbsp;'
            . '<span class="small text-muted">public ' . $this->e(substr($conv['public_id'], 0, 12)) . '…'
            . ((int) $conv['client_id'] > 0 ? ' · client #' . (int) $conv['client_id'] : ' · ' . $this->e(trim($conv['guest_name'] . ' <' . $conv['guest_email'] . '>')))
            . ((int) $conv['ticket_id'] > 0 ? ' · ticket #' . (int) $conv['ticket_id'] : '')
            . ($conv['escalation_reason'] !== '' && isset($labels[$conv['escalation_reason']]) ? ' · ' . $this->e($labels[$conv['escalation_reason']]) : '')
            . '</span></p>';

        echo '<div class="ch247ai-chat" style="margin-bottom:14px">';
        foreach (ConversationService::messages($id) as $message) {
            $class = $message['author'] === 'customer' ? 'user' : ($message['author'] === 'ai' ? 'assistant' : 'system');
            $who = $message['author'] === 'customer' ? 'Visitor' : ($message['author'] === 'ai' ? 'AI Operator' : ($message['author'] === 'agent' ? 'Agent' : 'System'));
            echo '<div class="ch247ai-msg ' . $class . '"><strong>' . $who . '</strong>'
                . ' <span class="text-muted small">' . $this->e((string) $message['created_at']) . '</span><br>'
                . $this->e($message['body']) . '</div>';
        }
        echo '</div>';

        if (!$manage) {
            echo '<div class="alert alert-info">You can read this conversation but need the AI manage permission to reply or change it.</div>';
            return;
        }
        echo '<div class="row"><div class="col-md-7"><div class="panel panel-default"><div class="panel-heading"><strong>Reply as support agent</strong></div><div class="panel-body">'
            . '<form method="post">' . Csrf::field() . '<input type="hidden" name="ch247ai_action" value="support_reply">'
            . '<input type="hidden" name="id" value="' . $id . '">'
            . '<div class="form-group"><textarea class="form-control" name="reply" rows="4" maxlength="4000" placeholder="Write to the visitor…"></textarea></div>'
            . '<button class="btn btn-primary btn-sm">Send reply</button>'
            . ' <span class="small text-muted">Mirrored into the linked ticket as a note.</span></form>'
            . '</div></div></div><div class="col-md-5">'
            . '<div class="panel panel-default"><div class="panel-heading"><strong>Assign</strong></div><div class="panel-body">'
            . '<form method="post" class="form-inline">' . Csrf::field() . '<input type="hidden" name="ch247ai_action" value="support_assign">'
            . '<input type="hidden" name="id" value="' . $id . '"><select class="form-control input-sm" name="admin_id">';
        foreach (Db::query('SELECT id, username FROM tbladmins ORDER BY id ASC') as $admin) {
            $selected = (int) $conv['assigned_admin_id'] === (int) $admin['id'] ? ' selected' : '';
            echo '<option value="' . (int) $admin['id'] . '"' . $selected . '>#' . (int) $admin['id'] . ' ' . $this->e($admin['username']) . '</option>';
        }
        echo '</select> <button class="btn btn-default btn-sm">Assign</button></form></div></div>'
            . '<div class="panel panel-default"><div class="panel-heading"><strong>Status</strong></div><div class="panel-body">';
        foreach (['resolved' => 'Resolve', 'ai_active' => 'Return to AI', 'closed' => 'Close'] as $to => $label) {
            echo '<form method="post" style="display:inline;margin-right:6px">' . Csrf::field()
                . '<input type="hidden" name="ch247ai_action" value="support_status">'
                . '<input type="hidden" name="id" value="' . $id . '">'
                . '<input type="hidden" name="to" value="' . $to . '">'
                . '<button class="btn btn-default btn-sm">' . $label . '</button></form>';
        }
        echo '</div></div></div></div>';
    }

    private function newsletter()
    {
        echo '<h2>Newsletter subscriptions</h2>';
        if (!$this->canView()) {
            echo '<div class="alert alert-danger">You need the ai.client.read permission to view newsletter subscriptions.</div>';
            return;
        }
        if (NewsletterBridge::marketingAvailable()) {
            echo '<p class="small text-muted">The marketing module is installed: new AI-collected subscriptions are mirrored into its audience store. This table is the AI-side record.</p>';
        } else {
            echo '<p class="small text-muted">The marketing module is not installed: subscriptions are kept in this local list only. Install it to sync audiences automatically.</p>';
        }
        $status = (string) ($_GET['status'] ?? '');
        $page = max(1, (int) ($_GET['p'] ?? 1));
        echo '<form method="get" class="form-inline" style="margin:12px 0"><input type="hidden" name="module" value="cloudhost247ai">'
            . '<input type="hidden" name="action" value="support-newsletter">'
            . '<select class="form-control input-sm" name="status"><option value="">All</option>'
            . '<option value="subscribed"' . ($status === 'subscribed' ? ' selected' : '') . '>Subscribed</option>'
            . '<option value="unsubscribed"' . ($status === 'unsubscribed' ? ' selected' : '') . '>Unsubscribed</option>'
            . '</select> <button class="btn btn-default btn-sm">Filter</button></form>';
        $list = NewsletterBridge::list($status, $page);
        $manage = Rbac::adminCan(Rbac::AI_MANAGE);
        echo '<div class="table-responsive"><table class="table table-striped"><thead><tr>'
            . '<th>Name</th><th>Email</th><th>Status</th><th>Source</th><th>Since</th><th></th></tr></thead><tbody>';
        foreach ($list['rows'] as $row) {
            echo '<tr><td>' . $this->e($row['name']) . '</td><td>' . $this->e($row['email']) . '</td>'
                . '<td>' . $this->e($row['status']) . '</td><td>' . $this->e($row['source']) . '</td>'
                . '<td class="small">' . $this->e((string) $row['created_at']) . '</td><td>';
            if ($manage && $row['status'] === 'subscribed') {
                echo '<form method="post" style="display:inline">' . Csrf::field()
                    . '<input type="hidden" name="ch247ai_action" value="support_newsletter_unsub">'
                    . '<input type="hidden" name="email" value="' . $this->e($row['email']) . '">'
                    . '<button class="btn btn-xs btn-default">Unsubscribe</button></form>';
            }
            echo '</td></tr>';
        }
        if ($list['rows'] === []) {
            echo '<tr><td colspan="6" class="text-muted">No AI-collected subscriptions yet.</td></tr>';
        }
        echo '</tbody></table></div>';
    }

    private function stat($label, $value)
    {
        return '<div class="col-md-2 col-sm-4"><div class="ch247ai-stat"><div class="number">' . (int) $value . '</div><div class="text-muted small">' . $this->e($label) . '</div></div></div>';
    }

    private function statusPill($status)
    {
        $map = [
            'ai_active' => 'info',
            'waiting_for_human' => 'warning',
            'human_active' => 'success',
            'resolved' => 'default',
            'closed' => 'default',
        ];
        $class = isset($map[$status]) ? $map[$status] : 'default';
        return '<span class="label label-' . $class . '">' . $this->e($status) . '</span>';
    }

    private function e($value)
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }

    private function u($action, array $params = [])
    {
        $url = $this->moduleLink . '&action=' . rawurlencode($action);
        foreach ($params as $key => $value) {
            $url .= '&' . rawurlencode($key) . '=' . rawurlencode((string) $value);
        }
        return htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
    }
}
