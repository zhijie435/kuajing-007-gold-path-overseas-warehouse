<?php
class Request {
    public static function getInput() {
        $input = file_get_contents('php://input');
        $data = json_decode($input, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $data;
        }
        return $_POST;
    }

    public static function getQuery() {
        return $_GET;
    }

    public static function getHeader($name) {
        $headers = function_exists('getallheaders') ? getallheaders() : [];
        $name = strtolower($name);
        foreach ($headers as $key => $value) {
            if (strtolower($key) === $name) {
                return $value;
            }
        }
        return null;
    }

    public static function getMethod() {
        return $_SERVER['REQUEST_METHOD'];
    }

    public static function getUri() {
        return parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    }
}
