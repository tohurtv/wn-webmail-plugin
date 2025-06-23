<?php namespace Tohur\WebMail\Components;

use Cms\Classes\ComponentBase;
use Auth;
use Tohur\WebMail\Classes\MailClient;
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
        $this->loadInbox();
    }

    protected function loadInbox()
    {
        try {
            $user = Auth::getUser();

            if (!$user) {
                throw new Exception('User not authenticated.');
            }

            // Replace these with real per-user credentials or a test account
            $username = $user->email;
            $password = $user->imap_password ?? 'test-password'; // update this logic

            $client = new MailClient($username, $password);
            $limit = (int) $this->property('limit');

            $this->messages = $client->getRecentMessages($limit);
        } catch (Exception $e) {
            \Log::error('[WebMail] Failed to load inbox: ' . $e->getMessage());
            $this->messages = collect();
        }

        $this->page['messages'] = $this->messages;
    }
}
