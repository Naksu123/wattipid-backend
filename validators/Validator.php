<?php
require_once __DIR__ . '/../helpers/ResponseHelper.php';

class Validator {
    /**
     * Ensures all required keys exist in the data array.
     */
    public static function validate($data, $requiredFields) {
        $missing = [];
        foreach ($requiredFields as $field) {
            if (!isset($data[$field]) || (is_string($data[$field]) && trim($data[$field]) === '')) {
                $missing[] = $field;
            }
        }

        if (!empty($missing)) {
            ResponseHelper::error("Missing required fields: " . implode(', ', $missing), 400);
        }

        return true;
    }

    /**
     * Validates an email format.
     */
    public static function email($email) {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            ResponseHelper::error("Invalid email format", 400);
        }
        return true;
    }
}
