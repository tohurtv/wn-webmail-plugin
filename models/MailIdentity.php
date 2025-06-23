<?php
namespace Tohur\Webmail\Models;

use Model;

class MailIdentity extends Model
{
    public $table = 'tohur_webmail_identities';

    protected $fillable = [
        'email',
        'imap_username',
        'name',
        'signature',
        'reply_to',
        'is_default'
    ];

    protected $casts = [
        'is_default' => 'boolean',
    ];
}
