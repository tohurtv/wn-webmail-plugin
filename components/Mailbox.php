<?php namespace Tohur\WebMail\Components;

use Cms\Classes\ComponentBase;
use Auth;
use Tohur\WebMail\Classes\MailClient;
use Tohur\WebMail\Models\Settings;
use Exception;

class Mailbox extends ComponentBase
{
    public $messages;
    public $folders;
    public $client;

    public function componentDetails()
    {
        return [
            'name'        => 'Mailbox',
            'description' => 'Connects to IMAP and displays mailbox contents.'
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
        $this->loadMailbox();
    }

    protected function loadMailbox()
    {
        try {
            $user = Auth::getUser();

            if (!$user) {
                throw new Exception('User not authenticated.');
            }

            // Get settings
            $settings = Settings::instance();

            // Use either per-user credentials or shared fallback
            $username = $user->email;
            $password = $user->imap_password ?? $settings->smtp_password;

            $this->client = new MailClient($username, $password);

            $this->folders = $this->client->getFolders();
            $this->messages = $this->client->getRecentMessages($this->property('limit'));

            $this->page['messages'] = $this->messages;
            $this->page['folders'] = $this->folders;

        } catch (Exception $e) {
            \Log::error('[WebMail] Mailbox load error: ' . $e->getMessage());
            $this->messages = collect();
            $this->folders = collect();
        }
    }
}
