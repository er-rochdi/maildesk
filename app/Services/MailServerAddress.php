<?php

namespace App\Services;

use RuntimeException;

class MailServerAddress
{
    // Pin a public IPv4 address for the socket; retain the hostname for TLS certificate verification.
    public function resolve(string $host): string
    {
        if (! preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9.\-]{0,252}\z/', $host)) {
            throw new RuntimeException('Nom du serveur email invalide.');
        }
        $addresses = filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? [$host] : gethostbynamel($host);
        if (! $addresses) {
            throw new RuntimeException('Serveur email introuvable.');
        }
        foreach ($addresses as $address) {
            if (! filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_GLOBAL_RANGE)) {
                throw new RuntimeException('Le serveur email doit avoir une adresse IP publique.');
            }
        }

        return $addresses[0];
    }
}
