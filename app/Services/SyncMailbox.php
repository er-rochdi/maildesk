<?php

namespace App\Services;

use App\Contracts\MailGateway;
use App\Models\Mailbox;
use App\Models\Message;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class SyncMailbox
{
    public function __construct(private MailGateway $gateway) {}

    public function run(Mailbox $mailbox): void
    {
        $lock = Cache::lock('mailbox-sync:'.$mailbox->id, 300);
        if (! $lock->get()) {
            return;
        }
        try {
            $mailbox->refresh();
            $batch = $this->gateway->receive($mailbox);
            $last = $mailbox->uid_validity === $batch['validity'] ? $mailbox->last_uid : 0;
            foreach ($batch['messages'] as $item) {
                $paths = [];
                try {
                    DB::transaction(function () use ($mailbox, $batch, $item, &$paths) {
                        $key = ['mailbox_id' => $mailbox->id, 'uid_validity' => $batch['validity'], 'remote_uid' => $item['remote_uid']];
                        if (Message::where($key)->exists()) {
                            return;
                        }
                        $files = $item['attachments'];
                        $report = $item['delivery_report'] ?? null;
                        unset($item['attachments'], $item['delivery_report']);
                        $message = Message::create($key + $item + ['direction' => 'inbound', 'status' => 'received']);
                        foreach ($files as $file) {
                            $path = 'mail/'.$mailbox->id.'/'.Str::uuid();
                            if (! Storage::disk('local')->put($path, $file['content'])) {
                                throw new RuntimeException('Écriture de la pièce jointe impossible.');
                            }
                            $paths[] = $path;
                            $message->attachments()->create(['name' => $file['name'] ?: 'piece-jointe', 'path' => $path, 'size' => strlen($file['content'])]);
                        }
                        (new DeliveryReport)->record($message, $report);
                    });
                } catch (\Throwable $e) {
                    Storage::disk('local')->delete($paths);
                    throw $e;
                }
                $last = max($last, $item['remote_uid']);
                $mailbox->forceFill(['uid_validity' => $batch['validity'], 'last_uid' => $last])->save();
            }
            $mailbox->forceFill(['uid_validity' => $batch['validity'], 'last_uid' => $last, 'last_synced_at' => now(), 'sync_error' => null])->save();
        } catch (\Throwable $e) {
            $mailbox->forceFill(['sync_error' => MailFailure::sync($e)])->save();
            // Never persist transport exception messages: some contain credentials or message contents.
            throw new RuntimeException('La synchronisation de la boîte '.$mailbox->id.' a échoué.');
        } finally {
            $lock->release();
        }
    }
}
