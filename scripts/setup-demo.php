<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';
if (!demo() || env('DB_DRIVER', 'sqlite') !== 'sqlite') { fwrite(STDERR,"La demo requiere APP_ENV=demo y DB_DRIVER=sqlite.\n"); exit(1); }
$path = env('SQLITE_PATH', ROOT . '/storage/demo.sqlite');
if (is_file($path)) exit("La demo ya existe. Se conservan tus datos.\n");
$pdo = new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
try {
    $pdo->exec(file_get_contents(ROOT . '/database/001_sqlite.sql'));
    $pdo->exec(file_get_contents(ROOT . '/database/002_auth_sqlite.sql'));
    $pdo->beginTransaction();
    $stmt = $pdo->prepare('INSERT INTO usuarios(nombre,apellidos,email,password_hash,rol) VALUES (?,?,?,?,?)');
    $stmt->execute(['Mariana','Torres','profesora@nexo.test',password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT),'Administrador']);
    $stmt->execute(['Alex','Martínez','alumno@nexo.test',password_hash('NexoAlumno2026!', PASSWORD_DEFAULT),'Alumno']);
    $stmt->execute(['Admin','Nexo','admin@nexo.test',password_hash('NexoAdmin2026!', PASSWORD_DEFAULT),'Administrador']);
    $courses = [
        ['Liderazgo en proyectos creativos','Convierte una idea en un proyecto. Aprende a coordinar un equipo, definir objetivos y comunicar una propuesta con claridad.',24,450,'liderazgo.jpg',1],
        ['Programación básica con Python','Da tus primeros pasos en programación: variables, decisiones, ciclos y funciones mediante ejercicios prácticos.',32,0,'python.jpg',1],
        ['Finanzas Personales','Organiza tus ingresos y gastos, construye un presupuesto y aprende a plantear metas de ahorro alcanzables.',16,350,'finanzas.jpg',0],
        ['Comunicación efectiva','Estructura tus ideas y fortalece tu comunicación oral. Practica la escucha activa y prepara presentaciones con propósito.',20,400,'comunicacion.jpg',1],
    ];
    $insert = $pdo->prepare('INSERT INTO cursos(id_admin,nombre,descripcion,duracion_horas,precio,imagen_url,destacado) VALUES (1,?,?,?,?,?,?)');
    $group = $pdo->prepare('INSERT INTO grupos(id_curso,id_profesor,nombre,cupo_maximo,fecha_inicio,fecha_fin) VALUES (?,1,?,?,?,?)');
    foreach ($courses as $i => $course) {
        $course[4] = 'assets/img/' . $course[4]; $insert->execute($course);
        $group->execute([$i+1,'Grupo A · En línea',20,gmdate('Y-m-d', strtotime('+7 days')),gmdate('Y-m-d', strtotime('+90 days'))]);
        $group->execute([$i+1,'Grupo B · En línea',15,gmdate('Y-m-d', strtotime('+14 days')),gmdate('Y-m-d', strtotime('+120 days'))]);
    }
    $pdo->exec("INSERT INTO inscripciones(id_usuario,id_grupo,id_curso) VALUES (2,1,1)");
    $pdo->exec("INSERT INTO pagos(id_usuario,id_inscripcion,tipo,monto) VALUES (2,1,'Inscripcion',450)");
    // Gastos de muestra del mes actual para el reporte de ganancias del panel de administración.
    $expense = $pdo->prepare('INSERT INTO gastos(id_admin,id_curso,concepto,monto,fecha_gasto) VALUES (3,?,?,?,?)');
    $expense->execute([2,'Mantenimiento de la plataforma de Python',1200,gmdate('Y-m-d')]);
    $expense->execute([null,'Licencias de video',800,gmdate('Y-m-d')]);
    $pdo->commit();
    echo "Demo preparada.\nAlumno: alumno@nexo.test | NexoAlumno2026!\nAdministrador: admin@nexo.test | NexoAdmin2026!\n";
} catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
