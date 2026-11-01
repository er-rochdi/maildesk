<?php

namespace App\Jobs;

use App\Models\Mailbox;
use App\Services\SyncMailbox;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SyncMailboxJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 300;

    public function uniqueId(): string
    {
        return (string) $this->mailboxId;
    }

    public int $tries = 1;

    public int $timeout = 240;

    public function __construct(public int $mailboxId) {}

    public function handle(SyncMailbox $service): void
    {
        if ($mailbox = Mailbox::find($this->mailboxId)) {
            $service->run($mailbox);
        }
    }

    public function failed(?\Throwable $exception): void
    {
        Mailbox::whereKey($this->mailboxId)->whereNull('sync_error')->update(['sync_error' => 'Synchronisation interrompue. Vérifiez le serveur et relancez la synchronisation.']);
    }
}
