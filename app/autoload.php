<?php
/**
 * Minimal PSR-4-style autoloader. No Composer — App\Foo\Bar maps to
 * app/Foo/Bar.php relative to this file.
 */
spl_autoload_register(function (string $class): void {
    $prefix = 'App\\';
    if (strncmp($prefix, $class, strlen($prefix)) !== 0) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $file = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';

    if (is_file($file)) {
        require $file;
    }
});
