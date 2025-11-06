<?php

declare(strict_types=1);

namespace App\Contract;

interface ErrorNotifierInterface
{
    public function notify(string $subject, string $message): void;
}
