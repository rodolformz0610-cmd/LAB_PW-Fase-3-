# Integración del módulo de alumnos

## Archivos y responsabilidades

`public/` es la única carpeta pública del servidor. `.env`, `config/`, `src/`, `database/`, `storage/` y `tests/` deben quedar fuera del DocumentRoot. En Apache configura un VirtualHost con DocumentRoot apuntando a `public/`; el `.htaccess` incluido desactiva índices. Para desarrollo usa el router de la raíz, como indica README.

El frontend solo consume la API JSON. La API aplica autorización, validación, cupos y reglas de negocio; ocultar un botón no es el control de seguridad. Las consultas usan parámetros PDO. Los campos de la base se escapan al mostrarlos en HTML. Las contraseñas usan `password_hash` y `password_verify`; las sesiones usan cookies HttpOnly y SameSite y regeneran el identificador al iniciar sesión. [PDO y transacciones](https://www.php.net/manual/en/pdo.transactions.php), [contraseñas PHP](https://www.php.net/manual/en/function.password-hash.php), [sesiones PHP](https://www.php.net/manual/en/session.security.ini.php).

## Rutas compartidas

Editar `public/assets/js/config.js`:

```js
routes: {
  home: '/inicio.php',
  courses: '/alumnos/cursos.html',
  about: '/nosotros.php',
  learning: '/como-aprenderas.php',
  login: '/login.php',
  register: '/registro.php',
  recovery: '/recuperar.php',
  myCourses: '/alumnos/mis-cursos.html',
  admin: '/administrador/index.php'
}
```

Son ejemplos de unión, no rutas reales conocidas del equipo. Configura los nombres que acuerden. Conserva API y frontend en el mismo origen para compartir cookies. Si se coloca el módulo en una subcarpeta, `apiBase` puede ser `api/index.php` dentro de esa misma carpeta.

## Sesión compartida

El login común debe usar el mismo `SESSION_NAME` y guardar el ID de `usuarios` en la clave indicada por `session_user_key`, por defecto:

```php
// Tras verificar password_verify y que el usuario este activo:
session_name('nexo_session');
session_start();
session_regenerate_id(true);
$_SESSION['id_usuario'] = (int) $usuario['id_usuario'];
// Opcional, recomendado: permite revocar esta sesion cuando cambia la contraseña.
$_SESSION['auth_fingerprint'] = hash('sha256', $usuario['password_hash']);
header('Location: /alumnos/mis-cursos.html');
exit;
```

Configuren las mismas opciones de cookie antes de iniciar la sesión: HTTPS, HttpOnly, SameSite=Lax y ruta `/`. La API vuelve a consultar rol y activo en cada petición; no confía en un rol enviado por JavaScript. El login compartido debe respetar el parámetro `next` usando una lista de destinos locales permitidos para regresar a la inscripción. Si el login es de otro origen o usa JWT, se requiere un adaptador de autenticación explícito; una clave de localStorage no equivale a una sesión PHP.

## Contrato de la API

Ruta base: `public/api/index.php?action=ACCION`. Las respuestas exitosas incluyen `{ok:true,data:...,csrf:...}` y los errores `{ok:false,error:"..."}`. Todas las escrituras son POST con JSON y cabecera `X-CSRF-Token` obtenida de `session`. El token puede rotar tras login, registro, logout o cambio de contraseña; conservar el nuevo valor de cada respuesta.

| Método | action | Entrada | Acceso |
| --- | --- | --- | --- |
| GET | session | — | Público; devuelve usuario o null, modo demo y CSRF |
| GET | courses | — | Catálogo público de cursos activos |
| GET | course | `id` en query | Grupos, fechas, cupo y estado de inscripción propia |
| GET | my-courses | — | Solo alumno autenticado; únicamente sus filas |
| GET | classroom | `id` de curso | Solo alumno con inscripción activa |
| POST | login | `email`, `password` | Público con CSRF y límite de intentos |
| POST | register | `nombre`, `apellidos`, `email`, `password` | Siempre crea rol Alumno |
| POST | logout | `{}` | Sesión actual |
| POST | enroll | `id_grupo`, `expected_price` como texto decimal | Alumno; el servidor determina curso, usuario y precio; compara el importe mostrado para evitar cambios sin confirmar |
| POST | withdraw | `id_inscripcion` | Alumno dueño de la inscripción |
| POST | forgot | `email` | Enlace de recuperación de 30 minutos |
| POST | reset | `token`, `password` | Token válido de un solo uso |

Errores relevantes: 401 sesión necesaria; 403 rol o aula no autorizados; 404 recurso no disponible; 405 método incorrecto; 409 duplicado/cupo/estado/pago; 419 CSRF; 422 datos inválidos; 429 demasiados intentos; 503 configuración incompleta.

## Inscripciones, bajas y reportes

Altas y bajas usan transacciones. Primero se bloquea la fila del alumno y después el grupo; el alta bloquea también el curso al consultar el precio. Se comprueban activo, fecha final, capacidad y la restricción única usuario/curso. En MySQL se usa READ COMMITTED para que el conteo de cupo vea las transacciones confirmadas. El equipo que administre cupos y grupos debe respetar los mismos bloqueos y validar el rol Administrador en sus escrituras.

La baja actualiza `estado='Baja'` y `fecha_baja`; no hace DELETE. Una reinscripción actualiza esa misma fila, respeta UNIQUE `(id_usuario,id_curso)` y puede cambiar de grupo. `pagos` conserva un movimiento por alta o baja aceptada, incluso cuando el importe es cero. Los importes son los del momento, no el precio actual del curso. La baja es gratuita por defecto porque el documento no fija una tarifa; `withdrawal_fee` permite acordar otra con el equipo.

El modelo de seis tablas conserva todos los pagos, pero solo la última fecha de alta/baja de cada inscripción: no contiene una tabla de eventos. Si los reportes necesitan cada ciclo completo de reinscripción o su grupo histórico, el equipo debe agregar una migración de historial; no se puede reconstruir esa información a partir de la fila actual. Para contar altas históricas ya registradas, se pueden agrupar los movimientos de tipo Inscripcion en `pagos` por fecha, incluyendo los de monto cero.

## Pagos reales

SQLite con APP_ENV=demo simula los movimientos e informa que no hay cobro. MySQL/PostgreSQL **nunca habilitan esa simulación**, incluso si se olvida cambiar APP_ENV. Un curso gratuito puede inscribirse directamente; para importes mayores a cero hay que configurar `verify_payment` en `config/integrations.local.php`.

```php
<?php
return [
  'verify_payment' => function (int $userId, int $courseId, string $type, string $amount, string $key): bool {
      // INTEGRAR: consultar la orden ya liquidada en el sistema del equipo.
      // Validar dueño, curso, moneda MXN, tipo, monto y que no se reutilice en otro ciclo.
      // Registrar/reservar atomicamente la referencia unica $key en ese sistema.
      // No aceptar una bandera enviada por el navegador y no cobrar aqui.
      return false;
  },
];
```

El callback debe ser idempotente. Hace una verificación de una orden ya pagada dentro de la transacción, no un nuevo cargo. Si devuelve false o no está configurado, no se registra inscripción ni pago. Conciliación, reembolsos, órdenes pendientes y referencias de pasarela requieren el módulo de pagos del equipo; no están definidos en el esquema académico original.

## Contenido del aula

`course_content` recibe `courseId` y `userId` después de comprobar la inscripción. Debe devolver una lista con esta forma:

```php
return [
  [
    'title' => 'Título del módulo',
    'minutes' => 12,
    'paragraphs' => ['Primer párrafo.', 'Segundo párrafo.'],
    'code' => 'Ejemplo opcional',
    'exercise' => 'Actividad opcional',
  ],
];
```

No devuelvas HTML arbitrario. Para videos o descargas privados, agrega un endpoint que repita la autorización o URL firmadas con caducidad; no coloques archivos protegidos dentro de `public/`. El ejemplo `config/content.php` solo se usa con SQLite en modo demo.

## Recuperación de contraseña

El bosquejo permite escribir un email y otra contraseña directamente. Esta implementación conserva el formulario, pero requiere probar la posesión de un enlace secreto, evitando que cualquiera cambie la cuenta de otra persona. `002_auth_*` añade tokens hasheados con vencimiento y uso único. En producción configura `send_reset_email(email,url)` con el correo del equipo. Sin ese servicio, el sistema informa que la recuperación no está habilitada; no afirma haber enviado correos. La demo muestra el enlace local para probarlo, sin enviar mensajes.

## Diferencias justificadas respecto al documento

- Se implementan las seis tablas que realmente aparecen, no una séptima inventada.
- La tabla adicional de recuperación se entrega en una migración opcional separada.
- No se inventan columnas de edad, género, domicilio, categoría o progreso. Las categorías de los cuatro cursos de demostración son etiquetas visuales; cursos nuevos usan una etiqueta general.
- Las fotografías son sustituciones. Se conserva la composición de los bosquejos, con búsqueda, cupo, estados y adaptación móvil añadidos para operar el flujo.
- Inscripción y aula no tenían un bosquejo individual; reutilizan colores, encabezado, tipografía, formularios y botones de Nexo.
- No se emiten certificados ni se presenta un pago simulado como real. Esos servicios quedan identificados para su integración.
