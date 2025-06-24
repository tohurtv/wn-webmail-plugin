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

    if (!$this->checkSession() && $currentPage === $this->property('defaultPage')) {
        return redirectToLogin();
    }

    if ($this->checkSession() && $currentPage === $this->property('loginPage')) {
        return redirectToDefault();
    }

    // New logic for /mailbox/:folder?
    $folderParam = $this->param('folder') ?? 'INBOX';

    try {
        $identity = $this->getCurrentIdentity();
        if (!$identity) {
            return redirectToLogin();
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
        $messages = $folder->messages()->all()->setFetchOrder("asc")->limit(20)->get();
        
        $messages = $messages->sortByDesc(function ($message) {
    return $message->getDate();
});
        // Pass to the page/partials
        $this->page['folder'] = $folderParam;
        $this->page['messages'] = $messages;
        $this->page['folders'] = $this->listFolders();
    } catch (\Exception $e) {
        \Log::error("Error loading mailbox folder: " . $e->getMessage());
        \Flash::error("Failed to load folder: " . $folderParam);
        return redirectToDefault();
    }

    $this->page['folders'] = $this->listFolders();
}


public function getDateFormat() {
    // return your preferred date format string here
    return 'F j, Y g:i A';
}

    public function onLogin()
    {
        $email = post('email');
        $password = post('password');

        try {
            $this->attemptLogin($email, $password);
            return redirectToDefault();
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
        return redirectToLogin();
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
            $messages->messages()->all()->get();
            
            $messages = $messages->sortByDesc(function ($message) {
    return $message->getDate();
});
            return $messages->messages()->all()->get();
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

        $priorityOrder = [
            'INBOX', 'STARRED', 'IMPORTANT', 'SENT', 'DRAFTS', 'ARCHIVE', 'SPAM', 'JUNK', 'TRASH',
        ];

        $folders = collect($client->getFolders());

        return $folders->sortBy(function ($folder) use ($priorityOrder) {
            $name = strtoupper($folder->name);
            $index = array_search($name, $priorityOrder);
            return $index !== false ? $index : count($priorityOrder) + ord($name[0]);
        });
    } catch (\Exception $e) {
        \Log::error('Failed to list IMAP folders: ' . $e->getMessage());
        return [];
    }
}

public function onLoadFolders()
{
    try {
        $identity = $this->getCurrentIdentity();
        if (!$identity) throw new \Exception('Missing identity');

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

        $priorityOrder = [
            'INBOX', 'STARRED', 'IMPORTANT', 'SENT', 'DRAFTS', 'ARCHIVE', 'SPAM', 'JUNK', 'TRASH',
        ];

        $folders = collect($client->getFolders())->sortBy(function ($folder) use ($priorityOrder) {
            $name = strtoupper($folder->name);
            $index = array_search($name, $priorityOrder);
            return $index !== false ? $index : count($priorityOrder) + ord($name[0]);
        });

        return [
            '#folder-list' => $this->renderPartial('webmail/folderList', [
                'folders' => $folders
            ])
        ];
    } catch (\Exception $e) {
        \Log::error('Failed to load folders: ' . $e->getMessage());
        return [
            '#folder-list' => '<div class="alert alert-danger">Failed to load folders.</div>'
        ];
    }
}

public function onLoadMessagesFromFolder()
{
    $folderName = post('folder');
    $sortOrder = post('sort', 'desc'); // Default to 'desc' if not provided

    \Log::info("Folder requested: " . $folderName . " with sort: " . $sortOrder);

    try {
        $identity = $this->getCurrentIdentity();
        if (!$identity) {
            throw new \Exception('Missing identity');
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

        $folder = $client->getFolder($folderName);
        $messages = $folder->query()->all()->limit(20)->get();

        // Sort messages based on selected order
        $messages = $sortOrder === 'asc'
            ? $messages->sortBy(fn($msg) => $msg->getDate())
            : $messages->sortByDesc(fn($msg) => $msg->getDate());

        return [
            '#message-list' => $this->renderPartial('webmail/messageList', [
                'messages' => $messages,
                'folder'   => $folder->path,
                'sort'     => $sortOrder,
                'folders' => $this->listFolders(),
            ])
        ];
    } catch (\Exception $e) {
        \Log::error('Failed to load messages: ' . $e->getMessage());
        return [
            '#message-list' => '<div class="alert alert-danger">Failed to load messages.</div>'
        ];
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

if ($html) {
    // Remove raw email headers that appear at the start of the HTML body
    // Email headers usually look like: Key: Value\r\n and end before the actual HTML content starts

    // This regex tries to remove everything from the start up to the first <html> or <body> tag
    // or alternatively up to the first <!DOCTYPE> or a double line break (empty line)
    $html = preg_replace(
        '#^(.*?)((<html\b)|(<!DOCTYPE\b)|(<body\b))#is',
        '$2',
        $html,
        1
    );

    // Now $html should start with the actual HTML content, no raw headers

    libxml_use_internal_errors(true);

    $doc = new \DOMDocument();
    $utf8html = mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8');
    $doc->loadHTML($utf8html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);

    // Remove <head> to avoid showing styles inside iframe
    $headTags = $doc->getElementsByTagName('head');
    if ($headTags->length > 0) {
        $headNode = $headTags->item(0);
        $headNode->parentNode->removeChild($headNode);
    }

    $bodyContent = $doc->saveHTML();

    libxml_clear_errors();
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

public function onDeleteMessage()
{
    $folderName = post('folder');
    $uid = post('uid');
    $sort = post('sort', 'desc');

    try {
        $identity = $this->getCurrentIdentity();
        if (!$identity) {
            throw new \Exception('Missing identity');
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

        $folder = $client->getFolder($folderName);

        $message = $folder->query()->getMessage($uid);
        if (!$message) {
            throw new \Exception("Message with UID {$uid} not found");
        }

        $message->move('Trash');

        return [
            '#message-list' => $this->renderPartial('webmail/messageList', [
                'messages' => $client->getFolder($folderName)->query()->all()->limit(20)->get(),
                'folder'   => $folderName,
                'sort'     => $sort,
                'dateFormat' => $this->getDateFormat(),
                'folders' => $this->listFolders(),
            ]),
            'delete_success' => true,  // <-- this key flags success
        ];

    } catch (\Exception $e) {
        \Log::error('Failed to delete message: ' . $e->getMessage());
        return [
            '#message-list' => '<div class="alert alert-danger">Failed to delete message.</div>',
            'delete_success' => false,
        ];
    }
}

public function onMoveMessage()
{
    $uid = post('uid');
    $from = post('from');
    $to = post('to');
    $sort = post('sort', 'desc');

    try {
        $identity = $this->getCurrentIdentity();
        if (!$identity) {
            throw new \Exception('Missing identity');
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

        $sourceFolder = $client->getFolder($from);
        $message = $sourceFolder->query()->getMessage($uid);
        $message->move($to);

        $messages = $sourceFolder->query()->all()->limit(20)->get();
        $messages = $sort === 'asc'
            ? $messages->sortBy(fn($msg) => $msg->getDate())
            : $messages->sortByDesc(fn($msg) => $msg->getDate());

        return [
            '#message-list' => $this->renderPartial('webmail/messageList', [
                'messages' => $messages,
                'folder'   => $from,
                'sort'     => $sort,
                'dateFormat' => $this->getDateFormat(),
                'folders' => $this->listFolders(),
            ]),
        ];
    } catch (\Exception $e) {
        \Log::error('Move failed: ' . $e->getMessage());
        return [
            '#message-list' => '<div class="alert alert-danger">Failed to move message.</div>'
        ];
    }
}

}
