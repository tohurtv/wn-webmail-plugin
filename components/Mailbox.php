<?php namespace Tohur\WebMail\Components;

use Cms\Classes\ComponentBase;
use Tohur\WebMail\Classes\MailClient;
use Tohur\Webmail\Models\MailIdentity;
use Webklex\IMAP\Facades\Client;
use Exception;

class Inbox extends ComponentBase
{
    public $messages;

    public function componentDetails()
    {
        return [
            'name'        => 'Inbox',
            'description' => 'Displays a list of recent emails from the inbox.'
        ];
    }

    public function defineProperties()
    {
        return [
            'limit' => [
                'title'             => 'Message Limit',
                'description'       => 'Number of messages to show',
                'default'           => 10,
                'type'              => 'string',
                'validationPattern' => '^\d+$',
                'validationMessage' => 'The message limit must be a number.'
            ]
        ];
    }

    public function onRun()
    {
        $this->login();
    }

public function login($email, $password)
{
    // Get IMAP config from plugin Settings model
    $settings = Settings::instance();

    $client = Client::make([
        'host' => $settings->imap_host,
        'port' => $settings->imap_port,
        'encryption' => $settings->imap_encryption,
        'validate_cert' => true,
        'username' => $email,
        'password' => $password,
        'protocol' => 'imap'
    ]);

    try {
        $client->connect();

        // Lookup or create identity
        $identity = MailIdentity::firstOrCreate(
            ['email' => $email],
            ['imap_username' => $email] // Could be adjusted
        );

        Session::put('webmail_identity', $identity->id);

        return true;
    } catch (\Exception $e) {
        throw new \ApplicationException('Login failed: ' . $e->getMessage());
    }
}
}
