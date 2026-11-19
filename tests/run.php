<?php
declare(strict_types=1);
// Pruebas sobre una base temporal. Nunca toca la base configurada del equipo.
$path = __DIR__ . '/../storage/test-' . bin2hex(random_bytes(8)) . '.sqlite';
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
    foreach ([['Admin','Administrador'],['Ana','Alumno'],['Luis','Alumno']] as $i => [$name,$role]) {
        query('INSERT INTO usuarios(nombre,apellidos,email,password_hash,rol) VALUES (?,?,?,?,?)', [$name,'Prueba',strtolower($name).'@nexo.test',password_hash('Password2026!',PASSWORD_DEFAULT),$role]);
    }
    query("INSERT INTO cursos(id_admin,nombre,descripcion,duracion_horas,precio) VALUES (1,'Curso A','Descripción',12,50),(1,'Curso B','Descripción',10,0)");
    query("INSERT INTO grupos(id_curso,id_profesor,nombre,cupo_maximo,fecha_inicio,fecha_fin) VALUES (1,1,'A',1,'2020-01-01','2099-01-01'),(1,1,'B',5,'2020-01-01','2099-01-01'),(2,1,'C',5,'2020-01-01','2099-01-01')");
    rejects(fn() => myCourses(),401,'Sin sesion no se consultan inscripciones');
    check(count(courseDetail(1)['grupos']) === 2,'Catalogo publico y grupos disponibles');
    signIn(1); rejects(fn() => enroll(1),403,'Administrador no puede inscribirse');
    signIn(2); rejects(fn() => enroll(1,'5.00'),409,'Precio modificado requiere una nueva confirmacion');
    $first = enroll(1,'50.00')['inscripcion'];
    check($first['estado'] === 'Activa','Alta crea una inscripcion activa');
    check((float)query('SELECT monto FROM pagos WHERE id_inscripcion=?',[$first['id_inscripcion']])->fetchColumn() === 50.0,'Pago conserva el precio del momento');
    rejects(fn() => enroll(2),409,'Un alumno no puede ocupar dos grupos del mismo curso');
    check((int)query('SELECT COUNT(*) FROM pagos')->fetchColumn() === 1,'Intento duplicado no crea otro pago');
    signIn(3); rejects(fn() => enroll(1),409,'Cupo lleno rechaza alta');
    rejects(fn() => withdraw((int)$first['id_inscripcion']),404,'Alumno no puede dar de baja inscripcion ajena');
    rejects(fn() => classroom(1),403,'Aula bloqueada para no inscritos');
    signIn(2); check(count(classroom(1)['modules']) === 3,'Inscrito obtiene material protegido');
    withdraw((int)$first['id_inscripcion']);
    $old = one('SELECT * FROM inscripciones WHERE id_inscripcion=?',[$first['id_inscripcion']]);
    check($old['estado'] === 'Baja' && $old['fecha_baja'] !== null,'Baja conserva registro y fecha');
    rejects(fn() => withdraw((int)$first['id_inscripcion']),409,'Baja repetida no duplica movimientos');
    rejects(fn() => classroom(1),403,'Baja revoca el acceso al aula');
    signIn(3); enroll(1); check(count(myCourses()) === 1,'Baja libera cupo para otro alumno');
    signIn(2); $again = enroll(2)['inscripcion'];
    check($again['id_inscripcion'] === $first['id_inscripcion'] && $again['fecha_baja'] === null,'Reinscripcion reactiva la misma fila y permite otro grupo');
    query('UPDATE cursos SET precio=999 WHERE id_curso=1');
    check((float)query('SELECT monto FROM pagos WHERE id_pago=1')->fetchColumn() === 50.0,'Cambiar precio no altera reportes historicos');
    query('UPDATE grupos SET activo=0 WHERE id_grupo=3');
    rejects(fn() => enroll(3),409,'Grupo inactivo no acepta alumnos');
    check((int)query('SELECT COUNT(*) FROM inscripciones')->fetchColumn() === 2,'Error de alta no deja filas parciales');
    try { query("INSERT INTO inscripciones(id_usuario,id_grupo,id_curso) VALUES (2,3,1)"); throw new RuntimeException('FK no comprobada'); }
    catch (PDOException) { check(true,'Llave compuesta impide mezclar curso y grupo'); }
    logoutUser();
    $registered = registerUser(['nombre'=>'Eva','apellidos'=>'Prueba','email'=>'eva@nexo.test','password'=>'Password2026!','rol'=>'Administrador']);
    check($registered['rol'] === 'Alumno','Registro ignora escalamiento de rol');
    check(password_verify('Password2026!', one('SELECT password_hash FROM usuarios WHERE email=?',['eva@nexo.test'])['password_hash']),'Contraseñas se almacenan con hash');
    rejects(fn() => registerUser(['nombre'=>'Eva','apellidos'=>'Prueba','email'=>'EVA@nexo.test','password'=>'Password2026!']),409,'Correo duplicado rechazado sin distinguir mayusculas');
    rejects(fn() => loginUser(['email'=>'eva@nexo.test','password'=>'incorrecta']),401,'Contraseña incorrecta rechazada');
    $recovery = forgotPassword(['email'=>'eva@nexo.test']);
    parse_str(parse_url($recovery['demo_url'],PHP_URL_QUERY),$resetQuery);
    resetPassword(['token'=>$resetQuery['token'],'password'=>'NuevaClave2026!']);
    rejects(fn() => resetPassword(['token'=>$resetQuery['token'],'password'=>'OtraClave2026!']),422,'Token de recuperacion solo se usa una vez');
    rejects(fn() => loginUser(['email'=>'eva@nexo.test','password'=>'Password2026!']),401,'La contraseña anterior deja de funcionar');
    check(loginUser(['email'=>'eva@nexo.test','password'=>'NuevaClave2026!'])['nombre'] === 'Eva','Nueva contraseña permite acceso');
    $unknown = forgotPassword(['email'=>'nadie@nexo.test']);
    check($unknown['message'] === $recovery['message'],'Recuperacion no revela cuentas mediante el mensaje');
    $userId = (int)$registered['id_usuario'];
    query('UPDATE usuarios SET password_hash=? WHERE id_usuario=?',[password_hash('CambioExterno2026!',PASSWORD_DEFAULT),$userId]);
    check(currentUser() === null,'Cambiar contraseña invalida una sesion anterior');
    signIn(2); query('UPDATE usuarios SET activo=0 WHERE id_usuario=2');
    rejects(fn() => myCourses(),401,'Cuenta desactivada pierde acceso');
    echo implode(PHP_EOL,$results) . PHP_EOL . count($results) . " comprobaciones correctas.\n";
} catch (Throwable $e) { fwrite(STDERR,(string)$e . PHP_EOL); exit(1); }
finally { Database::$connection = null; if (is_file($path)) unlink($path); }
