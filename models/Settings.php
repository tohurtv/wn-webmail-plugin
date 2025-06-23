<?php namespace Tohur\WebMail\Models;

use Model;

class Settings extends Model
{
    public $implement = ['System.Behaviors.SettingsModel'];

    public $settingsCode = 'tohur_webmail_settings';
    public $settingsFields = 'fields.yaml';
}
