<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

require_once APP_DIR . 'libraries/Audit_logger.php';

class Mail_outbox_model extends Model
{
    public function __construct()
    {
        parent::__construct();
        lava_instance()->call->database();
    }

    public function send_pending($limit = 20)
    {
        $host = getenv('MAIL_HOST') ?: 'smtp.gmail.com';
        $port = (int) (getenv('MAIL_PORT') ?: 587);
        $username = getenv('MAIL_USERNAME') ?: '';
        $password = getenv('MAIL_PASSWORD') ?: '';
        $fromEmail = getenv('MAIL_FROM_EMAIL') ?: $username;
        $fromName = getenv('MAIL_FROM_NAME') ?: 'AGRI-TRACK';
        if ($username === '' || $password === '' || $fromEmail === '' || $port < 1 || $port > 65535) {
            throw new RuntimeException('Configure MAIL_USERNAME, MAIL_PASSWORD, MAIL_FROM_EMAIL, and MAIL_PORT before sending queued email.');
        }

        $autoload = dirname(APP_DIR) . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
        if (!is_file($autoload)) {
            throw new RuntimeException('Install backend Composer dependencies before sending queued email.');
        }
        require_once $autoload;
        if (!class_exists(\PHPMailer\PHPMailer\PHPMailer::class)) {
            throw new RuntimeException('PHPMailer is not installed. Run composer install in the backend directory.');
        }

        $limit = max(1, min(100, (int) $limit));
        $sent = 0;
        $failed = 0;
        for ($count = 0; $count < $limit; $count++) {
            $job = $this->claim_next();
            if (!$job) {
                break;
            }

            try {
                $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
                $mail->isSMTP();
                $mail->Host = $host;
                $mail->SMTPAuth = true;
                $mail->Username = $username;
                $mail->Password = $password;
                $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
                $mail->Port = $port;
                $mail->Timeout = 10;
                $mail->CharSet = 'UTF-8';
                $mail->setFrom($fromEmail, $fromName);
                $mail->addAddress($job['recipient']);
                $mail->Subject = $job['subject'];
                $mail->Body = $job['body'];
                $mail->isHTML(false);
                $mail->send();
                $this->mark_sent($job);
                $sent++;
            } catch (Throwable $exception) {
                $this->mark_failed($job, $exception->getMessage());
                $failed++;
            }
        }

        return ['sent' => $sent, 'failed' => $failed];
    }

    private function claim_next()
    {
        $this->db->transaction();
        try {
            $job = $this->db->raw(
                "SELECT id, user_id, barangay_id, mail_type, recipient, subject, body, attempt_count
                 FROM email_outbox
                 WHERE (status = 'queued' AND next_attempt_at <= UTC_TIMESTAMP())
                    OR (status = 'sending' AND locked_at < UTC_TIMESTAMP() - INTERVAL 15 MINUTE)
                 ORDER BY id
                 LIMIT 1 FOR UPDATE"
            )->fetch(PDO::FETCH_ASSOC);
            if (!$job) {
                $this->db->commit();
                return null;
            }

            $this->db->raw(
                "UPDATE email_outbox
                 SET status = 'sending', locked_at = UTC_TIMESTAMP(),
                     attempt_count = attempt_count + 1
                 WHERE id = ?",
                [(int) $job['id']]
            );
            $job['attempt_count'] = (int) $job['attempt_count'] + 1;
            $this->db->commit();
            return $job;
        } catch (Throwable $exception) {
            $this->db->roll_back();
            throw $exception;
        }
    }

    private function mark_sent(array $job)
    {
        $this->db->raw(
            "UPDATE email_outbox
             SET status = 'sent', sent_at = UTC_TIMESTAMP(), locked_at = NULL,
                 last_error = NULL, body = ''
             WHERE id = ? AND status = 'sending'",
            [(int) $job['id']]
        );
        (new Audit_logger($this->db))->record(
            null,
            null,
            $job['barangay_id'] === null ? null : (int) $job['barangay_id'],
            'email_sent',
            'email_outbox',
            (int) $job['id'],
            null,
            ['mail_type' => $job['mail_type']]
        );
    }

    private function mark_failed(array $job, $message)
    {
        $maxAttempts = 5;
        $attempts = (int) $job['attempt_count'];
        $status = $attempts >= $maxAttempts ? 'failed' : 'queued';
        $delay = min(3600, 60 * (2 ** max(0, $attempts - 1)));
        $nextAttempt = gmdate('Y-m-d H:i:s', time() + $delay);
        $safeMessage = substr(preg_replace('/[\r\n]+/', ' ', (string) $message), 0, 1000);
        $this->db->raw(
            'UPDATE email_outbox
             SET status = ?, next_attempt_at = ?, locked_at = NULL, last_error = ?
             WHERE id = ? AND status = ?',
            [$status, $nextAttempt, $safeMessage, (int) $job['id'], 'sending']
        );
        (new Audit_logger($this->db))->record(
            null,
            null,
            $job['barangay_id'] === null ? null : (int) $job['barangay_id'],
            'email_send_failed',
            'email_outbox',
            (int) $job['id'],
            null,
            ['mail_type' => $job['mail_type'], 'attempt' => $attempts, 'final' => $status === 'failed']
        );
    }
}
