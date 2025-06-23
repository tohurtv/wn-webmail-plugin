<?php namespace Tohur\WebMail\Classes;

use Webklex\IMAP\ClientManager;
use Exception;

class MailClient
{
    protected $client;

    public function __construct($username, $password)
    {
        $cm = new ClientManager();

        $this->client = $cm->make([
            'host'          => 'imap.example.com',
            'port'          => 993,
            'encryption'    => 'ssl',
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
