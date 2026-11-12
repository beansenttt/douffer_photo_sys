<?php
declare(strict_types=1);

namespace App\Services;

use RuntimeException;
use Throwable;

/**
 * Thrown when service data fails business validation rules.
 */
final class ValidationException extends RuntimeException
{
    /** @var array<string, string> */
    private array $errors;

    public function __construct(string $message = '', array $errors = [], int $code = 0, ?Throwable $previous = null)
    {
        if ($message === '' && !empty($errors)) {
            $message = (string) reset($errors);
        }
        parent::__construct($message ?: 'Validation failed.', $code, $previous);
        $this->errors = $errors;
    }

    /** @return array<string, string> */
    public function getErrors(): array
    {
        return $this->errors;
    }
}
