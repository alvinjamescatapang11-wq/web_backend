<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

require_once APP_DIR . 'libraries/Audit_logger.php';

class Auth_model extends Model
{
    public function __construct()
    {
        parent::__construct();
        lava_instance()->call->database();
    }

    public function find_by_email($email)
    {
        return $this->db->raw(
            'SELECT users.id, users.username, users.email, users.password, users.google_sub,
                    users.role_id, users.barangay_id, users.full_name, users.is_active,
                    roles.slug AS role_slug, roles.name AS role_name,
                    farmers.id AS farmer_id
             FROM users
             LEFT JOIN roles ON roles.id = users.role_id
             LEFT JOIN farmers ON farmers.user_id = users.id
                AND farmers.deleted_at IS NULL
                AND farmers.is_active = 1
             WHERE users.email = ? AND users.deleted_at IS NULL
             LIMIT 1',
            [$email]
        )->fetch(PDO::FETCH_ASSOC);
    }

    public function attach_google_subject($userId, $subject)
    {
        $this->db->raw(
            'UPDATE users SET google_sub = ?
             WHERE id = ? AND (google_sub IS NULL OR google_sub = ?)',
            [$subject, (int) $userId, $subject]
        );
        return (bool) $this->db->raw(
            'SELECT id FROM users WHERE id = ? AND google_sub = ? LIMIT 1',
            [(int) $userId, $subject]
        )->fetchColumn();
    }

    public function find_user_by_token_hash($tokenHash)
    {
        $user = $this->db->raw(
            'SELECT users.id, users.username, users.email, users.full_name,
                    users.role_id, users.barangay_id, roles.slug AS role_slug,
                    roles.name AS role_name, barangays.name AS barangay_name,
                    farmers.id AS farmer_id
             FROM user_sessions
             INNER JOIN users ON users.id = user_sessions.user_id
             INNER JOIN roles ON roles.id = users.role_id
             LEFT JOIN barangays ON barangays.id = users.barangay_id
             LEFT JOIN farmers ON farmers.user_id = users.id
                AND farmers.deleted_at IS NULL
                AND farmers.is_active = 1
             WHERE user_sessions.token_hash = ?
               AND user_sessions.revoked_at IS NULL
               AND user_sessions.expires_at > UTC_TIMESTAMP()
               AND users.is_active = 1
               AND users.deleted_at IS NULL
             LIMIT 1',
            [$tokenHash]
        )->fetch(PDO::FETCH_ASSOC);

        if ($user) {
            $this->db->raw(
                'UPDATE user_sessions SET last_used_at = UTC_TIMESTAMP()
                 WHERE token_hash = ? AND revoked_at IS NULL',
                [$tokenHash]
            );
        }

        return $user;
    }

    public function issue_session($userId, $tokenHash, $expiresAt, $ipAddress, $userAgent)
    {
        $this->db->raw(
            'INSERT INTO user_sessions
                (user_id, token_hash, expires_at, ip_address, user_agent)
             VALUES (?, ?, ?, ?, ?)',
            [$userId, $tokenHash, $expiresAt, $ipAddress, $userAgent]
        );
    }

    public function revoke_session($tokenHash)
    {
        $this->db->raw(
            'UPDATE user_sessions SET revoked_at = UTC_TIMESTAMP()
             WHERE token_hash = ? AND revoked_at IS NULL',
            [$tokenHash]
        );
    }

