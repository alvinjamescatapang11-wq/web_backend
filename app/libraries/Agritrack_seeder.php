<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

require_once APP_DIR . 'libraries/Audit_logger.php';

class Agritrack_seeder
{
    private $db;

    public function __construct()
    {
        $this->db = lava_instance()->call->database();
    }

    public function create_cao_admin()
    {
        $email = strtolower(trim(getenv('AGRI_ADMIN_EMAIL') ?: ''));
        $password = getenv('AGRI_ADMIN_PASSWORD') ?: '';
        $name = trim(getenv('AGRI_ADMIN_NAME') ?: '');

        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 255) {
            throw new InvalidArgumentException('Set a valid AGRI_ADMIN_EMAIL in the backend .env before seeding the administrator.');
        }
        if (strlen($password) < 12 || strlen($password) > 1024) {
            throw new InvalidArgumentException('AGRI_ADMIN_PASSWORD must contain between 12 and 1024 characters.');
        }
        if ($name === '' || strlen($name) > 150) {
            throw new InvalidArgumentException('Set AGRI_ADMIN_NAME (1 to 150 characters) in the backend .env.');
        }

        $existing = $this->db->raw(
            'SELECT users.id, roles.slug AS role_slug
             FROM users
             INNER JOIN roles ON roles.id = users.role_id
             WHERE users.email = ?
             LIMIT 1',
            [$email]
        )->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            if ($existing['role_slug'] === 'cao_staff') {
                $adminRoleId = $this->db->raw(
                    'SELECT id FROM roles WHERE slug = ? LIMIT 1',
                    ['cao_admin']
                )->fetchColumn();
                if (!$adminRoleId) {
                    throw new RuntimeException('The CAO Administrator role is missing. Run the migrations first.');
                }

                $this->db->transaction();
                try {
                    $this->db->raw(
                        'UPDATE users
                         SET role = ?, role_id = ?, updated_at = UTC_TIMESTAMP()
                         WHERE id = ?',
                        ['cao_admin', (int) $adminRoleId, (int) $existing['id']]
                    );
                    (new Audit_logger($this->db))->record(
                        (int) $existing['id'],
                        'cao_admin',
                        null,
                        'bootstrap_admin_promoted',
                        'user_administration',
                        (int) $existing['id'],
                        ['role' => 'cao_staff'],
                        ['role' => 'cao_admin']
                    );
                    $this->db->commit();
                } catch (Throwable $exception) {
                    $this->db->roll_back();
                    throw $exception;
                }

                return ['created' => false, 'message' => 'Promoted the explicitly configured bootstrap account to CAO Administrator.'];
            }

            if ($existing['role_slug'] !== 'cao_admin') {
                throw new RuntimeException('The configured administrator email already belongs to a non-CAO account.');
            }

            return ['created' => false, 'message' => 'The configured CAO Staff administrator already exists; no account changes were made.'];
        }

        $usernameBase = strtolower((string) strstr($email, '@', true));
        $usernameBase = preg_replace('/[^a-z0-9._-]/', '', $usernameBase);
        $usernameBase = substr($usernameBase ?: 'cao-admin', 0, 90);
        $username = $usernameBase;
        $suffix = 1;

        while ($this->username_exists($username)) {
            $username = substr($usernameBase, 0, 90) . $suffix;
            $suffix++;
        }

        $roleId = $this->db->raw(
            'SELECT id FROM roles WHERE slug = ? LIMIT 1',
            ['cao_admin']
        )->fetchColumn();
        if (!$roleId) {
            throw new RuntimeException('The CAO Administrator role is missing. Run the migrations first.');
        }

        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        if ($passwordHash === false) {
            throw new RuntimeException('Password hashing failed; no administrator account was created.');
        }

        $this->db->transaction();
        try {
            $this->db->raw(
                'INSERT INTO users (username, email, password, role, role_id, full_name, is_active)
                 VALUES (?, ?, ?, ?, ?, ?, 1)',
                [$username, $email, $passwordHash, 'cao_admin', (int) $roleId, $name]
            );
            $userId = (int) $this->db->raw('SELECT LAST_INSERT_ID()')->fetchColumn();

            (new Audit_logger($this->db))->record(
                $userId,
                'cao_admin',
                null,
                'bootstrap_admin_created',
                'user_administration',
                $userId
            );
            $this->db->commit();
        } catch (Throwable $exception) {
            $this->db->roll_back();
            throw $exception;
        }

        return ['created' => true, 'message' => 'Created the initial CAO Administrator. Store the configured credentials securely.'];
    }

    private function username_exists($username)
    {
        return (bool) $this->db->raw(
            'SELECT 1 FROM users WHERE username = ? LIMIT 1',
            [$username]
        )->fetchColumn();
    }
}
