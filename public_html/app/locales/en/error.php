<?php

declare(strict_types=1);

return [
    'session_auto_start' => 'Causette requires PHP sessions with automatic session start disabled.',
    'configured_https_required' => 'Open Causette using the configured HTTPS address.',
    'invalid_proxy_scheme' => 'The approved proxy did not report a valid request scheme.',
    'https_page_required' => 'Open the HTTPS page before submitting this request.',
    'session_start_failed' => 'Causette could not start a protected session.',
    'database_configuration_mismatch' => 'The Causette private configuration does not match the selected database.',
    'forbidden' => 'Forbidden',
    'forbidden_help' => 'You do not have permission to access this resource.',
    'not_found' => 'Not Found',
    'not_found_help' => 'The page you are looking for could not be found.',
    'session_expired' => 'Session Expired',
    'session_expired_help' => 'Your session has expired or the request could not be verified. Please go back and try again.',
    'failed_dependency' => 'Failed Dependency',
    'failed_dependency_help' => 'Causette has not been installed yet. Please initialize the application.',
    'server_error' => 'Server Error',
    'server_error_help' => 'Something went wrong on our side.',
    'error' => 'Error',
    'error_help' => 'An unexpected error occurred.',
    'back_home' => 'Back to home',
    'too_many_requests' => 'Too Many Requests',
    'debug_message' => 'Message: {value}',
    'debug_application_version' => 'App Version: {value}',
    'debug_database_version' => 'Database Version: {value}',
    'debug_file' => 'File: {value}',
    'debug_line' => 'Line: {value}',
    'debug_trace' => 'Trace:',
];