    public function update_profile(array $user, array $changes)
    {
        $current = $this->db->raw(
            'SELECT id, username, email, full_name, password
             FROM users
             WHERE id = ? AND is_active = 1 AND deleted_at IS NULL
             LIMIT 1',
            [(int) $user['id']]
        )->fetch(PDO::FETCH_ASSOC);
        if (!$current) {
            return false;
        }

        $sensitiveChange = (
            array_key_exists('email', $changes) && $changes['email'] !== $current['email']
        ) || array_key_exists('password', $changes);
        if ($sensitiveChange && (
            !isset($changes['currentPassword']) ||
            !password_verify($changes['currentPassword'], $current['password'])
        )) {
            throw new InvalidArgumentException('Your current password is incorrect.');
        }

        $sets = [];
        $params = [];
        $nextName = array_key_exists('fullName', $changes) ? $changes['fullName'] : $current['full_name'];
        $nextEmail = array_key_exists('email', $changes) ? $changes['email'] : $current['email'];

        if ($nextName !== $current['full_name']) {
            $sets[] = 'full_name = ?';
            $params[] = $nextName;
        }
        if ($nextEmail !== $current['email']) {
            $sets[] = 'email = ?';
            $params[] = $nextEmail;
        }
        if (array_key_exists('password', $changes)) {
            if (password_verify($changes['password'], $current['password'])) {
                throw new InvalidArgumentException('Choose a password different from your current password.');
            }
            $hash = password_hash($changes['password'], PASSWORD_DEFAULT);
            if ($hash === false) {
                throw new RuntimeException('Password hashing failed.');
            }
            $sets[] = 'password = ?';
            $params[] = $hash;
        }
        if (!$sets) {
            return [
                'id' => (int) $current['id'],
                'username' => $current['username'],
                'email' => $current['email'],
                'name' => $current['full_name'] ?: $current['username'],
                'passwordChanged' => false,
            ];
        }

        $this->db->transaction();
        try {
            $this->db->raw(
                'UPDATE users SET ' . implode(', ', $sets) . ', updated_at = UTC_TIMESTAMP()
                 WHERE id = ? AND is_active = 1 AND deleted_at IS NULL',
                array_merge($params, [(int) $user['id']])
            );
            if (array_key_exists('password', $changes)) {
                $this->db->raw(
                    'UPDATE user_sessions SET revoked_at = UTC_TIMESTAMP()
                     WHERE user_id = ? AND revoked_at IS NULL',
                    [(int) $user['id']]
                );
            }

            (new Audit_logger($this->db))->record(
                (int) $user['id'],
                $user['role_slug'],
                $user['barangay_id'] === null ? null : (int) $user['barangay_id'],
                array_key_exists('password', $changes) ? 'credentials_changed' : 'profile_updated',
                'user_profile',
                (int) $user['id'],
                ['email' => $current['email'], 'full_name' => $current['full_name']],
                ['email' => $nextEmail, 'full_name' => $nextName, 'password_changed' => array_key_exists('password', $changes)]
            );
            $this->db->commit();
        } catch (Throwable $exception) {
            $this->db->roll_back();
            throw $exception;
        }

        return [
            'id' => (int) $current['id'],
            'username' => $current['username'],
            'email' => $nextEmail,
            'name' => $nextName ?: $current['username'],
            'passwordChanged' => array_key_exists('password', $changes),
        ];
    }

    public function failed_attempts_in_window($identityHash, $ipAddress)
    {
        return (int) $this->db->raw(
            'SELECT COUNT(*) FROM auth_login_attempts
             WHERE was_successful = 0
               AND attempted_at >= UTC_TIMESTAMP() - INTERVAL 15 MINUTE
               AND (identity_hash = ? OR ip_address = ?)',
            [$identityHash, $ipAddress]
        )->fetchColumn();
    }

    public function record_login_attempt($identityHash, $ipAddress, $successful)
    {
        $this->db->raw(
            'DELETE FROM auth_login_attempts
             WHERE attempted_at < UTC_TIMESTAMP() - INTERVAL 1 DAY'
        );
        $this->db->raw(
            'INSERT INTO auth_login_attempts (identity_hash, ip_address, was_successful, attempted_at)
             VALUES (?, ?, ?, UTC_TIMESTAMP())',
            [$identityHash, $ipAddress, $successful ? 1 : 0]
        );
    }

    public function request_password_reset($email, $tokenHash, $expiresAt, $resetUrl)
    {
        $user = $this->db->raw(
            'SELECT id, email, full_name, username
             FROM users
             WHERE email = ? AND is_active = 1 AND deleted_at IS NULL
             LIMIT 1',
            [$email]
        )->fetch(PDO::FETCH_ASSOC);
        if (!$user) {
            return false;
        }

        $this->db->transaction();
        try {
            $this->db->raw(
                'UPDATE password_reset_tokens SET used_at = UTC_TIMESTAMP()
                 WHERE user_id = ? AND used_at IS NULL',
                [(int) $user['id']]
            );
            $this->db->raw(
                "UPDATE email_outbox
                 SET status = 'failed', body = '', last_error = 'Superseded by a newer password reset request.'
                 WHERE user_id = ? AND mail_type = 'password_reset' AND status = 'queued'",
                [(int) $user['id']]
            );
            $this->db->raw(
                'INSERT INTO password_reset_tokens (user_id, token_hash, expires_at)
                 VALUES (?, ?, ?)',
                [(int) $user['id'], $tokenHash, $expiresAt]
            );

            require_once APP_DIR . 'libraries/Mail_queue.php';
            $name = $user['full_name'] ?: $user['username'];
            (new Mail_queue($this->db))->enqueue(
                $user['email'],
                'Reset your AGRI-TRACK password',
                "Hello {$name},\n\nUse the following link to choose a new password. It expires in one hour:\n{$resetUrl}\n\nIf you did not request this, you can ignore this message.",
                'password_reset',
                (int) $user['id']
            );
            $this->db->commit();
            return true;
        } catch (Throwable $exception) {
            $this->db->roll_back();
            throw $exception;
        }
    }

