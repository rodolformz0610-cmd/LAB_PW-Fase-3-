<?php
declare(strict_types=1);

/* Panel de administración de Nexo (pantallas 6 a 10 del documento).
 * Todas las acciones exigen una sesión con rol Administrador. El rol se consulta
 * en la base en cada petición; nunca se acepta desde el navegador.
 * Usa los mismos helpers de bootstrap.php y api.php (query, one, transaction, textField...).
 */

function adminReadActions(): array {
    return ['admin-overview', 'admin-users', 'admin-user', 'admin-courses', 'admin-course',
        'admin-admins', 'admin-stats', 'admin-earnings', 'admin-detail'];
}
function adminWriteActions(): array {
    return ['admin-user-save', 'admin-user-delete', 'admin-course-save', 'admin-course-delete',
        'admin-group-save', 'admin-group-delete', 'admin-expense-save', 'admin-expense-delete'];
}

function admin(): array {
    $user = currentUser(true);
    if ($user['rol'] !== 'Administrador') throw new HttpError(403, 'Esta sección es exclusiva para administradores.');
    return $user;
}

function adminDispatch(string $action, array $data): mixed {
    $me = admin();
    return match ($action) {
        'admin-overview' => adminOverview(),
        'admin-users' => adminUsers(),
        'admin-user' => adminUser(positiveId($_GET['id'] ?? null)),
        'admin-courses' => adminCourses(),
        'admin-course' => adminCourse(positiveId($_GET['id'] ?? null)),
        'admin-admins' => adminAdmins(),
        'admin-stats' => adminStats(),
        'admin-earnings' => adminEarnings(),
        'admin-detail' => adminDetail(),
        'admin-user-save' => adminUserSave($data, $me),
        'admin-user-delete' => adminUserDelete($data, $me),
        'admin-course-save' => adminCourseSave($data, $me),
        'admin-course-delete' => adminCourseDelete($data),
        'admin-group-save' => adminGroupSave($data),
        'admin-group-delete' => adminGroupDelete($data),
        'admin-expense-save' => adminExpenseSave($data, $me),
        'admin-expense-delete' => adminExpenseDelete($data),
    };
}

/* ---------- Validaciones propias del panel ---------- */

function optionalId(mixed $value): ?int {
    return ($value === null || $value === '') ? null : positiveId($value);
}
function flag(array $data, string $key, int $default = 0): int {
    if (!array_key_exists($key, $data)) return $default;
    return filter_var($data[$key], FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
}
function intField(array $data, string $key, int $min, int $max): int {
    $value = filter_var($data[$key] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => $min, 'max_range' => $max]]);
    if ($value === false) throw new HttpError(422, "Revisa el campo $key.");
    return $value;
}
function moneyField(array $data, string $key, bool $allowZero = true): string {
    $value = $data[$key] ?? null;
    if (is_int($value) || is_float($value)) $value = number_format((float)$value, 2, '.', '');
    if (!is_string($value)) throw new HttpError(422, "Revisa el campo $key.");
    $value = trim($value);
    if (!preg_match('/^\d{1,8}(\.\d{1,2})?$/', $value)) throw new HttpError(422, 'Escribe un importe válido, con máximo dos decimales.');
    if (!$allowZero && (float)$value <= 0) throw new HttpError(422, 'El importe debe ser mayor que cero.');
    return number_format((float)$value, 2, '.', '');
}
function dateField(array $data, string $key): string {
    $value = is_string($data[$key] ?? null) ? trim($data[$key]) : '';
    $date = DateTime::createFromFormat('!Y-m-d', $value);
    if (!$date || $date->format('Y-m-d') !== $value) throw new HttpError(422, "La fecha del campo $key no es válida.");
    return $value;
}
function imageField(array $data): ?string {
    $value = trim(is_string($data['imagen_url'] ?? null) ? $data['imagen_url'] : '');
    if ($value === '') return null;
    if (mb_strlen($value) > 255 || !preg_match('~^(https?://[^\s<>"\']+|assets/img/[A-Za-z0-9._-]+)$~', $value)) {
        throw new HttpError(422, 'La imagen debe ser una URL http(s) o una ruta como assets/img/curso.jpg.');
    }
    return $value;
}
function insertId(string $sql, array $params, string $idColumn): int {
    $pdo = db();
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql') {
        return (int)query($sql . ' RETURNING ' . $idColumn, $params)->fetchColumn();
    }
    query($sql, $params);
    return (int)$pdo->lastInsertId();
}
function duplicateKey(PDOException $e): bool {
    return in_array((string)$e->getCode(), ['23000', '23505'], true);
}
// El documento exige comprobar que id_admin / id_profesor sean usuarios con rol Administrador.
function requireAdminUser(int $id): void {
    if (!one("SELECT id_usuario FROM usuarios WHERE id_usuario=? AND rol='Administrador' AND activo=1", [$id])) {
        throw new HttpError(422, 'El responsable o profesor elegido debe ser un administrador activo.');
    }
}
function reportPeriod(): array {
    $utc = new DateTimeZone('UTC');
    $now = new DateTimeImmutable('now', $utc);
    $month = filter_var($_GET['mes'] ?? $now->format('n'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 12]]);
    $year = filter_var($_GET['anio'] ?? $now->format('Y'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 2000, 'max_range' => 2100]]);
    if ($month === false || $year === false) throw new HttpError(422, 'Elige un mes y un año válidos.');
    $start = new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month), $utc);
    $end = $start->modify('+1 month');
    return ['mes' => $month, 'anio' => $year, 'dias' => (int)$start->format('t'),
        'from' => $start->format('Y-m-d 00:00:00'), 'to' => $end->format('Y-m-d 00:00:00'),
        'fromDate' => $start->format('Y-m-d'), 'toDate' => $end->format('Y-m-d')];
}

