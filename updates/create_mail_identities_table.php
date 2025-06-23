<?php namespace Tohur\Bookings\Updates;

use Schema;
use Winter\Storm\Database\Schema\Blueprint;
use Winter\Storm\Database\Updates\Migration;

class CreateMailIdentitiesTable extends Migration
{
    public function up()
    {
Schema::create('tohur_webmail_identities', function (Blueprint $table) {
    $table->id();
    $table->string('email')->unique(); // user@domain.com
    $table->string('imap_username')->nullable(); // if different from email
    $table->string('name')->nullable(); // display name
    $table->string('reply_to')->nullable();
    $table->text('signature')->nullable();
    $table->boolean('is_default')->default(true);
    $table->timestamps();
});
    }

    public function down()
    {
        Schema::dropIfExists('tohur_webmail_identities');
    }
}
