<?php
class Response {
    public static function json($data = [], $code = 0, $message = 'success', $httpCode = 200) {
        http_response_code($httpCode);
        header('Content-Type: application/json; charset=utf-8');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Callback-Token');

        echo json_encode([
            'code' => $code,
            'message' => $message,
            'data' => $data,
            'timestamp' => time(),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    public static function success($data = [], $message = 'success') {
        self::json($data, 0, $message, 200);
    }

    public static function error($message = 'error', $code = 1, $httpCode = 400) {
        self::json([], $code, $message, $httpCode);
    }

    public static function unauthorized($message = 'Unauthorized') {
        self::json([], 401, $message, 401);
    }

    public static function notFound($message = 'Not Found') {
        self::json([], 404, $message, 404);
    }
}
