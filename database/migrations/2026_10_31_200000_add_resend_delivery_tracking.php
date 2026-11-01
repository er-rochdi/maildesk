<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->string('provider')->nullable();
            $table->string('provider_message_id')->nullable();
            $table->string('delivery_status')->nullable();
            $table->unique(['provider', 'provider_message_id']);
        });
        Schema::create('message_delivery_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained()->cascadeOnDelete();
            $table->string('event_id')->unique();
            $table->string('type');
            $table->json('recipients');
            $table->timestamp('occurred_at');
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_delivery_events');
        Schema::table('messages', function (Blueprint $table) {
            $table->dropUnique(['provider', 'provider_message_id']);
            $table->dropColumn(['provider', 'provider_message_id', 'delivery_status']);
        });
    }
};
