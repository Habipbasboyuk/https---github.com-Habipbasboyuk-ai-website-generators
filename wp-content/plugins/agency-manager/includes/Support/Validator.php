<?php
namespace AM\Support;

use WP_Error;

if (!defined('ABSPATH')) { exit; }

final class Validator {
    public static function require_int($value, string $field): int {
        $int = intval($value);
        if ($int <= 0) {
            throw new \InvalidArgumentException(sprintf('%s must be a positive integer', $field));
        }
        return $int;
    }

    public static function sanitize_status_key(string $key): string {
        $key = sanitize_key($key);
        if ($key === '') {
            throw new \InvalidArgumentException('status_key is required');
        }
        return $key;
    }

    public static function workflow_is_valid(array $workflow): bool {
        if (!isset($workflow['statuses']) || !is_array($workflow['statuses'])) return false;
        $seen = [];
        foreach ($workflow['statuses'] as $st) {
            if (!is_array($st)) return false;
            $k = sanitize_key((string)($st['key'] ?? ''));
            $n = (string)($st['name'] ?? '');
            if ($k === '' || $n === '') return false;
            if (isset($seen[$k])) return false;
            $seen[$k] = true;
        }
        return true;
    }

    public static function error(string $code, string $message, int $status = 400): WP_Error {
        return new WP_Error($code, $message, ['status' => $status]);
    }
}
