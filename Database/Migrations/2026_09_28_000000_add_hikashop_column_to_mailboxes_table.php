<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// A mailbox may talk to a shop of its own (several brands on one desk): its
// shop URL and device token, as JSON, the token encrypted.
class AddHikashopColumnToMailboxesTable extends Migration
{
    public function up()
    {
        Schema::table('mailboxes', function (Blueprint $table) {
            $table->text('hikashop')->nullable();
        });
    }

    public function down()
    {
        Schema::table('mailboxes', function (Blueprint $table) {
            $table->dropColumn('hikashop');
        });
    }
}
