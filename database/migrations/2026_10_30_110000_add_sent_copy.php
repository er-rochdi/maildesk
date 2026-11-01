<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mailboxes', fn (Blueprint $t) => $t->string('sent_folder')->default('Sent'));
        Schema::table('messages', fn (Blueprint $t) => $t->string('archive_error')->nullable());
    }

    public function down(): void
    {
        Schema::table('messages', fn (Blueprint $t) => $t->dropColumn('archive_error'));
        Schema::table('mailboxes', fn (Blueprint $t) => $t->dropColumn('sent_folder'));
    }
};
