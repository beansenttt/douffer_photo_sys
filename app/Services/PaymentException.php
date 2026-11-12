<?php
declare(strict_types=1);

namespace App\Services;

use RuntimeException;

/** A payment action that cannot be done. The message is safe to show to staff. */
class PaymentException extends RuntimeException
{
}
