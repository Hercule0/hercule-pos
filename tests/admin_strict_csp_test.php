<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$adminRoot = $root . '/public/admin';
$assetJsRoot = $adminRoot . '/assets/js';
$bootstrapPath = $adminRoot . '/includes/bootstrap.php';
$errorHandlerPath = $root . '/includes/ErrorHandler.php';

$fail = static function (string $message): never {
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
};

$bootstrap = file_get_contents($bootstrapPath);
if (!is_string($bootstrap)) $fail('admin bootstrap could not be read');
$errorHandler = file_get_contents($errorHandlerPath);
if (!is_string($errorHandler)) $fail('error handler could not be read');

$phpFiles = glob($adminRoot . '/*.php') ?: [];
if (!$phpFiles) $fail('no top-level admin PHP files were found');

foreach ($phpFiles as $file) {
    $source = file_get_contents($file);
    if (!is_string($source)) $fail('could not read ' . basename($file));
    $name = basename($file);

    if (preg_match('/<style\b/i', $source)) {
        $fail("{$name} contains an inline style block");
    }
    if (preg_match('/\sstyle\s*=\s*/i', $source)) {
        $fail("{$name} contains an inline style attribute");
    }
    if (preg_match('/\son[a-z]+\s*=\s*/i', $source)) {
        $fail("{$name} contains an inline event handler");
    }
    if (preg_match('/<script(?![^>]*\bsrc=)[^>]*>/i', $source)) {
        $fail("{$name} contains inline JavaScript");
    }
}

$jsFiles = glob($assetJsRoot . '/*.js') ?: [];
if (!$jsFiles) $fail('no admin JavaScript assets were found');

foreach ($jsFiles as $file) {
    $source = file_get_contents($file);
    if (!is_string($source)) $fail('could not read ' . basename($file));
    $name = basename($file);

    if (preg_match('/\.style\s*(?:\.|\[|=)/', $source)) {
        $fail("{$name} mutates element.style and would require style-src unsafe-inline");
    }
    if (preg_match('/setAttribute\s*\(\s*[\'\"]style[\'\"]/i', $source)) {
        $fail("{$name} sets an inline style attribute");
    }
    if (str_contains($source, 'insertAdjacentHTML') || str_contains($source, '.innerHTML')) {
        $fail("{$name} uses raw HTML insertion");
    }
}

if (str_contains($bootstrap, "script-src 'self' 'unsafe-inline'")) {
    $fail('admin CSP still allows unsafe-inline scripts');
}
if (str_contains($bootstrap, "style-src 'self' 'unsafe-inline'")) {
    $fail('admin CSP still allows unsafe-inline styles');
}
if (!str_contains($bootstrap, "script-src 'self';")) {
    $fail('admin CSP is missing strict self-only script-src');
}
if (!str_contains($bootstrap, "style-src 'self' https://fonts.googleapis.com;")) {
    $fail('admin CSP is missing strict stylesheet policy');
}

if (str_contains($errorHandler, 'header_register_callback')) {
    $fail('error boundary still installs a final-header CSP override');
}
if (str_contains($errorHandler, "header_remove('Content-Security-Policy')")
    || str_contains($errorHandler, 'header_remove("Content-Security-Policy")')) {
    $fail('error boundary can remove the admin Content-Security-Policy');
}
if (str_contains($errorHandler, "'unsafe-inline'") || str_contains($errorHandler, "'unsafe-eval'")) {
    $fail('error boundary contains a permissive CSP directive');
}

echo "PASS full admin strict CSP gate\n";
