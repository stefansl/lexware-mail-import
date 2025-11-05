<?php
declare(strict_types=1);

namespace App\Attachment;

use App\Imap\MessageRef;

/** Tries multiple providers; the first one that yields content wins. */
final class AttachmentChainProvider implements AttachmentProviderInterface
{
    /** @param AttachmentProviderInterface[] $providers */
    public function __construct(private readonly array $providers) {}

    public function get(MessageRef $ref): iterable
    {
        foreach ($this->providers as $provider) {
            $yielded = false;
            foreach ($provider->get($ref) as $att) {
                $yielded = true;
                yield $att;
            }
            if ($yielded) return;
        }
    }
}
