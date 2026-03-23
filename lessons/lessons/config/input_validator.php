<?php
/**
 * Input Validation and Sanitization Class
 * Provides consistent input handling and validation across the application
 */

class InputValidator
{
    /**
     * Validate and sanitize a string
     * 
     * @param mixed $value The value to validate
     * @param array $options Options array with keys: required, min_length, max_length, pattern
     * @return string|null The validated string or null if invalid
     */
    public static function validateString($value, array $options = []): ?string
    {
        $required = $options['required'] ?? false;
        $minLength = $options['min_length'] ?? 0;
        $maxLength = $options['max_length'] ?? PHP_INT_MAX;
        $pattern = $options['pattern'] ?? null;

        if ($value === null || $value === '') {
            return $required ? null : '';
        }

        $value = trim((string) $value);

        if (strlen($value) < $minLength || strlen($value) > $maxLength) {
            return null;
        }

        if ($pattern && !preg_match($pattern, $value)) {
            return null;
        }

        return $value;
    }

    /**
     * Validate and sanitize an email
     * 
     * @param mixed $value The value to validate
     * @param bool $required Whether email is required
     * @return string|null The validated email or null if invalid
     */
    public static function validateEmail($value, bool $required = true): ?string
    {
        $value = trim((string) ($value ?? ''));

        if (empty($value)) {
            return $required ? null : null;
        }

        if (strlen($value) > 254) {
            return null;
        }

        $email = filter_var($value, FILTER_SANITIZE_EMAIL);
        
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        return $email;
    }

    /**
     * Validate an integer
     * 
     * @param mixed $value The value to validate
     * @param array $options Options array with keys: required, min, max
     * @return int|null The validated integer or null if invalid
     */
    public static function validateInteger($value, array $options = []): ?int
    {
        $required = $options['required'] ?? false;
        $min = $options['min'] ?? PHP_INT_MIN;
        $max = $options['max'] ?? PHP_INT_MAX;

        if ($value === null || $value === '') {
            return $required ? null : null;
        }

        $int = filter_var($value, FILTER_VALIDATE_INT);

        if ($int === false) {
            return null;
        }

        if ($int < $min || $int > $max) {
            return null;
        }

        return $int;
    }

    /**
     * Validate a boolean
     * 
     * @param mixed $value The value to validate
     * @return bool|null True, false, or null if invalid
     */
    public static function validateBoolean($value): ?bool
    {
        if ($value === null || $value === '') {
            return null;
        }

        $bool = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        return $bool;
    }

    /**
     * Validate a URL
     * 
     * @param mixed $value The value to validate
     * @param bool $required Whether URL is required
     * @return string|null The validated URL or null if invalid
     */
    public static function validateURL($value, bool $required = true): ?string
    {
        $value = trim((string) ($value ?? ''));

        if (empty($value)) {
            return $required ? null : null;
        }

        if (!filter_var($value, FILTER_VALIDATE_URL)) {
            return null;
        }

        return $value;
    }

    /**
     * Validate that value is one of allowed options
     * 
     * @param mixed $value The value to validate
     * @param array $allowedValues Array of allowed values
     * @param bool $required Whether value is required
     * @return string|null The validated value or null if invalid
     */
    public static function validateEnum($value, array $allowedValues, bool $required = true): ?string
    {
        $value = trim((string) ($value ?? ''));

        if (empty($value)) {
            return $required ? null : null;
        }

        if (!in_array($value, $allowedValues, true)) {
            return null;
        }

        return $value;
    }

    /**
     * Validate array contents
     * 
     * @param mixed $value The value to validate
     * @param array $itemSchema Schema for validating each item
     * @param bool $required Whether array is required
     * @return array|null The validated array or null if invalid
     */
    public static function validateArray($value, array $itemSchema = [], bool $required = true): ?array
    {
        if (!is_array($value)) {
            return $required ? null : [];
        }

        if (empty($itemSchema)) {
            return $value;
        }

        $validated = [];
        $validator = $itemSchema['type'] ?? 'string';
        $options = $itemSchema['options'] ?? [];

        foreach ($value as $item) {
            $result = match ($validator) {
                'string' => self::validateString($item, $options),
                'integer' => self::validateInteger($item, $options),
                'email' => self::validateEmail($item, false),
                'url' => self::validateURL($item, false),
                default => $item,
            };

            if ($result !== null) {
                $validated[] = $result;
            }
        }

        return $validated;
    }

    /**
     * Batch validate an array of form inputs
     * 
     * @param array $data The data to validate (typically $_POST or $_GET)
     * @param array $schema Validation schema
     * @return array Associative array with 'valid' (validated data) and 'errors' (validation errors)
     * 
     * Example schema:
     * [
     *     'email' => ['type' => 'email', 'required' => true],
     *     'age' => ['type' => 'integer', 'required' => true, 'min' => 0, 'max' => 150],
     *     'website' => ['type' => 'url', 'required' => false],
     * ]
     */
    public static function validateFormData(array $data, array $schema): array
    {
        $valid = [];
        $errors = [];

        foreach ($schema as $fieldName => $fieldSchema) {
            $type = $fieldSchema['type'] ?? 'string';
            $required = $fieldSchema['required'] ?? false;
            $value = $data[$fieldName] ?? null;

            $result = null;

            switch ($type) {
                case 'string':
                    $result = self::validateString($value, [
                        'required' => $required,
                        'min_length' => $fieldSchema['min_length'] ?? 0,
                        'max_length' => $fieldSchema['max_length'] ?? PHP_INT_MAX,
                        'pattern' => $fieldSchema['pattern'] ?? null,
                    ]);
                    break;

                case 'email':
                    $result = self::validateEmail($value, $required);
                    break;

                case 'integer':
                    $result = self::validateInteger($value, [
                        'required' => $required,
                        'min' => $fieldSchema['min'] ?? PHP_INT_MIN,
                        'max' => $fieldSchema['max'] ?? PHP_INT_MAX,
                    ]);
                    break;

                case 'boolean':
                    $result = self::validateBoolean($value);
                    break;

                case 'url':
                    $result = self::validateURL($value, $required);
                    break;

                case 'enum':
                    $result = self::validateEnum($value, $fieldSchema['values'] ?? [], $required);
                    break;

                case 'array':
                    $result = self::validateArray($value, $fieldSchema['items'] ?? [], $required);
                    break;
            }

            if ($required && $result === null) {
                $errors[$fieldName] = $fieldSchema['error_message'] ?? "El campo '$fieldName' es requerido";
            } elseif ($result !== null || !$required) {
                $valid[$fieldName] = $result;
            }
        }

        return [
            'valid' => $valid,
            'errors' => $errors,
            'is_valid' => empty($errors),
        ];
    }

    /**
     * Sanitize HTML input (strip tags or escape)
     * 
     * @param string $input The input to sanitize
     * @param bool $stripTags Whether to strip tags or escape
     * @return string The sanitized input
     */
    public static function sanitizeHTML(string $input, bool $stripTags = false): string
    {
        if ($stripTags) {
            return strip_tags(trim($input));
        }
        return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
    }

    /**
     * Check if request is from expected method
     * 
     * @param string $method The expected HTTP method (GET, POST, etc.)
     * @return bool True if request method matches
     */
    public static function isRequestMethod(string $method): bool
    {
        return strtoupper($_SERVER['REQUEST_METHOD'] ?? '') === strtoupper($method);
    }

    /**
     * Check if input is coming from a form (has POST or GET data)
     * 
     * @return bool True if request appears to be form input
     */
    public static function isFormSubmission(): bool
    {
        return self::isRequestMethod('POST') || self::isRequestMethod('GET');
    }
}
