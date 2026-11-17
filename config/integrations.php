<?php
declare(strict_types=1);

/* PUNTOS DE UNION CON EL EQUIPO.
 * Copiar a integrations.local.php para ajustes de cada equipo (no se sube a Git).
 * Todos los callbacks se ejecutan en el servidor; nunca reciben codigo del navegador.
 */
return [
    // El login compartido debe guardar un ID entero en esta clave de $_SESSION.
    'session_user_key' => 'id_usuario',
    // Conectar aqui el contenido real del equipo. Debe devolver un arreglo como content.php.
    // function (int $courseId, int $userId): array { return ...; }
    'course_content' => null,
    // Recuperacion: implementar con el proveedor SMTP del equipo.
    // function (string $email, string $url): void { ...; } Lanzar excepcion si falla.
    'send_reset_email' => null,
    // Cursos de pago en produccion requieren un pago YA VERIFICADO en servidor.
    // function (int $userId, int $courseId, string $type, string $amount, string $key): bool
    // Debe ser idempotente por $key. Devolver true solo si el pago esta confirmado.
    // No cobrar dentro de la transaccion: consultar una orden previamente liquidada.
    'verify_payment' => null,
    // La baja es gratuita por defecto; no hay tarifa concreta en el documento.
    'withdrawal_fee' => '0.00',
];