    public function password_reset_requests_in_window($identityHash, $ipAddress)
    {
        return (int) $this->db->raw(
            'SELECT COUNT(*) FROM password_reset_requests
             WHERE requested_at >= UTC_TIMESTAMP() - INTERVAL 15 MINUTE
               AND (identity_hash = ? OR ip_address = ?)',
            [$identityHash, $ipAddress]
        )->fetchColumn();
    }

    public function record_password_reset_request($identityHash, $ipAddress)
    {
        $this->db->raw(
            'DELETE FROM password_reset_requests
             WHERE requested_at < UTC_TIMESTAMP() - INTERVAL 1 DAY'
        );
        $this->db->raw(
            'INSERT INTO password_reset_requests (identity_hash, ip_address, requested_at)
             VALUES (?, ?, UTC_TIMESTAMP())',
            [$identityHash, $ipAddress]
        );
    }

    public function complete_password_reset($tokenHash, $passwordHash)
    {
        $this->db->transaction();
        try {
            $token = $this->db->raw(
                'SELECT password_reset_tokens.id, password_reset_tokens.user_id,
                        users.barangay_id, roles.slug AS role_slug
                 FROM password_reset_tokens
                 INNER JOIN users ON users.id = password_reset_tokens.user_id
                 LEFT JOIN roles ON roles.id = users.role_id
                 WHERE password_reset_tokens.token_hash = ?
                   AND password_reset_tokens.used_at IS NULL
                   AND password_reset_tokens.expires_at > UTC_TIMESTAMP()
                 LIMIT 1 FOR UPDATE',
                [$tokenHash]
            )->fetch(PDO::FETCH_ASSOC);
            if (!$token) {
                $this->db->roll_back();
                return false;
            }

            $updated = $this->db->raw(
                'UPDATE users SET password = ?, updated_at = UTC_TIMESTAMP()
                 WHERE id = ? AND is_active = 1 AND deleted_at IS NULL',
                [$passwordHash, (int) $token['user_id']]
            );
            if ($updated->rowCount() !== 1) {
                $this->db->roll_back();
                return false;
            }
            $this->db->raw(
                'UPDATE password_reset_tokens SET used_at = UTC_TIMESTAMP()
                 WHERE user_id = ? AND used_at IS NULL',
                [(int) $token['user_id']]
            );
            $this->db->raw(
                'UPDATE user_sessions SET revoked_at = UTC_TIMESTAMP()
                 WHERE user_id = ? AND revoked_at IS NULL',
                [(int) $token['user_id']]
            );
            (new Audit_logger($this->db))->record(
                (int) $token['user_id'],
                $token['role_slug'],
                $token['barangay_id'] === null ? null : (int) $token['barangay_id'],
                'password_reset_completed',
                'authentication'
            );
            $this->db->commit();
            return true;
        } catch (Throwable $exception) {
            $this->db->roll_back();
            throw $exception;
        }
    }

    public function update_announcement_email_preference(array $user, $enabled)
    {
        $this->db->raw(
            'INSERT INTO user_email_preferences (user_id, announcements_enabled)
             VALUES (?, ?)
             ON DUPLICATE KEY UPDATE announcements_enabled = VALUES(announcements_enabled)',
            [(int) $user['id'], $enabled ? 1 : 0]
        );
        return ['announcementsEnabled' => (bool) $enabled];
    }

    public function announcement_email_preference(array $user)
    {
        return (bool) $this->db->raw(
            'SELECT announcements_enabled FROM user_email_preferences
             WHERE user_id = ? LIMIT 1',
            [(int) $user['id']]
        )->fetchColumn();
    }
}
