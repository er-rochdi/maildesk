<?php

namespace App\Exceptions;

use RuntimeException;

class MailAttachmentUnavailable extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Pièce jointe indisponible. Aucun envoi Resend n’a été effectué.');
    }
}
