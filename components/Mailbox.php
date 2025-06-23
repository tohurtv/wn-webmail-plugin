<?php namespace Tohur\WebMail\Components;

use Cms\Classes\ComponentBase;
use Cms\Classes\Page;
use Tohur\WebMail\Models\Settings;
use Tohur\WebMail\Models\MailIdentity;
use Webklex\IMAP\Facades\Client;
use Session;
use Flash;
use Redirect;
use ApplicationException;
use Log;

class Mailbox extends ComponentBase
{
    public function componentDetails()
    {
        return [
            'name'        => 'Mailbox',
            'description' => 'Handles login, session, and routing for Webmail'
        ];
    }

    public function defineProperties()
    {
        return [
            'defaultPage' => [
                'title'       => 'Default Page After Login',
                'description' => 'Page to redirect to after successful login',
                'type'        => 'dropdown',
                'options'     => $this->getCmsPageOptions(),
                'default'     => 'webmail/inbox',
            ],
            'loginPage' => [
                'title'       => 'Login Page',
                'description' => 'Page to redirect to for login',
                'type'        => 'dropdown',
                'options'     => $this->getCmsPageOptions(),
                'default'     => 'webmail/login',
            ],
            'defaultFolder' => [
                'title'       => 'Default Mail Folder',
                'description' => 'Folder to load if none specified in URL',
                'type'        => 'string',
                'default'     => 'INBOX',
            ],
        ];
    }

    protected function getCmsPageOptions()
    {
        $pages = Page::listInTheme(\Cms\Classes\Theme::getActiveTheme());
        $options = [];
        foreach ($pages as $page) {
            $options[$page->baseFileName] = $page->baseFileName;
        }
        return $options;
    }

public function onRun()
{
    $currentPage = $this->page->baseFileName;

    if (!$this->checkSession() && $currentPage === $this->property('defaultPage')) {
        return Redirect::to($this->property('loginPage'));
    }

    if ($this->checkSession() && $currentPage === $this->property('loginPage')) {
        return Redirect::to($this->property('defaultPage'));
    }

    // New logic for /mailbox/:folder?
    $folderParam = $this->param('folder') ?? 'INBOX';

    try {
        $identity = $this->getCurrentIdentity();
        if (!$identity) {
            return Redirect::to($this->property('loginPage'));
        }

        $settings = Settings::instance();

        $client = Client::make([
            'host'          => $settings->imap_host,
            'port'          => $settings->imap_port,
            'encryption'    => $settings->imap_encryption,
            'validate_cert' => true,
            'username'      => $identity->imap_username,
            'password'      => Session::get('webmail_password'),
            'protocol'      => 'imap'
        ]);

        $client->connect();
        $folder = $client->getFolder($folderParam);
        $messages = $folder->messages()->all()->limit(20)->get();

        // Pass to the page/partials
        $this->page['folder'] = $folderParam;
        $this->page['messages'] = $messages;
    } catch (\Exception $e) {
        \Log::error("Error loading mailbox folder: " . $e->getMessage());
        \Flash::error("Failed to load folder: " . $folderParam);
        return Redirect::to($this->property('defaultPage'));
    }
}


    public function onLogin()
    {
        $email = post('email');
        $password = post('password');

        try {
            $this->attemptLogin($email, $password);
            return Redirect::to($this->property('defaultPage'));
        } catch (\Exception $ex) {
            Flash::error('Login failed: ' . $ex->getMessage());
            return Redirect::back();
        }
    }

    public function onLogout()
    {
        Session::forget('webmail_identity');
        Session::forget('webmail_password');
        Flash::success('You have been logged out.');
        return Redirect::to($this->property('loginPage'));
    }

    protected function checkSession()
    {
        return Session::has('webmail_identity') && Session::has('webmail_password');
    }

