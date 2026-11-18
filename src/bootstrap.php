<?php
declare(strict_types=1);

const ROOT = __DIR__ . '/..';
ini_set('display_errors', '0');
ini_set('log_errors', '1');
date_default_timezone_set('UTC');
if (is_file(ROOT . '/.env')) {
    foreach (file(ROOT . '/.env', FILE_IGNORE_NEW_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) continue;
        [$key, $value] = explode('=', $line, 2);
        if (getenv(trim($key)) === false) putenv(trim($key) . '=' . trim($value, " \t\"'"));
    }
}
function env(string $key, string $default = ''): string {
    $value = getenv($key);
    return $value === false ? $default : $value;
}
function demo(): bool { return env('APP_ENV', 'demo') === 'demo' && env('DB_DRIVER', 'sqlite') === 'sqlite'; }
function integrations(): array {
    static $config;
    return $config ??= array_replace(require ROOT . '/config/integrations.php',
        is_file(ROOT . '/config/integrations.local.php') ? require ROOT . '/config/integrations.local.php' : []);
}
final class HttpError extends RuntimeException {
    public function __construct(public int $status, string $message) { parent::__construct($message); }
}
final class Database { public static ?PDO $connection = null; }
function db(): PDO {
    $pdo = &Database::$connection;
    if ($pdo) return $pdo;
    $driver = env('DB_DRIVER', 'sqlite');
    $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC];
    if ($driver === 'sqlite') {
        if (!demo()) throw new RuntimeException('SQLite solo esta habilitado para demostracion.');
        $path = env('SQLITE_PATH', ROOT . '/storage/demo.sqlite');
        if (!is_file($path)) throw new HttpError(503, 'Primero ejecuta php scripts/setup-demo.php para preparar la demostración.');
        $pdo = new PDO('sqlite:' . $path, null, null, $options);
        $pdo->exec('PRAGMA foreign_keys=ON; PRAGMA busy_timeout=5000;');
    } elseif ($driver === 'mysql') {
        $dsn = 'mysql:host=' . env('DB_HOST', '127.0.0.1') . ';port=' . env('DB_PORT', '3306') . ';dbname=' . env('DB_NAME', 'bd_fase2') . ';charset=utf8mb4';
        $pdo = new PDO($dsn, env('DB_USER'), env('DB_PASSWORD'), $options + [PDO::ATTR_EMULATE_PREPARES => false]);
        $pdo->exec("SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED");
        $pdo->exec("SET time_zone = '+00:00'");
    } elseif ($driver === 'pgsql') {
        $dsn = 'pgsql:host=' . env('DB_HOST') . ';port=' . env('DB_PORT', '5432') . ';dbname=' . env('DB_NAME', 'postgres') . ';sslmode=' . env('DB_SSLMODE', 'require');
        $pdo = new PDO($dsn, env('DB_USER'), env('DB_PASSWORD'), $options + [PDO::ATTR_EMULATE_PREPARES => false]);
        $pdo->exec("SET TIME ZONE 'UTC'");
    } else throw new RuntimeException('DB_DRIVER no soportado.');
    return $pdo;
}
function query(string $sql, array $params = []): PDOStatement {
    $stmt = db()->prepare($sql); $stmt->execute($params); return $stmt;
}
function one(string $sql, array $params = []): ?array { return query($sql, $params)->fetch() ?: null; }
function lockSql(): string { return db()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? '' : ' FOR UPDATE'; }
function transaction(callable $action): mixed {
    $pdo = db(); $sqlite = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
    $sqlite ? $pdo->exec('BEGIN IMMEDIATE') : $pdo->beginTransaction();
    try { $result = $action(); $sqlite ? $pdo->exec('COMMIT') : $pdo->commit(); return $result; }
    catch (Throwable $e) { $sqlite ? $pdo->exec('ROLLBACK') : $pdo->rollBack(); throw $e; }
}
function startSession(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    ini_set('session.use_strict_mode', '1'); ini_set('session.use_only_cookies', '1');
    session_name(env('SESSION_NAME', 'nexo_session'));
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => env('COOKIE_SECURE', demo() ? '0' : '1') === '1', 'httponly' => true, 'samesite' => 'Lax']);
    session_start();
    if (isset($_SESSION['last_activity']) && time() - $_SESSION['last_activity'] > 7200) {
        $_SESSION = []; session_regenerate_id(true);
    }
    $_SESSION['last_activity'] = time();
    $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
}
function currentUser(bool $required = false): ?array {
    $id = $_SESSION[integrations()['session_user_key']] ?? null;
    $user = $id ? one('SELECT id_usuario,nombre,apellidos,email,rol,password_hash FROM usuarios WHERE id_usuario=? AND activo=1', [$id]) : null;
    if ($user && isset($_SESSION['auth_fingerprint']) && !hash_equals($_SESSION['auth_fingerprint'], hash('sha256', $user['password_hash']))) {
        unset($_SESSION[integrations()['session_user_key']], $_SESSION['auth_fingerprint']); $user = null;
    }
    if ($user) unset($user['password_hash']);
    if (!$user && $required) throw new HttpError(401, 'Inicia sesión para continuar.');
    return $user;
}
function student(): array {
    $user = currentUser(true);
    if ($user['rol'] !== 'Alumno') throw new HttpError(403, 'Esta acción está disponible únicamente para alumnos.');
    return $user;
}
function textField(array $data, string $key, int $max): string {
    $value = trim(is_string($data[$key] ?? null) ? $data[$key] : '');
    if ($value === '' || mb_strlen($value) > $max) throw new HttpError(422, "Revisa el campo $key.");
    return $value;
}
function emailField(array $data): string {
    $email = strtolower(textField($data, 'email', 120));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new HttpError(422, 'Introduce un correo electrónico válido.');
    return $email;
}
function passwordField(array $data): string {
    $password = is_string($data['password'] ?? null) ? $data['password'] : '';
    if (strlen($password) < 10 || strlen($password) > 72) throw new HttpError(422, 'La contraseña debe tener entre 10 y 72 bytes.');
    return $password;
}
function positiveId(mixed $value): int {
    $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($id === false) throw new HttpError(422, 'Identificador inválido.');
    return $id;
}
// Limite persistente por IP y accion; no se evade creando otra cookie de sesion.
function rateLimit(string $action, int $max, int $seconds): void {
    $key = hash('sha256', $action . ':' . ($_SERVER['REMOTE_ADDR'] ?? 'cli'));
    $path = ROOT . '/storage/rate-' . $key . '.json';
    $file = fopen($path, 'c+');
    if (!$file || !flock($file, LOCK_EX)) throw new HttpError(503, 'Intenta nuevamente en unos minutos.');
    try {
        $items = json_decode(stream_get_contents($file), true) ?: [];
        $items = array_values(array_filter($items, fn($t) => $t > time() - $seconds));
        if (count($items) >= $max) throw new HttpError(429, 'Demasiados intentos. Espera unos minutos e intenta de nuevo.');
        $items[] = time(); rewind($file); ftruncate($file, 0); fwrite($file, json_encode($items));
    } finally { flock($file, LOCK_UN); fclose($file); }
}
function payment(int $userId, int $courseId, string $type, string $amount, string $key): void {
    if (demo() || (float)$amount === 0.0) return;
    $verify = integrations()['verify_payment'];
    if (!$verify || !$verify($userId, $courseId, $type, $amount, $key)) {
        throw new HttpError(409, 'No encontramos un pago confirmado para esta operación. Contacta a Nexo para completar tu inscripción.');
    }
}
