<?php
declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/** Thrown to stop a request with an HTTP status (403, 404, 429...). */
final class HttpException extends RuntimeException
{
    public int $status;

    public function __construct(int $status, string $message = '')
    {
        $this->status = $status;
        parent::__construct($message, $status);
    }
}
