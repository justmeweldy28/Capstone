<?php
/**
 * TRACEGRAD AI Assistant configuration.
 *
 * SECURITY:
 * - Never place the API key in index.php, script.js, or any browser-side file.
 * - Prefer setting OPENAI_API_KEY as a server environment variable.
 * - For local XAMPP development, you may temporarily set the key below.
 */

if (!defined('TRACEGRAD_OPENAI_API_KEY')) {
    $envKey = getenv('OPENAI_API_KEY');

    define(
        'TRACEGRAD_OPENAI_API_KEY',
        is_string($envKey) && trim($envKey) !== ''
            ? trim($envKey)
            : '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'
    );
}

if (!defined('TRACEGRAD_OPENAI_MODEL')) {
    define(
        'TRACEGRAD_OPENAI_MODEL',
        getenv('OPENAI_MODEL') ?: 'gpt-5.4-mini'
    );
}
