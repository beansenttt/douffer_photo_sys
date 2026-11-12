<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Server-side form validation with plain-language messages.
 *
 *   $errors = Validator::make($_POST, [
 *       'full_name' => 'required|max:120',
 *       'mobile'    => 'required|mobile',
 *       'event_date'=> 'required|date|future',
 *   ]);
 *
 * Returns ['field' => 'message', ...] (first error per field). Empty = valid.
 */
final class Validator
{
    public static function make(array $data, array $rules, array $labels = []): array
    {
        $errors = [];
        foreach ($rules as $field => $ruleString) {
            $value = $data[$field] ?? null;
            if (is_string($value)) {
                $value = trim($value);
            }
            $isEmpty = $value === null || $value === '' || $value === [];
            $label = $labels[$field] ?? 'This field';

            foreach (explode('|', $ruleString) as $rule) {
                [$name, $arg] = array_pad(explode(':', $rule, 2), 2, null);

                if ($name === 'required') {
                    if ($isEmpty) {
                        $errors[$field] = 'Please fill in this field.';
                        break;
                    }
                    continue;
                }
                if ($name === 'accepted') {
                    if (!in_array($value, ['1', 'on', 'yes', 1, true], true)) {
                        $errors[$field] = 'Please tick this box to continue.';
                        break;
                    }
                    continue;
                }
                if ($isEmpty) {
                    continue; // optional and empty: skip remaining rules
                }

                $msg = self::checkRule($name, $arg, $value, $label, $data);
                if ($msg !== null) {
                    $errors[$field] = $msg;
                    break;
                }
            }
        }
        return $errors;
    }

    private static function checkRule(string $name, ?string $arg, mixed $value, string $label, array $data): ?string
    {
        $str = is_scalar($value) ? (string) $value : '';
        switch ($name) {
            case 'max':
                return mb_strlen($str) > (int) $arg ? "Please use {$arg} characters or fewer." : null;
            case 'min':
                return mb_strlen($str) < (int) $arg ? "Please use at least {$arg} characters." : null;
            case 'mobile':
                return normalize_mobile($str) === null
                    ? 'Please enter a Philippine mobile number, e.g. 0917 123 4567 or +63 917 123 4567.' : null;
            case 'email':
                return filter_var($str, FILTER_VALIDATE_EMAIL) ? null : 'Please enter a valid email address.';
            case 'date':
                return self::isDate($str) ? null : 'Please choose a valid date.';
            case 'future': // today or later
                return self::isDate($str) && $str >= Clock::today() ? null : 'Please choose today or a later date.';
            case 'time':
                return preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', $str) ? null : 'Please enter a valid time.';
            case 'after_time': // end time after another field's time
                $other = trim((string) ($data[$arg] ?? ''));
                return ($other === '' || $str > $other) ? null : 'The end time must be after the start time.';
            case 'in':
                return in_array($str, explode(',', (string) $arg), true) ? null : 'Please choose one of the options.';
            case 'integer':
                return preg_match('/^\d+$/', $str) ? null : 'Please enter a whole number.';
            case 'money':
                return preg_match('/^\d{1,8}(\.\d{1,2})?$/', str_replace(',', '', $str)) ? null : 'Please enter an amount, e.g. 2500 or 2500.00.';
            case 'min_value':
                return (float) str_replace(',', '', $str) >= (float) $arg ? null : "Please enter at least {$arg}.";
            case 'gcash_ref':
                // GCash reference numbers are 13 digits; allow 6-20 digits/letters to be safe.
                return preg_match('/^[A-Za-z0-9]{6,20}$/', preg_replace('/\s+/', '', $str)) ? null
                    : 'Please enter the reference number exactly as shown in GCash (numbers only, no spaces).';
            case 'username':
                return preg_match('/^[a-z0-9_.]{3,50}$/', $str) ? null : 'Use 3-50 lowercase letters, numbers, dots or underscores.';
            case 'password':
                return self::passwordProblem($str);
            case 'same':
                return $str === (string) ($data[$arg] ?? '') ? null : 'The two passwords do not match.';
            case 'url':
                return filter_var($str, FILTER_VALIDATE_URL) ? null : 'Please enter a full link starting with https://';
        }
        throw new \InvalidArgumentException('Unknown validation rule: ' . $name);
    }

    public static function isDate(string $s): bool
    {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $s, $m)) {
            return false;
        }
        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    /** Strong-password rule for new/changed passwords. Returns a message or null. */
    public static function passwordProblem(string $pw): ?string
    {
        if (strlen($pw) < 8) {
            return 'Use at least 8 characters.';
        }
        if (!preg_match('/[A-Za-z]/', $pw) || !preg_match('/\d/', $pw)) {
            return 'Use letters and at least one number.';
        }
        if (in_array(strtolower($pw), ['password1', 'password123', '12345678a', 'douffer123', 'qwerty123'], true)) {
            return 'This password is too common. Please choose another.';
        }
        return null;
    }
}
