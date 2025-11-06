<?php

declare(strict_types=1);

namespace App\Imap;

use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\ClientManager;

/**
 * Creates and opens a connected Webklex IMAP client.
 * - Centralizes configuration and connection logic.
 * - Converts empty encryption to null.
 * - Throws a RuntimeException on connection errors.
 */
final class ImapConnectionFactory
{
    public function __construct(
        private readonly ClientManager $manager,
        private readonly string $host,
        private readonly int $port,
        private readonly ?string $encryption,   // 'ssl' | 'tls' | null
        private readonly bool $validateCert,
        private readonly string $username,
        private readonly string $password,
        private readonly string $protocol = 'imap', // keep 'imap' unless you really need 'imap/ssl' legacy
    ) {
    }

    /** Build and connect a Webklex client. */
    public function connect(): Client
    {
        // Map empty string to null for encryption
        $enc = $this->encryption;
        if (null !== $enc) {
            $enc = '' === trim($enc) ? null : strtolower(trim($enc));
        }

        $client = $this->manager->make([
            'host' => $this->host,
            'port' => $this->port,
            'encryption' => $enc,                 // null | 'ssl' | 'tls'
            'validate_cert' => $this->validateCert,  // true/false
            'username' => $this->username,
            'password' => $this->password,
            'protocol' => $this->protocol,      // 'imap'
            // other optional keys: 'timeout', 'proxy', ...
        ]);

        try {
            $client->connect();
        } catch (\Throwable $e) {
            throw new \RuntimeException('IMAP connection failed: '.$e->getMessage(), previous: $e);
        }

        return $client;
    }
}
