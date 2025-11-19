<?php
// env_loader.php
// Load and parse .env file

function loadEnv($path = '.env') {
    if (!file_exists($path)) {
        throw new Exception('.env file not found');
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        // Skip comments and empty lines
        if (strpos(trim($line), '#') === 0 || trim($line) === '') {
            continue;
        }

        // Split name and value
        list($name, $value) = explode('=', $line, 2);
        $name = trim($name);
        $value = trim($value);

        // Remove quotes if present
        if (preg_match('/^([\'"])(.*)\1$/', $value, $matches)) {
            $value = $matches[2];
        }

        // Set environment variable
        putenv("$name=$value");
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }
}

// Load environment variables automatically
loadEnv();
?>