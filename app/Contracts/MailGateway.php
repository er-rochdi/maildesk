<?php

namespace App\Contracts;

use App\Models\Mailbox;
use App\Models\Message;

interface MailGateway
{
    /** @return array{validity: string, messages: array} */
    public function receive(Mailbox $mailbox): array;

    public function send(Message $message): void;

    public function archive(Message $message): void;
}
