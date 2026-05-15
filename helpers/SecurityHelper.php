<?php
/**
 * Wattipid SecurityHelper
 * 
 * Centralized security utilities for sanitization and validation.
 */

class SecurityHelper {
    /**
     * Recursively sanitizes an array or string to prevent XSS.
     */
    public static function sanitize($data) {
        if (is_array($data)) {
            foreach ($data as $key => $value) {
                $data[$key] = self::sanitize($value);
            }
        } else if (is_string($data)) {
            // For JSON APIs consumed by React Native, do not use htmlspecialchars 
            // as it breaks characters like '&' and apostrophes.
            $data = strip_tags($data);
        }
        return $data;
    }

    /**
     * Validates input against common patterns.
     */
    public static function validate($input, $rules) {
        $errors = [];
        foreach ($rules as $field => $rule) {
            $value = $input[$field] ?? null;

            if (strpos($rule, 'required') !== false && empty($value)) {
                $errors[] = "$field is required";
                continue;
            }

            if ($value === null) continue;

            if (strpos($rule, 'email') !== false && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                $errors[] = "$field must be a valid email";
            }

            if (strpos($rule, 'int') !== false && !is_numeric($value)) {
                $errors[] = "$field must be an integer";
            }

            if (preg_match('/min:(\d+)/', $rule, $matches)) {
                if (strlen($value) < $matches[1]) {
                    $errors[] = "$field must be at least {$matches[1]} characters";
                }
            }
        }
        return $errors;
    }
}
