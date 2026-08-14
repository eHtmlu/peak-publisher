<?php

namespace Pblsh;

defined('ABSPATH') || exit;


/**
 * Error type of the wordpress.org SVN pipeline (client transport and deploy workflow).
 * Carries a machine-readable error code, a user-facing localized message, and the
 * HTTP status the REST layer should respond with.
 */
class WporgSvnException extends \RuntimeException {
    private string $error_code;
    private int $http_status;

    public function __construct(string $error_code, string $message, int $http_status) {
        parent::__construct($message);
        $this->error_code = $error_code;
        $this->http_status = $http_status;
    }

    public static function from_wp_error(\WP_Error $error): self {
        $data = $error->get_error_data();
        $status = is_array($data) && isset($data['status']) ? (int) $data['status'] : 400;
        return new self((string) $error->get_error_code(), $error->get_error_message(), $status);
    }

    public function get_error_code(): string {
        return $this->error_code;
    }

    public function get_http_status(): int {
        return $this->http_status;
    }
}
