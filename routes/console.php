<?php

use App\Jobs\SendMessageJob;
use App\Jobs\SyncMailboxJob;
use App\Models\Mailbox;
use App\Models\Message;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::call(function () {
    Message::where('status', 'queued')->where('created_at', '<', now()->subMinute())->eachById(fn ($message) => SendMessageJob::dispatch($message->id));
    Mailbox::query()->eachById(fn ($mailbox) => SyncMailboxJob::dispatch($mailbox->id));
})->name('maildesk-sync')->everyMinute()->withoutOverlapping();
