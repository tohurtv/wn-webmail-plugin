<?php

namespace Tohur\Webmail;

use Backend;
use Backend\Models\UserRole;
use System\Classes\PluginBase;

/**
 * webmail Plugin Information File
 */
class Plugin extends PluginBase
{
    /**
     * Returns information about this plugin.
     */
    public function pluginDetails(): array
    {
        return [
            'name'        => 'tohur.webmail::lang.plugin.name',
            'description' => 'tohur.webmail::lang.plugin.description',
            'author'      => 'tohur',
            'icon'        => 'icon-leaf'
        ];
    }

    /**
     * Register method, called when the plugin is first registered.
     */
    public function register(): void
    {

    }

    /**
     * Boot method, called right before the request route.
     */
    public function boot(): void
    {

    }

    /**
     * Registers any frontend components implemented in this plugin.
     */
    public function registerComponents(): array
    {
        return [
        \Tohur\WebMail\Components\Inbox::class => 'webMailInbox'
    ];
    }

    /**
     * Registers any backend permissions used by this plugin.
     */
    public function registerPermissions(): array
    {
        return []; // Remove this line to activate

        return [
            'tohur.webmail.some_permission' => [
                'tab' => 'tohur.webmail::lang.plugin.name',
                'label' => 'tohur.webmail::lang.permissions.some_permission',
                'roles' => [UserRole::CODE_DEVELOPER, UserRole::CODE_PUBLISHER],
            ],
        ];
    }

    /**
     * Registers backend navigation items for this plugin.
     */
    public function registerNavigation(): array
    {
        return []; // Remove this line to activate

        return [
            'webmail' => [
                'label'       => 'tohur.webmail::lang.plugin.name',
                'url'         => Backend::url('tohur/webmail/mycontroller'),
                'icon'        => 'icon-leaf',
                'permissions' => ['tohur.webmail.*'],
                'order'       => 500,
            ],
        ];
    }
}
