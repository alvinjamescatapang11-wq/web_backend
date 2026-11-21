<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class System extends Controller
{
    public function migrate()
    {
        $this->require_cli();
        $this->require_database_configuration();
        $this->call->library('migration');
        $this->migration->migrate();
    }

    public function seed_admin()
    {
        $this->require_cli();
        $this->require_database_configuration();
        $this->call->library('agritrack_seeder');
        $result = $this->agritrack_seeder->create_cao_admin();
        echo $result['message'] . PHP_EOL;
    }

    public function send_mail()
    {
        $this->require_cli();
        $this->require_database_configuration();
        $this->call->model('Mail_outbox_model');
        $result = $this->Mail_outbox_model->send_pending();
        echo 'Sent: ' . $result['sent'] . '; failed/retried: ' . $result['failed'] . PHP_EOL;
    }

    private function require_cli()
    {
        if (!defined('IS_CLI') || IS_CLI !== true) {
            http_response_code(404);
            exit('Not found.');
        }
    }

    private function require_database_configuration()
    {
        if (
            (getenv('DB_HOST') ?: '') === '' ||
            (getenv('DB_USERNAME') ?: '') === '' ||
            (getenv('DB_NAME') ?: '') === ''
        ) {
            fwrite(STDERR, "Configure DB_HOST, DB_USERNAME, and DB_NAME in the backend .env first.\n");
            exit(1);
        }
    }
}
