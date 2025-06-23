<?php namespace Tohur\WebMail\Classes;

use Webklex\IMAP\ClientManager;
use Tohur\WebMail\Models\Settings;
use Exception;

class MailClient
{
    protected $client;

    public function __construct($username, $password)
    {
        $cm = new ClientManager();

        $settings = Settings::instance();

$client = $cm->make([
    'host'          => $settings->imap_host,
    'port'          => $settings->imap_port,
    'encryption'    => $settings->imap_encryption,
    'validate_cert' => true,
    'username'      => $username,
    'password'      => $password,
    'protocol'      => 'imap'
]);

        try {
            $this->client->connect();
        } catch (Exception $e) {
            \Log::error('IMAP connection failed: ' . $e->getMessage());
        }
    }

    public function getRecentMessages($limit = 10)
    {
        return $this->client->getFolder('INBOX')
            ->messages()
            ->all()
            ->limit($limit)
            ->get();
    }
}
