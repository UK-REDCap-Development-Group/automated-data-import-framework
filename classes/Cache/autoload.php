<?php
// Autoloader for UKModules\ADIF\Cache\* -> classes/Cache/*. Replace with Composer if the module adopts it.
spl_autoload_register(function (string $class) {
    $prefix = 'UKModules\\ADIF\\Cache\\';
    if (strncmp($class, $prefix, strlen($prefix)) === 0) {
        $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});
