<?php
// env_loader.php
// Load and parse .env file

function loadEnv($path = '.env') {
    if (!file_exists($path)) {
        // For local XAMPP this is fine – just skip if .env missing
        return;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        // Skip comments and empty lines
        if (strpos(trim($line), '#') === 0 || trim($line) === '') {
            continue;
        }

        // Split name and value
        list($name, $value) = explode('=', $line, 2);
        $name  = trim($name);
        $value = trim($value);

        // Remove quotes if present
        if (preg_match('/^([\'"])(.*)\1$/', $value, $matches)) {
            $value = $matches[2];
        }

        // Set environment variable
        putenv("$name=$value");
        $_ENV[$name]    = $value;
        $_SERVER[$name] = $value;
    }
}

// Load environment variables automatically; tolerate missing .env on Railway
try {
    loadEnv();
} catch (Throwable $e) {
    // File not found or unreadable? Use environment variables supplied by the host.
}
