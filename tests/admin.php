<?php
declare(strict_types=1);
// Pruebas del panel de administración sobre una base SQLite temporal. Nunca toca la base del equipo.
// Ejecutar:  php tests/admin.php
$path = __DIR__ . '/../storage/test-admin-' . bin2hex(random_bytes(8)) . '.sqlite';
touch($path);
putenv('APP_ENV=demo'); putenv('DB_DRIVER=sqlite'); putenv('SQLITE_PATH=' . $path);
$_SERVER['REMOTE_ADDR'] = 'test-' . bin2hex(random_bytes(8));
require __DIR__ . '/../src/bootstrap.php';
require ROOT . '/src/api.php';
startSession();
$results = [];
function check(bool $condition, string $label): void {
    global $results;
    if (!$condition) throw new RuntimeException('FALLO: ' . $label);
    $results[] = 'OK ' . $label;
}
function rejects(callable $fn, int $status, string $label): void {
    try { $fn(); } catch (HttpError $e) { check($e->status === $status, $label . ' (HTTP ' . $e->status . ')'); return; }
    throw new RuntimeException('FALLO: no rechazo ' . $label);
}
function signIn(int $id): void { establishSession(one('SELECT * FROM usuarios WHERE id_usuario=?', [$id])); }
try {
    db()->exec(file_get_contents(ROOT . '/database/001_sqlite.sql'));
    db()->exec(file_get_contents(ROOT . '/database/002_auth_sqlite.sql'));
    foreach ([['Admin', 'Administrador'], ['Ana', 'Alumno'], ['Luis', 'Alumno'], ['Otro', 'Administrador']] as [$name, $role]) {
        query('INSERT INTO usuarios(nombre,apellidos,email,password_hash,rol) VALUES (?,?,?,?,?)', [$name, 'Prueba', strtolower($name) . '@nexo.test', password_hash('Password2026!', PASSWORD_DEFAULT), $role]);
    }
    // Autorización: el rol se comprueba en el servidor.
    $_SESSION = []; startSession();
    rejects(fn() => adminDispatch('admin-users', []), 401, 'Sin sesión no se abre el panel');
    signIn(2); rejects(fn() => adminDispatch('admin-users', []), 403, 'Un alumno no puede usar acciones de administrador');
    rejects(fn() => adminDispatch('admin-course-save', ['nombre' => 'X']), 403, 'Un alumno no puede escribir cursos');
    signIn(1); $me = admin();
    check(count(adminUsers()) === 4, 'El administrador lista todas las cuentas');

    // Cursos
    $base = ['nombre' => 'Curso A', 'descripcion' => 'Descripción', 'duracion_horas' => 12, 'precio' => '50', 'imagen_url' => '', 'destacado' => true, 'activo' => true];
    rejects(fn() => adminCourseSave(array_merge($base, ['precio' => '-5']), $me), 422, 'Precio negativo rechazado');
    rejects(fn() => adminCourseSave(array_merge($base, ['duracion_horas' => 0]), $me), 422, 'Duración cero rechazada');
    rejects(fn() => adminCourseSave(array_merge($base, ['imagen_url' => 'javascript:alert(1)']), $me), 422, 'Imagen con esquema peligroso rechazada');
    rejects(fn() => adminCourseSave(array_merge($base, ['id_admin' => 2]), $me), 422, 'El responsable debe ser administrador');
    $course = adminCourseSave($base, $me);
    check((int)$course['id_admin'] === 1 && $course['precio'] == 50 && (int)$course['destacado'] === 1, 'Curso nuevo queda a cargo del administrador activo');
    $cid = (int)$course['id_curso'];
    $updated = adminCourseSave(array_merge($base, ['id' => $cid, 'nombre' => 'Curso A2', 'precio' => 75.5, 'id_admin' => 4]), $me);
    check($updated['nombre'] === 'Curso A2' && (float)$updated['precio'] === 75.5 && (int)$updated['id_admin'] === 4, 'Curso editado con otro responsable administrador');

    // Grupos
    $group = ['id_curso' => $cid, 'nombre' => 'Grupo A', 'id_profesor' => 1, 'cupo_maximo' => 2, 'fecha_inicio' => '2020-01-01', 'fecha_fin' => '2099-01-01', 'activo' => true];
    rejects(fn() => adminGroupSave(array_merge($group, ['fecha_fin' => '2019-01-01'])), 422, 'Fecha final anterior rechazada');
    rejects(fn() => adminGroupSave(array_merge($group, ['fecha_inicio' => '2020-02-30'])), 422, 'Fecha inexistente rechazada');
    rejects(fn() => adminGroupSave(array_merge($group, ['id_profesor' => 2])), 422, 'El profesor debe ser administrador');
    rejects(fn() => adminGroupSave(array_merge($group, ['cupo_maximo' => 0])), 422, 'Cupo cero rechazado');
    $g = adminGroupSave($group); $gid = (int)$g['id_grupo'];
    check(count(adminCourse($cid)['grupos']) === 1, 'El grupo aparece en el curso');
    signIn(2); enroll($gid); signIn(3); enroll($gid); signIn(1);
    rejects(fn() => adminGroupSave(array_merge($group, ['id' => $gid, 'cupo_maximo' => 1])), 409, 'No se reduce el cupo por debajo de los alumnos activos');
    check((int)adminGroupSave(array_merge($group, ['id' => $gid, 'cupo_maximo' => 5]))['cupo_maximo'] === 5, 'Cupo ampliado correctamente');
    $r = adminGroupDelete(['id' => $gid]);
    check($r['deleted'] === false && (int)one('SELECT activo FROM grupos WHERE id_grupo=?', [$gid])['activo'] === 0, 'Grupo con inscripciones se desactiva, no se borra');
    $r = adminCourseDelete(['id' => $cid]);
    check($r['deleted'] === false && (int)one('SELECT activo FROM cursos WHERE id_curso=?', [$cid])['activo'] === 0, 'Curso con inscripciones se desactiva, no se borra');
    $empty = adminCourseSave(array_merge($base, ['nombre' => 'Vacío']), $me); $eid = (int)$empty['id_curso'];
    $eg = adminGroupSave(array_merge($group, ['id_curso' => $eid, 'nombre' => 'G']));
    check(adminGroupDelete(['id' => $eg['id_grupo']])['deleted'] === true, 'Grupo sin inscripciones se elimina');
    adminGroupSave(array_merge($group, ['id_curso' => $eid, 'nombre' => 'G2']));
    check(adminCourseDelete(['id' => $eid])['deleted'] === true && !one('SELECT 1 FROM grupos WHERE id_curso=?', [$eid]), 'Curso sin inscripciones se elimina con sus grupos');

    // Usuarios
    rejects(fn() => adminUserSave(['nombre' => 'N', 'apellidos' => 'A', 'email' => 'nuevo@nexo.test', 'rol' => 'Alumno'], $me), 422, 'Cuenta nueva requiere contraseña');
    rejects(fn() => adminUserSave(['nombre' => 'N', 'apellidos' => 'A', 'email' => 'ana@nexo.test', 'rol' => 'Alumno', 'password' => 'Password2026!'], $me), 409, 'Correo duplicado rechazado');
    rejects(fn() => adminUserSave(['nombre' => 'N', 'apellidos' => 'A', 'email' => 'x@nexo.test', 'rol' => 'Superusuario', 'password' => 'Password2026!'], $me), 422, 'Rol inválido rechazado');
    $new = adminUserSave(['nombre' => 'Nuevo', 'apellidos' => 'Admin', 'email' => 'NUEVO@nexo.test', 'rol' => 'Administrador', 'password' => 'Password2026!', 'activo' => true], $me);
    check($new['email'] === 'nuevo@nexo.test' && $new['rol'] === 'Administrador', 'Alta de un administrador desde el panel');
    check(password_verify('Password2026!', one('SELECT password_hash FROM usuarios WHERE id_usuario=?', [$new['id_usuario']])['password_hash']), 'La contraseña se guarda con hash');
    rejects(fn() => adminUserSave(['id' => 1, 'nombre' => 'Admin', 'apellidos' => 'Prueba', 'email' => 'admin@nexo.test', 'rol' => 'Alumno', 'activo' => true], $me), 409, 'Nadie se quita su propio rol de administrador');
    rejects(fn() => adminUserSave(['id' => 4, 'nombre' => 'Otro', 'apellidos' => 'Prueba', 'email' => 'otro@nexo.test', 'rol' => 'Alumno', 'activo' => true], $me), 409, 'No se degrada a un administrador con cursos a su cargo');
    $before = one('SELECT password_hash FROM usuarios WHERE id_usuario=2')['password_hash'];
    adminUserSave(['id' => 2, 'nombre' => 'Ana', 'apellidos' => 'Editada', 'email' => 'ana@nexo.test', 'rol' => 'Alumno', 'activo' => true, 'password' => ''], $me);
    check(one('SELECT password_hash FROM usuarios WHERE id_usuario=2')['password_hash'] === $before, 'Contraseña vacía conserva la actual');
    rejects(fn() => adminUserDelete(['id' => 1], $me), 409, 'No se elimina la propia cuenta');
    check(adminUserDelete(['id' => 2], $me)['deleted'] === false && (int)one('SELECT activo FROM usuarios WHERE id_usuario=2')['activo'] === 0, 'Usuario con historial se desactiva');
    signIn(3); check(currentUser() !== null, 'Otro alumno sigue activo'); signIn(1);
    check(adminUserDelete(['id' => $new['id_usuario']], $me)['deleted'] === true, 'Usuario sin historial se elimina');

    // Reportes: los límites del mes usan [inicio, inicio del mes siguiente).
    query('DELETE FROM pagos');
    $ins = (int)one('SELECT id_inscripcion FROM inscripciones WHERE id_usuario=3')['id_inscripcion'];
    foreach ([['Inscripcion', 500, '2026-10-01 00:00:00'], ['Inscripcion', 500, '2026-10-31 23:59:59'], ['Inscripcion', 0, '2026-10-15 12:00:00'],
              ['Inscripcion', 500, '2026-11-01 00:00:00'], ['Baja', 300, '2026-10-20 10:00:00']] as [$type, $amount, $when]) {
        query('INSERT INTO pagos(id_usuario,id_inscripcion,tipo,monto,fecha_pago) VALUES (3,?,?,?,?)', [$ins, $type, $amount, $when]);
    }
    query("INSERT INTO gastos(id_admin,id_curso,concepto,monto,fecha_gasto) VALUES (1,NULL,'Hosting',4000,'2026-10-31'),(1,NULL,'Otro mes',999,'2026-11-01')");
    $_GET = ['mes' => '10', 'anio' => '2026'];
    $stats = adminStats();
    check($stats['alumnos_inscritos'] === 3 && $stats['bajas'] === 1, 'Estadísticas cuentan altas y bajas del mes, límites incluidos');
    check($stats['promedio_semanal'] === round(3 / (31 / 7), 1), 'Promedio semanal = inscripciones ÷ (días ÷ 7)');
    $earn = adminEarnings();
    check($earn['inscripciones']['total'] == 1000 && $earn['bajas']['total'] == 300 && $earn['ganancias_total'] == 1300, 'Ganancias suman inscripciones y bajas del mes');
    check($earn['gastos']['total'] == 4000 && $earn['neto'] == -2700, 'Gastos y resultado neto del mes');
    $_GET['tipo'] = 'total'; check(count(adminDetail()['filas']) === 4, 'Detalle de ganancias lista cada movimiento del mes');
    $_GET['tipo'] = 'inventado'; rejects(fn() => adminDetail(), 422, 'Tipo de detalle desconocido rechazado');
    $_GET = ['mes' => '13', 'anio' => '2026']; rejects(fn() => adminStats(), 422, 'Mes inválido rechazado');
    $_GET = ['mes' => '10', 'anio' => '2026'];
    $exp = adminExpenseSave(['concepto' => 'Licencias', 'monto' => '250.50', 'fecha_gasto' => '2026-10-10'], $me);
    check(adminEarnings()['gastos']['total'] == 4250.5, 'Gasto nuevo entra al reporte');
    rejects(fn() => adminExpenseSave(['concepto' => 'X', 'monto' => '0'], $me), 422, 'Gasto de cero rechazado');
    adminExpenseDelete(['id' => $exp['id_gasto']]);
    check(adminEarnings()['gastos']['total'] == 4000, 'Gasto eliminado sale del reporte');

    echo implode(PHP_EOL, $results) . PHP_EOL . count($results) . " comprobaciones correctas.\n";
} catch (Throwable $e) { fwrite(STDERR, (string)$e . PHP_EOL); exit(1); }
finally { Database::$connection = null; if (is_file($path)) unlink($path); }