    protected function attemptLogin($email, $password)
    {
        $settings = Settings::instance();

        $client = Client::make([
            'host'          => $settings->imap_host,
            'port'          => $settings->imap_port,
            'encryption'    => $settings->imap_encryption,
            'validate_cert' => true,
            'username'      => $email,
            'password'      => $password,
            'protocol'      => 'imap'
        ]);

        try {
            $client->connect();
        } catch (\Exception $e) {
            Log::error('Webmail login failed: ' . $e->getMessage());
            throw new ApplicationException("IMAP connection failed: " . $e->getMessage());
        }

        $identity = MailIdentity::firstOrCreate(
            ['email' => $email],
            ['imap_username' => $email]
        );

        Session::put('webmail_identity', $identity->id);
        Session::put('webmail_password', $password); // you may want to secure this!
    }

    protected function loadMessages($folder)
    {
        $identity = MailIdentity::find(Session::get('webmail_identity'));
        if (!$identity) {
            return [];
        }

        $settings = Settings::instance();

        $client = Client::make([
            'host'          => $settings->imap_host,
            'port'          => $settings->imap_port,
            'encryption'    => $settings->imap_encryption,
            'validate_cert' => true,
            'username'      => $identity->imap_username,
            'password'      => Session::get('webmail_password'),
            'protocol'      => 'imap'
        ]);

        try {
            $client->connect();
            $folderObj = $client->getFolder($folder);
            return $folderObj->messages()->all()->get();
        } catch (\Exception $e) {
            Log::error('Failed to load messages for folder ' . $folder . ': ' . $e->getMessage());
            return [];
        }
    }

    public function getCurrentIdentity()
    {
        if (!$this->checkSession()) {
            return null;
        }

        return MailIdentity::find(Session::get('webmail_identity'));
    }

    public function listFolders()
{
    try {
        $identity = $this->getCurrentIdentity();
        if (!$identity) return [];

        $settings = Settings::instance();
        $client = Client::make([
            'host'          => $settings->imap_host,
            'port'          => $settings->imap_port,
            'encryption'    => $settings->imap_encryption,
            'validate_cert' => true,
            'username'      => $identity->imap_username,
            'password'      => Session::get('webmail_password'),
            'protocol'      => 'imap'
        ]);
        $client->connect();

        return $client->getFolders();
    } catch (\Exception $e) {
        \Log::error('Failed to list IMAP folders: ' . $e->getMessage());
        return [];
    }
}

public function onViewMessage()
{
    $uid = post('uid');
    $folderName = post('folder');

    \Log::info('Loading message UID: ' . $uid . ' from folder: ' . $folderName);

    try {
        $identity = $this->getCurrentIdentity();
        if (!$identity) throw new ApplicationException("Session invalid.");

        $settings = Settings::instance();
        $client = Client::make([
            'host'          => $settings->imap_host,
            'port'          => $settings->imap_port,
            'encryption'    => $settings->imap_encryption,
            'validate_cert' => true,
            'username'      => $identity->imap_username,
            'password'      => Session::get('webmail_password'),
            'protocol'      => 'imap'
        ]);

        $client->connect();

        $folder = $client->getFolder($folderName);
        if (!$folder) {
            throw new ApplicationException("Folder '{$folderName}' not found.");
        }

        $messages = $folder->messages()->uid($uid)->get();
        if ($messages->isEmpty()) {
            throw new ApplicationException("Message with UID {$uid} not found in folder {$folderName}");
        }

        $message = $messages->first();

        // Clean up and isolate the <body> content
        $html = $message->getHTMLBody();
        $bodyContent = '';

        if ($html) {
            libxml_use_internal_errors(true);
            $doc = new \DOMDocument();
            $doc->loadHTML($html);
            libxml_clear_errors();

            $bodyTags = $doc->getElementsByTagName('body');
            if ($bodyTags->length > 0) {
                foreach ($bodyTags->item(0)->childNodes as $child) {
                    $bodyContent .= $doc->saveHTML($child);
                }
            } else {
                $bodyContent = $html; // fallback
            }
        } else {
            $bodyContent = nl2br(e($message->getTextBody()));
        }

        return [
            '#message-view' => $this->renderPartial('webmail/messageView', [
                'message' => $message,
                'htmlBody' => $bodyContent
            ])
        ];
    } catch (\Exception $e) {
        \Log::error('ViewMessage failed: ' . $e->getMessage());
        Flash::error('Failed to load message: ' . $e->getMessage());
        return ['#message-view' => '<p class="text-danger">Could not load message.</p>'];
    }
}



}
