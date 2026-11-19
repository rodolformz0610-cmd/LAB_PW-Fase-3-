# Panel de administración de Nexo

Cubre las pantallas 6 a 10 de la Fase 1 y usa las seis tablas de la Fase 2 sin modificarlas. Se integra en el mismo proyecto (HTML5, CSS3, JavaScript nativo, PHP con PDO), comparte la sesión PHP y la API con el módulo de alumnos y no cambia sus archivos, salvo `config.js` (ruta `admin`), `src/api.php` y `public/api/index.php` (alta de las acciones nuevas).

## Cómo probarlo

1. Con la demo: `INICIAR-DEMO.cmd`, o `php scripts/setup-demo.php` y `php -S 127.0.0.1:8765 -t public router.php`.
2. Si ya tenías `storage/demo.sqlite`, **bórralo** para que se cree el administrador de muestra, o ejecuta `php scripts/crear-admin.php admin@nexo.test "Admin" "Nexo"`.
3. Entra en Login con **admin@nexo.test / NexoAdmin2026!**. El login redirige al panel (`admin.html`).
4. Con MySQL o Supabase crea tu administrador con `php scripts/crear-admin.php correo "Nombre" "Apellidos"`; la contraseña se pide por teclado y no se guarda en archivos.

## Páginas (en `public/`)

| Archivo | Pantalla del documento | Función |
| --- | --- | --- |
| admin.html | 6. Menú Administrador | Accesos a usuarios, cursos y reportes |
| admin-usuarios.html | 7. Editar Usuarios | Buscar, agregar, editar, desactivar o eliminar cuentas |
| admin-cursos.html | 8. Editar Cursos | Lista de cursos con grupos, alumnos activos y estado |
| admin-curso.html | 8. Agregar / editar un curso | Datos del curso y gestión de sus grupos |
| admin-estadisticas.html | 9. Reporte de Estadísticas | Alumnos inscritos, cursos añadidos y promedio semanal por mes |
| admin-ganancias.html | 10. Reporte de Ganancias | Ingresos, bajas, gastos, resultado y "Ver detalle"; registro de gastos |

`assets/js/admin.js` y `assets/css/admin.css` son propios del panel; reutilizan `styles.css`.

## API (`public/api/index.php?action=...`)

Misma convención que el módulo de alumnos: lecturas GET, escrituras POST con JSON y cabecera `X-CSRF-Token`. **Todas** exigen rol Administrador, comprobado en el servidor en cada petición (401 sin sesión, 403 si no es administrador).

| Método | action | Entrada |
| --- | --- | --- |
| GET | admin-overview, admin-users, admin-courses, admin-admins | — |
| GET | admin-user, admin-course | `id` |
| GET | admin-stats, admin-earnings | `mes`, `anio` |
| GET | admin-detail | `tipo` (`inscripciones`, `bajas`, `total`, `gastos`), `mes`, `anio` |
| POST | admin-user-save | `id?`, `nombre`, `apellidos`, `email`, `rol`, `activo`, `password?` |
| POST | admin-user-delete | `id` |
| POST | admin-course-save | `id?`, `nombre`, `descripcion`, `duracion_horas`, `precio`, `id_admin`, `imagen_url`, `destacado`, `activo` |
| POST | admin-course-delete | `id` |
| POST | admin-group-save | `id?`, `id_curso`, `nombre`, `id_profesor`, `cupo_maximo`, `fecha_inicio`, `fecha_fin`, `activo` |
| POST | admin-group-delete | `id` |
| POST | admin-expense-save | `concepto`, `monto`, `fecha_gasto?`, `id_curso?` |
| POST | admin-expense-delete | `id` |

## Reglas y decisiones

- **Eliminar conserva el historial.** Un usuario, curso o grupo con inscripciones, pagos o gastos asociados no se borra: se desactiva (`activo=0`) y el panel lo avisa. Sin historial se borra de verdad. Así los reportes y las llaves foráneas no se rompen. Una cuenta desactivada pierde el acceso al instante.
- **Roles.** `id_admin` de cursos, `id_profesor` de grupos y `id_admin` de gastos solo aceptan administradores activos, como pide la sección 3.3 del documento. Un administrador no puede quitarse su propio rol, desactivarse ni eliminarse, y no se degrada a quien sea responsable de cursos, grupos o gastos hasta reasignarlos.
- **Cupo.** No se puede bajar el cupo de un grupo por debajo de sus alumnos activos. El grupo se bloquea antes que el curso, igual que en inscripciones y bajas, para evitar bloqueos cruzados.
- **Estadísticas.** "Alumnos inscritos" cuenta los pagos tipo `Inscripcion` del mes (incluye cursos gratuitos y reinscripciones: es el único registro que conserva todas las altas). "Cursos añadidos" usa `cursos.fecha_creacion`. "Promedio semanal" = inscripciones ÷ (días del mes ÷ 7).
- **Ganancias.** Suma `pagos` de tipo `Inscripcion` y `Baja` por `fecha_pago`; los gastos salen de `gastos.fecha_gasto`. Los importes son los guardados en cada movimiento, no el precio actual del curso. El mes se corta en horario **UTC**.
- **Gastos.** La tabla `gastos` no tenía ninguna pantalla que la llenara, por eso la página de ganancias incluye "Registrar gasto" y eliminar desde "Ver detalle".

## Diferencias justificadas respecto a los bosquejos

Igual que en el módulo de alumnos, no se inventaron columnas que la Fase 2 no tiene:

- Usuarios: edad, género y domicilio no existen en `usuarios`; el formulario edita nombre, apellidos, email, rol, estado y contraseña. La contraseña existente nunca se muestra; se escribe una nueva solo para cambiarla.
- Cursos: categoría, público, instructor, módulos, adjuntar contenido y certificado de prueba no existen en `cursos`. El "Instructor" se resuelve con el **Responsable** (`cursos.id_admin`) y con el **profesor de cada grupo** (`grupos.id_profesor`). Si el equipo agrega esas columnas, solo hay que ampliar `admin-course-save` y el formulario.
- "Ganancias de inscripciones" muestra el número de inscripciones y el total, no "86 × $500", porque cada curso tiene su precio.
- Agregar curso y editar curso comparten `admin-curso.html`; los grupos se agregan tras guardar el curso.

## Pruebas

```sh
php tests/admin.php
```

Usa una base SQLite temporal y comprueba autorización, validaciones, reglas de cupo, desactivar frente a eliminar y los cálculos de los reportes (incluidos los límites del mes). Ejecútalas junto con `php tests/run.php` antes de integrar. Las consultas se escribieron compatibles con SQLite, MySQL y PostgreSQL; se verificaron con SQLite.
