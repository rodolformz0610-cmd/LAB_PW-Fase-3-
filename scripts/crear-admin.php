<?php
declare(strict_types=1);
// Crea una cuenta de administrador (o promueve/restablece una existente) en la base configurada en .env.
// Uso:  php scripts/crear-admin.php correo@dominio.com "Nombre" "Apellidos"
// La contraseña se pide por teclado, o se toma de la variable NEXO_ADMIN_PASSWORD. No se guarda en ningún archivo.
require __DIR__ . '/../src/bootstrap.php';
if (PHP_SAPI !== 'cli') exit(1);
[, $email, $name, $last] = $argv + [null, null, null, null];
if (!$email || !$name || !$last) { fwrite(STDERR, "Uso: php scripts/crear-admin.php correo \"Nombre\" \"Apellidos\"\n"); exit(1); }
$email = strtolower(trim($email));
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 120 || mb_strlen($name) > 60 || mb_strlen($last) > 80) {
    fwrite(STDERR, "Correo, nombre o apellidos no válidos.\n"); exit(1);
}
$password = getenv('NEXO_ADMIN_PASSWORD') ?: '';
if ($password === '') { fwrite(STDOUT, 'Contraseña (10 a 72 caracteres): '); $password = rtrim((string)fgets(STDIN), "\r\n"); }
if (strlen($password) < 10 || strlen($password) > 72) { fwrite(STDERR, "La contraseña debe tener entre 10 y 72 bytes.\n"); exit(1); }
$hash = password_hash($password, PASSWORD_DEFAULT);
$existing = one('SELECT id_usuario FROM usuarios WHERE email=?', [$email]);
if ($existing) {
    query("UPDATE usuarios SET nombre=?,apellidos=?,password_hash=?,rol='Administrador',activo=1 WHERE id_usuario=?", [$name, $last, $hash, $existing['id_usuario']]);
    echo "Cuenta existente actualizada como administrador: $email\n";
} else {
    query("INSERT INTO usuarios(nombre,apellidos,email,password_hash,rol) VALUES (?,?,?,?,'Administrador')", [$name, $last, $email, $hash]);
    echo "Administrador creado: $email\n";
}
