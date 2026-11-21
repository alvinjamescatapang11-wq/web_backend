<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Mail_queue
{
    private $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function enqueue($recipient, $subject, $body, $mailType, $userId = null, $barangayId = null)
    {
        if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('A valid email address is required to queue mail.');
        }

        $this->db->raw(
            'INSERT INTO email_outbox
                (user_id, barangay_id, mail_type, recipient, subject, body)
             VALUES (?, ?, ?, ?, ?, ?)',
            [
                $userId === null ? null : (int) $userId,
                $barangayId === null ? null : (int) $barangayId,
                substr($mailType, 0, 40),
                $recipient,
                substr(str_replace(["\r", "\n"], ' ', $subject), 0, 255),
                $body,
            ]
        );
    }
}
