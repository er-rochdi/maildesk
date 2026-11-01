<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->id(); $table->string('name'); $table->timestamps();
        });
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('company_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('role')->default('member');
        });
        Schema::create('mailboxes', function (Blueprint $table) {
            $table->id(); $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name'); $table->string('email');
            $table->string('imap_host'); $table->unsignedSmallInteger('imap_port')->default(993);
            $table->string('smtp_host'); $table->unsignedSmallInteger('smtp_port')->default(587);
            $table->text('password'); $table->timestamps();
            $table->unique(['company_id', 'email']);
        });
        Schema::create('mailbox_user', function (Blueprint $table) {
            $table->foreignId('mailbox_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->primary(['mailbox_id', 'user_id']);
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('mailbox_user'); Schema::dropIfExists('mailboxes');
        Schema::table('users', function (Blueprint $table) { $table->dropConstrainedForeignId('company_id'); $table->dropColumn('role'); });
        Schema::dropIfExists('companies');
    }
};
