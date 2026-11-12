<?php
declare(strict_types=1);

namespace App\Services;

/** The GCash reference was already used on another payment and the staff member has not acknowledged it yet. */
final class DuplicateReferenceException extends PaymentException
{
}
