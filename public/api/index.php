<?php
declare(strict_types=1);
require __DIR__ . '/../../src/bootstrap.php';
require ROOT . '/src/api.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
try {
    startSession();
    $action = $_GET['action'] ?? 'session';
    $reads = array_merge(['session','courses','course','my-courses','classroom'], adminReadActions());
    $writes = array_merge(['login','register','logout','enroll','withdraw','forgot','reset'], adminWriteActions());
    if (!is_string($action) || !in_array($action, array_merge($reads, $writes), true)) throw new HttpError(404, 'Ruta no encontrada.');
    $method = in_array($action, $reads, true) ? 'GET' : 'POST';
    if ($_SERVER['REQUEST_METHOD'] !== $method) { header('Allow: ' . $method); throw new HttpError(405, 'Método no permitido.'); }
    $data = [];
    if ($method === 'POST') {
        if (!hash_equals($_SESSION['csrf'], $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) throw new HttpError(419, 'Tu sesión cambió. Actualiza la página e intenta de nuevo.');
        if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 16384) throw new HttpError(413, 'Solicitud demasiado grande.');
        if (!str_starts_with(strtolower($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json')) throw new HttpError(415, 'Se requiere JSON.');
        $data = json_decode(file_get_contents('php://input', false, null, 0, 16385), true);
        if (!is_array($data)) throw new HttpError(400, 'Solicitud inválida.');
    }
    $result = dispatch($action, $data);
    echo json_encode(['ok' => true, 'data' => $result, 'csrf' => $_SESSION['csrf']], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
} catch (HttpError $e) {
    http_response_code($e->status); echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log((string)$e);
    http_response_code(500); echo json_encode(['ok' => false, 'error' => 'No fue posible completar la operación. Revisa la conexión del servidor.'], JSON_UNESCAPED_UNICODE);
}
