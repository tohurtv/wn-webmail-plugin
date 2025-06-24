<?php namespace Tohur\WebMail\Components;

use Cms\Classes\ComponentBase;
use Cms\Classes\Page;
use Tohur\WebMail\Models\Settings;
use Tohur\WebMail\Models\MailIdentity;
use Webklex\IMAP\Facades\Client;
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
            'description' => 'Handles login and mailbox access for Webmail'
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

    protected function redirectToLogin()
    {
        return Redirect::to($this->controller->pageUrl($this->property('loginPage')));
    }

    protected function redirectToDefault()
    {
        return Redirect::to($this->controller->pageUrl($this->property('defaultPage')));
    }

    public function onRun()
    {
        $currentPage = $this->page->baseFileName;

        $email = post('email') ?? request()->cookie('webmail_email');
        $password = post('password') ?? request()->cookie('webmail_password');

        if (!$email || !$password) {
            if ($currentPage === $this->property('defaultPage')) {
                return $this->redirectToLogin();
            }
            return;
        }

        try {
            $identity = MailIdentity::where('email', $email)->first();
            if (!$identity) {
                return $this->redirectToLogin();
            }

            $settings = Settings::instance();
            $client = Client::make([
                'host'          => $settings->imap_host,
                'port'          => $settings->imap_port,
                'encryption'    => $settings->imap_encryption,
                'validate_cert' => true,
                'username'      => $identity->imap_username,
                'password'      => $password,
                'protocol'      => 'imap'
            ]);
            $client->connect();

            $folderParam = $this->param('folder') ?? $this->property('defaultFolder');
            $folder = $client->getFolder($folderParam);
            $messages = $folder->messages()->all()->setFetchOrder("asc")->limit(20)->get();
            $messages = $messages->sortByDesc(fn($m) => $m->getDate());

            $this->page['folder'] = $folderParam;
            $this->page['messages'] = $messages;
            $this->page['folders'] = $this->listFolders($client);
            $this->page['dateFormat'] = $identity->date_format ?? 'F j, Y g:i A';

        } catch (\Exception $e) {
            Log::error("Error loading mailbox folder: " . $e->getMessage());
            Flash::error("Failed to load folder: " . $folderParam);
            return $this->redirectToDefault();
        }
    }

    public function getDateFormat()
    {
        $email = request()->cookie('webmail_email');
        $identity = MailIdentity::where('email', $email)->first();
        return $identity && $identity->date_format ? $identity->date_format : 'F j, Y g:i A';
    }

    public function onLogin()
    {
        $email = post('email');
        $password = post('password');

        try {
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

            $client->connect();

            MailIdentity::firstOrCreate(['email' => $email], ['imap_username' => $email]);

            return Redirect::to($this->controller->pageUrl($this->property('defaultPage')))
                ->withCookie(cookie('webmail_email', $email, 60))
                ->withCookie(cookie('webmail_password', $password, 60));
        } catch (\Exception $e) {
            Flash::error('Login failed: ' . $e->getMessage());
            return Redirect::back();
        }
    }

    public function onLogout()
    {
        Flash::success('You have been logged out.');
        return Redirect::to($this->controller->pageUrl($this->property('loginPage')))
            ->withCookie(cookie()->forget('webmail_email'))
            ->withCookie(cookie()->forget('webmail_password'));
    }

    public function listFolders($client)
    {
        try {
            $priorityOrder = ['INBOX', 'STARRED', 'IMPORTANT', 'SENT', 'DRAFTS', 'ARCHIVE', 'SPAM', 'JUNK', 'TRASH'];
            $folders = collect($client->getFolders());

            return $folders->sortBy(function ($folder) use ($priorityOrder) {
                $name = strtoupper($folder->name);
                $index = array_search($name, $priorityOrder);
                return $index !== false ? $index : count($priorityOrder) + ord($name[0]);
            });
        } catch (\Exception $e) {
            Log::error('Failed to list IMAP folders: ' . $e->getMessage());
            return [];
        }
    }
}