/* ---------- Lecturas ---------- */

function adminOverview(): array {
    return [
        'usuarios' => (int)query('SELECT COUNT(*) FROM usuarios')->fetchColumn(),
        'cursos' => (int)query('SELECT COUNT(*) FROM cursos')->fetchColumn(),
        'grupos_activos' => (int)query('SELECT COUNT(*) FROM grupos WHERE activo=1')->fetchColumn(),
    ];
}
function adminUsers(): array {
    return query("SELECT u.id_usuario,u.nombre,u.apellidos,u.email,u.rol,u.activo,u.fecha_registro,
        (SELECT COUNT(*) FROM inscripciones i WHERE i.id_usuario=u.id_usuario AND i.estado='Activa') AS inscripciones_activas
        FROM usuarios u ORDER BY u.apellidos,u.nombre,u.id_usuario")->fetchAll();
}
function adminUser(int $id): array {
    $user = one('SELECT id_usuario,nombre,apellidos,email,rol,activo,fecha_registro FROM usuarios WHERE id_usuario=?', [$id]);
    if (!$user) throw new HttpError(404, 'Usuario no encontrado.');
    return $user;
}
function adminAdmins(): array {
    return query("SELECT id_usuario,nombre,apellidos FROM usuarios WHERE rol='Administrador' AND activo=1 ORDER BY nombre,apellidos,id_usuario")->fetchAll();
}
function adminCourses(): array {
    return query("SELECT c.id_curso,c.nombre,c.duracion_horas,c.precio,c.imagen_url,c.destacado,c.activo,
        (SELECT COUNT(*) FROM grupos g WHERE g.id_curso=c.id_curso) AS grupos,
        (SELECT COUNT(*) FROM inscripciones i WHERE i.id_curso=c.id_curso AND i.estado='Activa') AS inscritos
        FROM cursos c ORDER BY c.id_curso")->fetchAll();
}
function adminCourse(int $id): array {
    $course = one('SELECT * FROM cursos WHERE id_curso=?', [$id]);
    if (!$course) throw new HttpError(404, 'Curso no encontrado.');
    $course['grupos'] = query("SELECT g.*,u.nombre AS profesor_nombre,u.apellidos AS profesor_apellidos,
        (SELECT COUNT(*) FROM inscripciones i WHERE i.id_grupo=g.id_grupo AND i.estado='Activa') AS inscritos,
        (SELECT COUNT(*) FROM inscripciones i WHERE i.id_grupo=g.id_grupo) AS inscripciones_total
        FROM grupos g JOIN usuarios u ON u.id_usuario=g.id_profesor
        WHERE g.id_curso=? ORDER BY g.fecha_inicio,g.id_grupo", [$id])->fetchAll();
    return $course;
}

/* Estadísticas: cada alta aceptada deja un pago tipo 'Inscripcion' (incluso de $0),
 * y es el único registro que conserva todas las altas, también las reinscripciones. */
function adminStats(): array {
    $p = reportPeriod(); $range = [$p['from'], $p['to']];
    $enrolled = (int)query("SELECT COUNT(*) FROM pagos WHERE tipo='Inscripcion' AND fecha_pago>=? AND fecha_pago<?", $range)->fetchColumn();
    $withdrawn = (int)query("SELECT COUNT(*) FROM pagos WHERE tipo='Baja' AND fecha_pago>=? AND fecha_pago<?", $range)->fetchColumn();
    $added = (int)query('SELECT COUNT(*) FROM cursos WHERE fecha_creacion>=? AND fecha_creacion<?', $range)->fetchColumn();
    $byCourse = query("SELECT c.id_curso,c.nombre,COUNT(*) AS inscripciones
        FROM pagos p JOIN inscripciones i ON i.id_inscripcion=p.id_inscripcion JOIN cursos c ON c.id_curso=i.id_curso
        WHERE p.tipo='Inscripcion' AND p.fecha_pago>=? AND p.fecha_pago<?
        GROUP BY c.id_curso,c.nombre ORDER BY inscripciones DESC,c.nombre", $range)->fetchAll();
    return ['periodo' => ['mes' => $p['mes'], 'anio' => $p['anio']],
        'alumnos_inscritos' => $enrolled, 'bajas' => $withdrawn, 'cursos_anadidos' => $added,
        'promedio_semanal' => round($enrolled / ($p['dias'] / 7), 1), 'por_curso' => $byCourse];
}

function adminEarnings(): array {
    $p = reportPeriod(); $range = [$p['from'], $p['to']];
    $sum = function (string $type) use ($range): array {
        $row = one("SELECT COUNT(*) AS cantidad,COALESCE(SUM(monto),0) AS total FROM pagos WHERE tipo=? AND fecha_pago>=? AND fecha_pago<?", array_merge([$type], $range));
        return ['cantidad' => (int)$row['cantidad'], 'total' => round((float)$row['total'], 2)];
    };
    $in = $sum('Inscripcion'); $out = $sum('Baja');
    $exp = one('SELECT COUNT(*) AS cantidad,COALESCE(SUM(monto),0) AS total FROM gastos WHERE fecha_gasto>=? AND fecha_gasto<?', [$p['fromDate'], $p['toDate']]);
    $gross = round($in['total'] + $out['total'], 2); $spent = round((float)$exp['total'], 2);
    return ['periodo' => ['mes' => $p['mes'], 'anio' => $p['anio']], 'inscripciones' => $in, 'bajas' => $out,
        'ganancias_total' => $gross, 'gastos' => ['cantidad' => (int)$exp['cantidad'], 'total' => $spent], 'neto' => round($gross - $spent, 2)];
}

function adminDetail(): array {
    $p = reportPeriod(); $type = $_GET['tipo'] ?? '';
    if ($type === 'gastos') {
        $rows = query('SELECT g.id_gasto,g.fecha_gasto,g.concepto,g.monto,c.nombre AS curso
            FROM gastos g LEFT JOIN cursos c ON c.id_curso=g.id_curso
            WHERE g.fecha_gasto>=? AND g.fecha_gasto<? ORDER BY g.fecha_gasto DESC,g.id_gasto DESC LIMIT 500', [$p['fromDate'], $p['toDate']])->fetchAll();
        return ['tipo' => 'gastos', 'filas' => $rows];
    }
    $types = ['inscripciones' => ['Inscripcion'], 'bajas' => ['Baja'], 'total' => ['Inscripcion', 'Baja']];
    if (!is_string($type) || !isset($types[$type])) throw new HttpError(422, 'Tipo de detalle no válido.');
    $marks = implode(',', array_fill(0, count($types[$type]), '?'));
    $rows = query("SELECT p.id_pago,p.tipo,p.fecha_pago,p.monto,u.nombre,u.apellidos,c.nombre AS curso
        FROM pagos p JOIN usuarios u ON u.id_usuario=p.id_usuario
        JOIN inscripciones i ON i.id_inscripcion=p.id_inscripcion JOIN cursos c ON c.id_curso=i.id_curso
        WHERE p.tipo IN ($marks) AND p.fecha_pago>=? AND p.fecha_pago<?
        ORDER BY p.fecha_pago DESC,p.id_pago DESC LIMIT 500", array_merge($types[$type], [$p['from'], $p['to']]))->fetchAll();
    return ['tipo' => $type, 'filas' => $rows];
}

/* ---------- Usuarios ---------- */

function userReferences(int $id): int {
    return (int)query('SELECT (SELECT COUNT(*) FROM inscripciones WHERE id_usuario=?)
        + (SELECT COUNT(*) FROM pagos WHERE id_usuario=?)
        + (SELECT COUNT(*) FROM cursos WHERE id_admin=?)
        + (SELECT COUNT(*) FROM grupos WHERE id_profesor=?)
        + (SELECT COUNT(*) FROM gastos WHERE id_admin=?)', [$id, $id, $id, $id, $id])->fetchColumn();
}
function adminResponsibilities(int $id): int {
    return (int)query('SELECT (SELECT COUNT(*) FROM cursos WHERE id_admin=?)
        + (SELECT COUNT(*) FROM grupos WHERE id_profesor=?)
        + (SELECT COUNT(*) FROM gastos WHERE id_admin=?)', [$id, $id, $id])->fetchColumn();
}

function adminUserSave(array $data, array $me): array {
    $id = optionalId($data['id'] ?? null);
    $name = textField($data, 'nombre', 60); $last = textField($data, 'apellidos', 80); $email = emailField($data);
    $role = $data['rol'] ?? '';
    if (!in_array($role, ['Alumno', 'Administrador'], true)) throw new HttpError(422, 'Elige un rol válido.');
    $active = flag($data, 'activo', 1);
    $password = (($data['password'] ?? '') === '') ? null : passwordField($data);
    if ($id === null && $password === null) throw new HttpError(422, 'Escribe una contraseña inicial para la cuenta nueva.');
    try {
        return transaction(function () use ($id, $name, $last, $email, $role, $active, $password, $me) {
            if ($id === null) {
                $newId = insertId('INSERT INTO usuarios(nombre,apellidos,email,password_hash,rol,activo) VALUES (?,?,?,?,?,?)',
                    [$name, $last, $email, password_hash($password, PASSWORD_DEFAULT), $role, $active], 'id_usuario');
                return adminUser($newId);
            }
            $row = one('SELECT * FROM usuarios WHERE id_usuario=?' . lockSql(), [$id]);
            if (!$row) throw new HttpError(404, 'Usuario no encontrado.');
            if ($id === (int)$me['id_usuario'] && ($role !== 'Administrador' || !$active)) {
                throw new HttpError(409, 'No puedes quitarte tu propio acceso de administrador ni desactivar tu cuenta.');
            }
            if ($row['rol'] === 'Administrador' && $role !== 'Administrador' && adminResponsibilities($id) > 0) {
                throw new HttpError(409, 'Esta cuenta es responsable de cursos, grupos o gastos. Reasígnalos antes de cambiar su rol.');
            }
            $sql = 'UPDATE usuarios SET nombre=?,apellidos=?,email=?,rol=?,activo=?';
            $params = [$name, $last, $email, $role, $active];
            if ($password !== null) { $sql .= ',password_hash=?'; $params[] = password_hash($password, PASSWORD_DEFAULT); }
            query($sql . ' WHERE id_usuario=?', array_merge($params, [$id]));
            return adminUser($id);
        });
    } catch (PDOException $e) {
        if (duplicateKey($e)) throw new HttpError(409, 'Ya existe otra cuenta con ese correo.');
        throw $e;
    }
}

function adminUserDelete(array $data, array $me): array {
    $id = positiveId($data['id'] ?? null);
    if ($id === (int)$me['id_usuario']) throw new HttpError(409, 'No puedes eliminar tu propia cuenta.');
    return transaction(function () use ($id) {
        if (!one('SELECT id_usuario FROM usuarios WHERE id_usuario=?' . lockSql(), [$id])) throw new HttpError(404, 'Usuario no encontrado.');
        // Con historial (inscripciones, pagos, cursos...) se desactiva para no romper los reportes.
        if (userReferences($id) > 0) {
            query('UPDATE usuarios SET activo=0 WHERE id_usuario=?', [$id]);
            return ['deleted' => false, 'message' => 'La cuenta tiene historial, así que se desactivó en lugar de borrarse.'];
        }
        query('DELETE FROM usuarios WHERE id_usuario=?', [$id]);
        return ['deleted' => true, 'message' => 'Usuario eliminado.'];
    });
}

/* ---------- Cursos y grupos ---------- */

function adminCourseSave(array $data, array $me): array {
    $id = optionalId($data['id'] ?? null);
    $name = textField($data, 'nombre', 100); $description = textField($data, 'descripcion', 4000);
    $hours = intField($data, 'duracion_horas', 1, 10000); $price = moneyField($data, 'precio');
    $image = imageField($data); $featured = flag($data, 'destacado'); $active = flag($data, 'activo', 1);
    $owner = optionalId($data['id_admin'] ?? null) ?? (int)$me['id_usuario'];
    return transaction(function () use ($id, $name, $description, $hours, $price, $image, $featured, $active, $owner) {
        requireAdminUser($owner);
        if ($id === null) {
            $newId = insertId('INSERT INTO cursos(id_admin,nombre,descripcion,duracion_horas,precio,imagen_url,destacado,activo) VALUES (?,?,?,?,?,?,?,?)',
                [$owner, $name, $description, $hours, $price, $image, $featured, $active], 'id_curso');
            return adminCourse($newId);
        }
        if (!one('SELECT id_curso FROM cursos WHERE id_curso=?' . lockSql(), [$id])) throw new HttpError(404, 'Curso no encontrado.');
        query('UPDATE cursos SET id_admin=?,nombre=?,descripcion=?,duracion_horas=?,precio=?,imagen_url=?,destacado=?,activo=? WHERE id_curso=?',
            [$owner, $name, $description, $hours, $price, $image, $featured, $active, $id]);
        return adminCourse($id);
    });
}

function adminCourseDelete(array $data): array {
    $id = positiveId($data['id'] ?? null);
    return transaction(function () use ($id) {
        if (!one('SELECT id_curso FROM cursos WHERE id_curso=?' . lockSql(), [$id])) throw new HttpError(404, 'Curso no encontrado.');
        $used = (int)query('SELECT COUNT(*) FROM inscripciones WHERE id_curso=?', [$id])->fetchColumn();
        if ($used > 0) {
            query('UPDATE cursos SET activo=0 WHERE id_curso=?', [$id]);
            return ['deleted' => false, 'message' => 'El curso tiene inscripciones en su historial, así que se desactivó en lugar de borrarse.'];
        }
        query('DELETE FROM cursos WHERE id_curso=?', [$id]); // sus grupos se eliminan en cascada; los gastos quedan sin curso
        return ['deleted' => true, 'message' => 'Curso eliminado.'];
    });
}

function adminGroupSave(array $data): array {
    $id = optionalId($data['id'] ?? null); $courseId = positiveId($data['id_curso'] ?? null);
    $name = textField($data, 'nombre', 50); $teacher = positiveId($data['id_profesor'] ?? null);
    $capacity = intField($data, 'cupo_maximo', 1, 100000);
    $start = dateField($data, 'fecha_inicio'); $end = dateField($data, 'fecha_fin');
    if ($end < $start) throw new HttpError(422, 'La fecha final no puede ser anterior a la inicial.');
    $active = flag($data, 'activo', 1);
    return transaction(function () use ($id, $courseId, $name, $teacher, $capacity, $start, $end, $active) {
        if (!one('SELECT id_curso FROM cursos WHERE id_curso=?', [$courseId])) throw new HttpError(404, 'Curso no encontrado.');
        requireAdminUser($teacher);
        if ($id === null) {
            $newId = insertId('INSERT INTO grupos(id_curso,id_profesor,nombre,cupo_maximo,fecha_inicio,fecha_fin,activo) VALUES (?,?,?,?,?,?,?)',
                [$courseId, $teacher, $name, $capacity, $start, $end, $active], 'id_grupo');
            return one('SELECT * FROM grupos WHERE id_grupo=?', [$newId]);
        }
        // Mismo orden de bloqueo que las inscripciones: primero el grupo.
        if (!one('SELECT id_grupo FROM grupos WHERE id_grupo=? AND id_curso=?' . lockSql(), [$id, $courseId])) throw new HttpError(404, 'Grupo no encontrado.');
        $used = (int)query("SELECT COUNT(*) FROM inscripciones WHERE id_grupo=? AND estado='Activa'", [$id])->fetchColumn();
        if ($capacity < $used) throw new HttpError(409, "El grupo ya tiene $used alumnos inscritos; el cupo no puede ser menor.");
        query('UPDATE grupos SET id_profesor=?,nombre=?,cupo_maximo=?,fecha_inicio=?,fecha_fin=?,activo=? WHERE id_grupo=?',
            [$teacher, $name, $capacity, $start, $end, $active, $id]);
        return one('SELECT * FROM grupos WHERE id_grupo=?', [$id]);
    });
}

function adminGroupDelete(array $data): array {
    $id = positiveId($data['id'] ?? null);
    return transaction(function () use ($id) {
        if (!one('SELECT id_grupo FROM grupos WHERE id_grupo=?' . lockSql(), [$id])) throw new HttpError(404, 'Grupo no encontrado.');
        $used = (int)query('SELECT COUNT(*) FROM inscripciones WHERE id_grupo=?', [$id])->fetchColumn();
        if ($used > 0) {
            query('UPDATE grupos SET activo=0 WHERE id_grupo=?', [$id]);
            return ['deleted' => false, 'message' => 'El grupo tiene inscripciones en su historial, así que se desactivó en lugar de borrarse.'];
        }
        query('DELETE FROM grupos WHERE id_grupo=?', [$id]);
        return ['deleted' => true, 'message' => 'Grupo eliminado.'];
    });
}

/* ---------- Gastos (alimentan el reporte de ganancias) ---------- */

function adminExpenseSave(array $data, array $me): array {
    $courseId = optionalId($data['id_curso'] ?? null);
    $concept = textField($data, 'concepto', 150); $amount = moneyField($data, 'monto', false);
    $date = (($data['fecha_gasto'] ?? '') === '') ? gmdate('Y-m-d') : dateField($data, 'fecha_gasto');
    if ($courseId !== null && !one('SELECT id_curso FROM cursos WHERE id_curso=?', [$courseId])) throw new HttpError(404, 'Curso no encontrado.');
    $newId = insertId('INSERT INTO gastos(id_admin,id_curso,concepto,monto,fecha_gasto) VALUES (?,?,?,?,?)',
        [(int)$me['id_usuario'], $courseId, $concept, $amount, $date], 'id_gasto');
    return one('SELECT * FROM gastos WHERE id_gasto=?', [$newId]);
}
function adminExpenseDelete(array $data): array {
    $id = positiveId($data['id'] ?? null);
    if (!query('DELETE FROM gastos WHERE id_gasto=?', [$id])->rowCount()) throw new HttpError(404, 'Gasto no encontrado.');
    return ['message' => 'Gasto eliminado.'];
}
