<?php

namespace App\Services;

use Throwable;

class MailFailure
{
    public static function sync(Throwable $exception): string
    {
        // Inspect transport errors, but expose only fixed messages, never credentials.
        $detail = strtolower(get_class($exception).' '.$exception->getMessage());
        if (preg_match('/authenticat|authfailed|login (?:failed|denied)|invalid credentials|(?:wrong|invalid|incorrect) password/', $detail)) {
            return 'Connexion IMAP refusée. Vérifiez l’adresse et le mot de passe de la boîte dans Configurer.';
        }
        if (preg_match('/certificate|certificat|ssl|tls|crypto/', $detail)) {
            return 'Certificat IMAP invalide. Vérifiez le nom du serveur et son certificat SSL.';
        }
        if (preg_match('/timed? ?out|timeout|connection|connect|resolve|introuvable|socket|network/', $detail)) {
            return 'Serveur IMAP inaccessible. Vérifiez le DNS, le mode DNS only et le port 993, puis relancez Synchroniser.';
        }

        return 'Synchronisation impossible. Vérifiez la configuration de la boîte puis relancez Synchroniser.';
    }
}
