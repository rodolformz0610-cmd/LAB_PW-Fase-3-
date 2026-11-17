<?php
declare(strict_types=1);
if (PHP_VERSION_ID < 80200 || !extension_loaded('pdo_sqlite') || !extension_loaded('mbstring')) {
    fwrite(STDERR, "Se requiere PHP 8.2+ con pdo_sqlite y mbstring habilitados.\n");
    exit(1);
}
echo "PHP listo para ejecutar la demostracion.\n";
