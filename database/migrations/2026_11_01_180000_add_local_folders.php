<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void { Schema::table('messages', fn (Blueprint $table) => $table->string('local_folder')->default('inbox')); }
    public function down(): void { Schema::table('messages', fn (Blueprint $table) => $table->dropColumn('local_folder')); }
};
