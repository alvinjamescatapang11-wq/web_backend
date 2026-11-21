<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

require_once APP_DIR . 'libraries/Audit_logger.php';
require_once APP_DIR . 'libraries/Mail_queue.php';

class Operations_model extends Model
{
    public function __construct()
    {
        parent::__construct();
        lava_instance()->call->database();
    }

    public function notifications(array $user)
    {
        return $this->db->raw(
            'SELECT id, title, message, notification_type, related_module,
                    related_record_id, read_at, created_at
             FROM notifications WHERE user_id = ?
             ORDER BY created_at DESC, id DESC LIMIT 100',
            [(int) $user['id']]
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    public function mark_notification_read(array $user, $id)
    {
        $result = $this->db->raw(
            'UPDATE notifications SET read_at = COALESCE(read_at, UTC_TIMESTAMP())
             WHERE id = ? AND user_id = ?',
            [(int) $id, (int) $user['id']]
        );
        return $result->rowCount() > 0 || $this->db->raw(
            'SELECT id FROM notifications WHERE id = ? AND user_id = ? AND read_at IS NOT NULL LIMIT 1',
            [(int) $id, (int) $user['id']]
        )->fetchColumn();
    }

    public function announcements(array $user)
    {
        $where = ["announcements.status = 'published'", 'announcements.deleted_at IS NULL'];
        $params = [];
        if (!in_array($user['role_slug'], ['cao_admin', 'cao_staff'], true)) {
            $where[] = '(announcements.audience_barangay_id IS NULL OR announcements.audience_barangay_id = ?)';
            $params[] = (int) $user['barangay_id'];
        }
        return $this->db->raw(
            'SELECT announcements.id, announcements.title, announcements.content,
                    announcements.audience_barangay_id, announcements.status,
                    announcements.published_at, barangays.name AS barangay_name,
                    users.full_name AS author_name
             FROM announcements
             LEFT JOIN barangays ON barangays.id = announcements.audience_barangay_id
             LEFT JOIN users ON users.id = announcements.created_by
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY announcements.published_at DESC, announcements.id DESC
             LIMIT 100',
            $params
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    public function create_announcement(array $user, array $data)
    {
        $barangayId = $user['role_slug'] === 'barangay_personnel'
            ? (int) $user['barangay_id']
            : $data['barangayId'];
        if ($barangayId !== null) {
            $active = $this->db->raw(
                'SELECT id FROM barangays WHERE id = ? AND is_active = 1 AND deleted_at IS NULL LIMIT 1',
                [$barangayId]
            )->fetchColumn();
            if (!$active) {
                throw new InvalidArgumentException('Choose an active barangay audience.');
            }
        }

        $this->db->transaction();
        try {
            $this->db->raw(
                'INSERT INTO announcements
                    (title, content, audience_barangay_id, status, published_at, created_by)
                 VALUES (?, ?, ?, ?, ?, ?)',
                [
                    $data['title'],
                    $data['content'],
                    $barangayId,
                    $data['status'],
                    $data['status'] === 'published' ? gmdate('Y-m-d H:i:s') : null,
                    (int) $user['id'],
                ]
            );
            $id = (int) $this->db->raw('SELECT LAST_INSERT_ID()')->fetchColumn();
            if ($data['status'] === 'published') {
                $where = [
                    'users.is_active = 1',
                    'users.deleted_at IS NULL',
                    'user_email_preferences.announcements_enabled = 1',
                    'users.email IS NOT NULL',
                    "users.email <> ''",
                ];
                $params = [];
                if ($barangayId !== null) {
                    $where[] = '(users.barangay_id = ? OR roles.slug IN (?, ?))';
                    $params = [$barangayId, 'cao_admin', 'cao_staff'];
                }
                $recipients = $this->db->raw(
                    'SELECT users.id, users.email, users.barangay_id, users.full_name, users.username
                     FROM users
                     INNER JOIN roles ON roles.id = users.role_id
                     INNER JOIN user_email_preferences ON user_email_preferences.user_id = users.id
                     WHERE ' . implode(' AND ', $where),
                    $params
                )->fetchAll(PDO::FETCH_ASSOC);
                $queue = new Mail_queue($this->db);
                foreach ($recipients as $recipient) {
                    $name = $recipient['full_name'] ?: $recipient['username'];
                    $queue->enqueue(
                        $recipient['email'],
                        'AGRI-TRACK announcement: ' . $data['title'],
                        "Hello {$name},\n\n{$data['title']}\n\n{$data['content']}",
                        'announcement',
                        (int) $recipient['id'],
                        $barangayId === null ? null : (int) $recipient['barangay_id']
                    );
                }
            }
            (new Audit_logger($this->db))->record(
                (int) $user['id'], $user['role_slug'], $barangayId,
                'create', 'announcements', $id, null,
                ['title' => $data['title'], 'status' => $data['status']]
            );
            $this->db->commit();
            return $id;
        } catch (Throwable $exception) {
            $this->db->roll_back();
            throw $exception;
        }
    }

    public function messages(array $user)
    {
        $where = ['messages.deleted_at IS NULL'];
        $params = [];
        if (!in_array($user['role_slug'], ['cao_admin', 'cao_staff'], true)) {
            $where[] = '(messages.barangay_id IS NULL OR messages.barangay_id = ?)';
            $params[] = (int) $user['barangay_id'];
        }
        return $this->db->raw(
            'SELECT messages.id, messages.conversation_key, messages.sender_id,
                    messages.barangay_id, messages.body, messages.created_at,
                    barangays.name AS barangay_name, users.full_name AS sender_name,
                    users.role AS sender_role
             FROM messages
             INNER JOIN users ON users.id = messages.sender_id
             LEFT JOIN barangays ON barangays.id = messages.barangay_id
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY messages.created_at DESC, messages.id DESC LIMIT 100',
            $params
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    public function create_message(array $user, array $data)
    {
        $barangayId = null;
        if ($user['role_slug'] === 'barangay_personnel' || $user['role_slug'] === 'farmer') {
            $barangayId = (int) $user['barangay_id'];
        } elseif ($data['barangayId'] !== null) {
            $barangayId = $data['barangayId'];
            $active = $this->db->raw(
                'SELECT id FROM barangays WHERE id = ? AND is_active = 1 AND deleted_at IS NULL LIMIT 1',
                [$barangayId]
            )->fetchColumn();
            if (!$active) {
                throw new InvalidArgumentException('Choose an active barangay conversation.');
            }
        }

        $this->db->transaction();
        try {
            $this->db->raw(
                'INSERT INTO messages (conversation_key, sender_id, barangay_id, body)
                 VALUES (?, ?, ?, ?)',
                [$data['conversationKey'], (int) $user['id'], $barangayId, $data['body']]
            );
            $id = (int) $this->db->raw('SELECT LAST_INSERT_ID()')->fetchColumn();
            (new Audit_logger($this->db))->record(
                (int) $user['id'], $user['role_slug'], $barangayId,
                'send', 'messages', $id
            );
            $this->db->commit();
            return $id;
        } catch (Throwable $exception) {
            $this->db->roll_back();
            throw $exception;
        }
    }

    public function calendar_events(array $user)
    {
        $where = [];
        $params = [];
        if (!in_array($user['role_slug'], ['cao_admin', 'cao_staff'], true)) {
            $where[] = '(calendar_events.barangay_id IS NULL OR calendar_events.barangay_id = ?)';
            $params[] = (int) $user['barangay_id'];
        }
        return $this->db->raw(
            'SELECT calendar_events.id, calendar_events.barangay_id, calendar_events.title,
                    calendar_events.description, calendar_events.event_type,
                    calendar_events.starts_at, calendar_events.ends_at,
                    barangays.name AS barangay_name
             FROM calendar_events
             LEFT JOIN barangays ON barangays.id = calendar_events.barangay_id'
             . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . '
             ORDER BY calendar_events.starts_at DESC LIMIT 200',
            $params
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    public function create_calendar_feed_token(array $user, $tokenHash)
    {
        $this->db->raw(
            'INSERT INTO calendar_feed_tokens (user_id, token_hash, created_at, revoked_at)
             VALUES (?, ?, UTC_TIMESTAMP(), NULL)
             ON DUPLICATE KEY UPDATE token_hash = VALUES(token_hash),
                 created_at = UTC_TIMESTAMP(), revoked_at = NULL',
            [(int) $user['id'], $tokenHash]
        );
        (new Audit_logger($this->db))->record(
            (int) $user['id'],
            $user['role_slug'],
            $user['barangay_id'] === null ? null : (int) $user['barangay_id'],
            'calendar_feed_created',
            'calendar_feed_tokens'
        );
    }

    public function revoke_calendar_feed_token(array $user)
    {
        $result = $this->db->raw(
            'UPDATE calendar_feed_tokens SET revoked_at = UTC_TIMESTAMP()
             WHERE user_id = ? AND revoked_at IS NULL',
            [(int) $user['id']]
        );
        if ($result->rowCount() > 0) {
            (new Audit_logger($this->db))->record(
                (int) $user['id'],
                $user['role_slug'],
                $user['barangay_id'] === null ? null : (int) $user['barangay_id'],
                'calendar_feed_revoked',
                'calendar_feed_tokens'
            );
        }
        return $result->rowCount() > 0;
    }

    public function calendar_feed_events($tokenHash)
    {
        $user = $this->db->raw(
            'SELECT users.id, users.role_id, users.barangay_id, roles.slug AS role_slug
             FROM calendar_feed_tokens
             INNER JOIN users ON users.id = calendar_feed_tokens.user_id
             INNER JOIN roles ON roles.id = users.role_id
             WHERE calendar_feed_tokens.token_hash = ?
               AND calendar_feed_tokens.revoked_at IS NULL
               AND users.is_active = 1 AND users.deleted_at IS NULL
             LIMIT 1',
            [$tokenHash]
        )->fetch(PDO::FETCH_ASSOC);
        if (!$user) {
            return false;
        }

        $where = [];
        $params = [];
        if (!in_array($user['role_slug'], ['cao_admin', 'cao_staff'], true)) {
            $where[] = '(calendar_events.barangay_id IS NULL OR calendar_events.barangay_id = ?)';
            $params[] = (int) $user['barangay_id'];
        }
        return $this->db->raw(
            'SELECT calendar_events.id, calendar_events.title, calendar_events.description,
                    calendar_events.event_type, calendar_events.starts_at, calendar_events.ends_at,
                    barangays.name AS barangay_name
             FROM calendar_events
             LEFT JOIN barangays ON barangays.id = calendar_events.barangay_id'
             . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . '
             ORDER BY calendar_events.starts_at ASC LIMIT 500',
            $params
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    public function create_calendar_event(array $user, array $data)
    {
        $barangayId = $user['role_slug'] === 'barangay_personnel'
            ? (int) $user['barangay_id']
            : $data['barangayId'];
        if ($barangayId !== null) {
            $active = $this->db->raw(
                'SELECT id FROM barangays WHERE id = ? AND is_active = 1 AND deleted_at IS NULL LIMIT 1',
                [$barangayId]
            )->fetchColumn();
            if (!$active) {
                throw new InvalidArgumentException('Choose an active barangay audience.');
            }
        }

        $this->db->transaction();
        try {
            $this->db->raw(
                'INSERT INTO calendar_events
                    (barangay_id, title, description, event_type, starts_at, ends_at, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?)',
                [
                    $barangayId, $data['title'], $data['description'], $data['eventType'],
                    $data['startsAt'], $data['endsAt'], (int) $user['id'],
                ]
            );
            $id = (int) $this->db->raw('SELECT LAST_INSERT_ID()')->fetchColumn();
            (new Audit_logger($this->db))->record(
                (int) $user['id'], $user['role_slug'], $barangayId,
                'create', 'calendar_events', $id, null, ['title' => $data['title']]
            );
            $this->db->commit();
            return $id;
        } catch (Throwable $exception) {
            $this->db->roll_back();
            throw $exception;
        }
    }

    public function audit_logs()
    {
        return $this->db->raw(
            'SELECT audit_logs.id, audit_logs.user_id, audit_logs.role_slug,
                    audit_logs.barangay_id, audit_logs.action, audit_logs.module,
                    audit_logs.record_id, audit_logs.old_values, audit_logs.new_values,
                    audit_logs.created_at, users.full_name AS actor_name,
                    barangays.name AS barangay_name
             FROM audit_logs
             LEFT JOIN users ON users.id = audit_logs.user_id
             LEFT JOIN barangays ON barangays.id = audit_logs.barangay_id
             ORDER BY audit_logs.created_at DESC, audit_logs.id DESC LIMIT 200'
        )->fetchAll(PDO::FETCH_ASSOC);
    }
}
