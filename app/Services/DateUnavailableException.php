<?php
declare(strict_types=1);

namespace App\Services;

use RuntimeException;

/** Thrown when a date cannot take another confirmed booking (full, blocked or past). */
final class DateUnavailableException extends RuntimeException
{
}
