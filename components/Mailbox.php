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
                'options'     => function () {
                    return Page::sortBy('baseFileName')->lists('baseFileName', 'baseFileName');
                },
                'default'     => 'webmail/inbox',
            ]
        ];
    }

    public function onRun()
    {
        $currentPage = $this->page->baseFileName;

        if (!$this->checkSession() && $currentPage !== 'login') {
            return Redirect::to('webmail/login');
        }

        if ($this->checkSession() && $currentPage === 'login') {
            return Redirect::to($this->property('defaultPage'));
        }

        // Always return null to allow CMS to finish loading the page
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
        Flash::success('You have been logged out.');
        return Redirect::to('webmail/login');
    }

    protected function checkSession()
    {
        return Session::has('webmail_identity');
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
    }

    public function getCurrentIdentity()
    {
        if (!$this->checkSession()) {
            return null;
        }

        return MailIdentity::find(Session::get('webmail_identity'));
    }
}