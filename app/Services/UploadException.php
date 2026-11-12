<?php
declare(strict_types=1);

namespace App\Services;

use RuntimeException;

/** A rejected upload. The message is safe to show to the user. */
final class UploadException extends RuntimeException
{
}
