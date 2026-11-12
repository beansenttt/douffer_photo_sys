<?php
declare(strict_types=1);

namespace App\Services;

use RuntimeException;

/**
 * Thrown when assigning a crew member to an event whose hours overlap
 * with another event assigned to the same crew member on the same date (T4).
 */
final class CrewOverlapConflictException extends RuntimeException
{
}
