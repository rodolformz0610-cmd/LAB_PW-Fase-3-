<?php
declare(strict_types=1);

require_once __DIR__ . '/admin_api.php';

function dispatch(string $action, array $data): mixed {
    return match ($action) {
        'session' => ['user' => currentUser(), 'demo' => demo(), 'withdrawalFee' => integrations()['withdrawal_fee']],
        'courses' => query('SELECT * FROM cursos WHERE activo=1 ORDER BY id_curso')->fetchAll(),
        'course' => courseDetail(positiveId($_GET['id'] ?? null)),
        'my-courses' => myCourses(),
        'classroom' => classroom(positiveId($_GET['id'] ?? null)),
        'register' => registerUser($data), 'login' => loginUser($data), 'logout' => logoutUser(),
        'enroll' => enroll(positiveId($data['id_grupo'] ?? null), textField($data, 'expected_price', 20)),
        'withdraw' => withdraw(positiveId($data['id_inscripcion'] ?? null)),
        'forgot' => forgotPassword($data), 'reset' => resetPassword($data),
        default => adminDispatch($action, $data),
    };
}
function courseDetail(int $id): array {
    $course = one('SELECT * FROM cursos WHERE id_curso=? AND activo=1', [$id]);
    if (!$course) throw new HttpError(404, 'Este curso no está disponible.');
    $course['grupos'] = query("SELECT g.*,u.nombre AS profesor_nombre,u.apellidos AS profesor_apellidos,
        (SELECT COUNT(*) FROM inscripciones i WHERE i.id_grupo=g.id_grupo AND i.estado='Activa') AS inscritos
        FROM grupos g JOIN usuarios u ON u.id_usuario=g.id_profesor WHERE g.id_curso=? AND g.activo=1 AND g.fecha_fin>=? ORDER BY g.fecha_inicio,g.id_grupo", [$id, date('Y-m-d')])->fetchAll();
    $user = currentUser();
    $course['inscripcion'] = $user ? one('SELECT id_inscripcion,id_grupo,estado FROM inscripciones WHERE id_usuario=? AND id_curso=?', [$user['id_usuario'], $id]) : null;
    return $course;
}
function myCourses(): array {
    $user = student();
    return query('SELECT i.*,c.nombre,c.descripcion,c.duracion_horas,c.imagen_url,c.activo AS curso_activo,
        g.nombre AS grupo_nombre,g.fecha_inicio,g.fecha_fin,g.activo AS grupo_activo,
        u.nombre AS profesor_nombre,u.apellidos AS profesor_apellidos
        FROM inscripciones i JOIN cursos c ON c.id_curso=i.id_curso JOIN grupos g ON g.id_grupo=i.id_grupo
        JOIN usuarios u ON u.id_usuario=g.id_profesor WHERE i.id_usuario=? ORDER BY i.fecha_inscripcion DESC', [$user['id_usuario']])->fetchAll();
}
function classroom(int $courseId): array {
    $user = student();
    $enrollment = one("SELECT i.id_inscripcion,c.nombre,g.nombre AS grupo_nombre FROM inscripciones i
        JOIN cursos c ON c.id_curso=i.id_curso JOIN grupos g ON g.id_grupo=i.id_grupo
        WHERE i.id_usuario=? AND i.id_curso=? AND i.estado='Activa' AND c.activo=1 AND g.activo=1", [$user['id_usuario'], $courseId]);
    if (!$enrollment) throw new HttpError(403, 'Necesitas una inscripción activa para acceder a este curso.');
    $provider = integrations()['course_content'];
    $modules = $provider ? $provider($courseId, (int)$user['id_usuario']) : (demo() ? (require ROOT . '/config/content.php')[$courseId] ?? [] : []);
    return ['curso' => $enrollment, 'modules' => $modules, 'demo' => demo() && !$provider];
}
function establishSession(array $user): array {
    session_regenerate_id(true);
    $_SESSION[integrations()['session_user_key']] = (int)$user['id_usuario'];
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
    $_SESSION['auth_fingerprint'] = hash('sha256', $user['password_hash']);
    return currentUser(true);
}
function registerUser(array $data): array {
    rateLimit('register', 10, 3600);
    $name = textField($data, 'nombre', 60); $last = textField($data, 'apellidos', 80);
    $email = emailField($data); $password = passwordField($data);
    try { query("INSERT INTO usuarios(nombre,apellidos,email,password_hash,rol) VALUES (?,?,?,?,'Alumno')", [$name, $last, $email, password_hash($password, PASSWORD_DEFAULT)]); }
    catch (PDOException $e) {
        if (in_array($e->getCode(), ['23000','23505'], true)) throw new HttpError(409, 'Ya existe una cuenta con este correo. Inicia sesión o recupera tu contraseña.');
        throw $e;
    }
    return establishSession(one('SELECT * FROM usuarios WHERE email=?', [$email]));
}
function loginUser(array $data): array {
    rateLimit('login', 15, 900);
    $email = emailField($data); $password = is_string($data['password'] ?? null) ? $data['password'] : '';
    $user = one('SELECT * FROM usuarios WHERE email=? AND activo=1', [$email]);
    $dummy = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.';
    $valid = password_verify($password, $user['password_hash'] ?? $dummy);
    if (!$valid || !$user) throw new HttpError(401, 'El correo o la contraseña son incorrectos.');
    return establishSession($user);
}
function logoutUser(): array {
    $_SESSION = []; session_regenerate_id(true); $_SESSION['csrf'] = bin2hex(random_bytes(32)); return ['message' => 'Sesión cerrada.'];
}
function enroll(int $groupId, ?string $expectedPrice = null): array {
    $user = student();
    return transaction(function () use ($user, $groupId, $expectedPrice) {
        // Mismo orden de bloqueos en altas y bajas. Serializa cambios de un alumno.
        one('SELECT id_usuario FROM usuarios WHERE id_usuario=?' . lockSql(), [$user['id_usuario']]);
        $group = one('SELECT * FROM grupos WHERE id_grupo=?' . lockSql(), [$groupId]);
        if (!$group || !(int)$group['activo'] || $group['fecha_fin'] < date('Y-m-d')) throw new HttpError(409, 'Este grupo ya no está disponible.');
        $course = one('SELECT * FROM cursos WHERE id_curso=?' . lockSql(), [$group['id_curso']]);
        if (!$course || !(int)$course['activo']) throw new HttpError(409, 'Este curso ya no está disponible.');
        if ($expectedPrice !== null) {
            if (!preg_match('/^\d{1,8}(\.\d{1,2})?$/', $expectedPrice)) throw new HttpError(422, 'Precio de confirmación inválido.');
            if ((int)round((float)$expectedPrice * 100) !== (int)round((float)$course['precio'] * 100)) throw new HttpError(409, 'El precio cambió. Vuelve al curso para revisar el importe antes de confirmar.');
        }
        $old = one('SELECT * FROM inscripciones WHERE id_usuario=? AND id_curso=?', [$user['id_usuario'], $course['id_curso']]);
        if ($old && $old['estado'] === 'Activa') throw new HttpError(409, 'Ya tienes una inscripción activa en este curso.');
        $used = (int)query("SELECT COUNT(*) FROM inscripciones WHERE id_grupo=? AND estado='Activa'", [$groupId])->fetchColumn();
        if ($used >= (int)$group['cupo_maximo']) throw new HttpError(409, 'El grupo acaba de quedarse sin lugares. Elige otro grupo.');
        $cycle = $old ? (int)query("SELECT COUNT(*) FROM pagos WHERE id_inscripcion=? AND tipo='Inscripcion'", [$old['id_inscripcion']])->fetchColumn() + 1 : 1;
        $key = 'enroll:' . $user['id_usuario'] . ':' . $course['id_curso'] . ':' . $cycle;
        payment((int)$user['id_usuario'], (int)$course['id_curso'], 'Inscripcion', (string)$course['precio'], $key);
        if ($old) {
            query("UPDATE inscripciones SET id_grupo=?,estado='Activa',fecha_inscripcion=CURRENT_TIMESTAMP,fecha_baja=NULL WHERE id_inscripcion=?", [$groupId, $old['id_inscripcion']]);
        } else {
            query('INSERT INTO inscripciones(id_usuario,id_grupo,id_curso) VALUES (?,?,?)', [$user['id_usuario'], $groupId, $course['id_curso']]);
        }
        $row = one('SELECT * FROM inscripciones WHERE id_usuario=? AND id_curso=?', [$user['id_usuario'], $course['id_curso']]);
        // Captura el precio del momento; los reportes nunca recalculan importes historicos.
        query("INSERT INTO pagos(id_usuario,id_inscripcion,tipo,monto) VALUES (?,?,'Inscripcion',?)", [$user['id_usuario'], $row['id_inscripcion'], $course['precio']]);
        return ['inscripcion' => $row, 'message' => 'Tu inscripción está lista.', 'demo' => demo()];
    });
}
function withdraw(int $enrollmentId): array {
    $user = student();
    return transaction(function () use ($user, $enrollmentId) {
        one('SELECT id_usuario FROM usuarios WHERE id_usuario=?' . lockSql(), [$user['id_usuario']]);
        $row = one('SELECT * FROM inscripciones WHERE id_inscripcion=? AND id_usuario=?', [$enrollmentId, $user['id_usuario']]);
        if (!$row) throw new HttpError(404, 'Inscripción no encontrada.');
        one('SELECT id_grupo FROM grupos WHERE id_grupo=?' . lockSql(), [$row['id_grupo']]);
        if ($row['estado'] !== 'Activa') throw new HttpError(409, 'Esta inscripción ya está dada de baja.');
        $fee = integrations()['withdrawal_fee'];
        if (!is_numeric($fee) || (float)$fee < 0) throw new RuntimeException('Tarifa de baja invalida.');
        $cycle = (int)query("SELECT COUNT(*) FROM pagos WHERE id_inscripcion=? AND tipo='Inscripcion'", [$enrollmentId])->fetchColumn();
        payment((int)$user['id_usuario'], (int)$row['id_curso'], 'Baja', (string)$fee, 'withdraw:' . $enrollmentId . ':' . $cycle);
        query("UPDATE inscripciones SET estado='Baja',fecha_baja=CURRENT_TIMESTAMP WHERE id_inscripcion=?", [$enrollmentId]);
        query("INSERT INTO pagos(id_usuario,id_inscripcion,tipo,monto) VALUES (?,?,'Baja',?)", [$user['id_usuario'], $enrollmentId, $fee]);
        return ['message' => 'La baja se realizó. Tu inscripción permanece en el historial.'];
    });
}
function forgotPassword(array $data): array {
    rateLimit('forgot', 5, 900);
    $email = emailField($data); $sender = integrations()['send_reset_email'];
    if (!demo() && !$sender) throw new HttpError(503, 'La recuperación de contraseña aún no está habilitada. Contacta al equipo de la plataforma.');
    $result = ['message' => 'Si el correo corresponde a una cuenta activa, recibirás las instrucciones para cambiar tu contraseña.'];
    $user = one('SELECT id_usuario FROM usuarios WHERE email=? AND activo=1', [$email]);
    if ($user) {
        $token = bin2hex(random_bytes(32));
        query('INSERT INTO password_reset_tokens(token_hash,id_usuario,expires_at) VALUES (?,?,?)', [hash('sha256', $token), $user['id_usuario'], gmdate('Y-m-d H:i:s', time() + 1800)]);
        $url = rtrim(env('APP_URL', 'http://127.0.0.1:8765'), '/') . '/recuperar.html?token=' . $token;
        if ($sender) $sender($email, $url);
        elseif (demo()) $result['demo_url'] = $url;
    }
    return $result;
}
function resetPassword(array $data): array {
    rateLimit('reset', 10, 900);
    $token = textField($data, 'token', 64); $password = passwordField($data);
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) throw new HttpError(422, 'El enlace no es válido.');
    $hash = hash('sha256', $token);
    // Bloqueo de usuario primero: dos tokens del mismo usuario no pueden usarse a la vez.
    $candidate = one('SELECT id_usuario FROM password_reset_tokens WHERE token_hash=?', [$hash]);
    if (!$candidate) throw new HttpError(422, 'El enlace venció o ya fue utilizado. Solicita uno nuevo.');
    return transaction(function () use ($candidate, $hash, $password) {
        one('SELECT id_usuario FROM usuarios WHERE id_usuario=?' . lockSql(), [$candidate['id_usuario']]);
        $row = one('SELECT * FROM password_reset_tokens WHERE token_hash=?' . lockSql(), [$hash]);
        if (!$row || $row['used_at'] || $row['expires_at'] <= gmdate('Y-m-d H:i:s')) throw new HttpError(422, 'El enlace venció o ya fue utilizado. Solicita uno nuevo.');
        query('UPDATE usuarios SET password_hash=? WHERE id_usuario=?', [password_hash($password, PASSWORD_DEFAULT), $row['id_usuario']]);
        query('UPDATE password_reset_tokens SET used_at=CURRENT_TIMESTAMP WHERE id_usuario=? AND used_at IS NULL', [$row['id_usuario']]);
        logoutUser(); return ['message' => 'Contraseña actualizada. Ya puedes iniciar sesión.'];
    });
}
