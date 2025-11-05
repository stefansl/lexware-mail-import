<?php
declare(strict_types=1);

namespace App\Service\Exception;

final class LexwareHttpException extends \RuntimeException
{
    public function __construct(
        public readonly int $statusCode,
        string $message,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $statusCode, $previous);
    }
}
