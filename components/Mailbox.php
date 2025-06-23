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

        // Redirect if not logged in and on defaultPage
        if (!$this->checkSession() && $currentPage === $this->property('defaultPage')) {
            return Redirect::to($this->property('loginPage'));
        }

        // Redirect if logged in and on loginPage
        if ($this->checkSession() && $currentPage === $this->property('loginPage')) {
            return Redirect::to($this->property('defaultPage'));
        }

        // Load folder from URL param or default
        $folder = $this->param('folder') ?: $this->property('defaultFolder');
        $this->page['folder'] = $folder;

        // Load messages for the folder only if session is valid
        if ($this->checkSession()) {
            $this->page['messages'] = $this->loadMessages($folder);
        } else {
            $this->page['messages'] = [];
        }

        // Return null so CMS completes rendering
        return null;
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
    $folder = post('folder');

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

        $folderObj = $client->getFolder($folder);
        $message = $folderObj->getMessage($uid);

        return [
            '#message-view' => $this->renderPartial('webmail/messageView', [
                'message' => $message
            ])
        ];
    } catch (\Exception $e) {
        Flash::error('Failed to load message: ' . $e->getMessage());
        return ['#message-view' => '<p class="text-danger">Could not load message.</p>'];
    }
}
}
