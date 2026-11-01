<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mailboxes', function (Blueprint $table) {
            $table->string('uid_validity')->nullable();
            $table->unsignedBigInteger('last_uid')->default(0);
            $table->timestamp('last_synced_at')->nullable();
            $table->string('sync_error')->nullable();
        });
        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mailbox_id')->constrained()->cascadeOnDelete();
            $table->string('direction');
            $table->string('status')->default('received');
            $table->string('uid_validity')->nullable();
            $table->unsignedBigInteger('remote_uid')->nullable();
            $table->string('internet_message_id')->nullable();
            $table->foreignId('sender_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reply_to_id')->nullable()->constrained('messages')->nullOnDelete();
            $table->string('request_id', 36)->nullable();
            $table->string('from_address');
            $table->string('from_name')->nullable();
            $table->json('to');
            $table->json('cc')->nullable();
            $table->string('subject')->default('');
            $table->longText('body');
            $table->timestamp('message_at');
            $table->timestamp('sent_at')->nullable();
            $table->string('send_error')->nullable();
            $table->timestamps();
            $table->unique(['mailbox_id', 'uid_validity', 'remote_uid']);
            $table->unique(['mailbox_id', 'request_id']);
            $table->index(['mailbox_id', 'direction', 'message_at']);
        });
        Schema::create('message_reads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('read_at');
            $table->unique(['message_id', 'user_id']);
        });
        Schema::create('attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('path');
            $table->unsignedBigInteger('size');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attachments');
        Schema::dropIfExists('message_reads');
        Schema::dropIfExists('messages');
        Schema::table('mailboxes', fn (Blueprint $t) => $t->dropColumn(['uid_validity', 'last_uid', 'last_synced_at', 'sync_error']));
    }
};
