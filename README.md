# LAB_PW-Fase-3-

# Nexo — Plataforma de cursos en línea (con panel de administración)
 
Es un sitio de cursos en línea con dos partes:
 
- **Módulo de alumnos:** catálogo de cursos, detalle, inscripción, "Mis cursos", aula, registro, login y recuperación de contraseña.
- **Panel de administración:** gestión de usuarios, cursos y grupos, reporte de estadísticas y reporte de ganancias (con registro de gastos).

Tecnologías: HTML5, CSS3, JavaScript nativo y PHP con PDO. Usa una base de datos SQLite, no necesitas configurar un servidor de base de datos.

## Estructura
  
```
nexo/
├── INICIAR-DEMO.cmd        ← Ejecutable para Windows (un clic: prepara y arranca todo)
├── router.php              ← Router del servidor de desarrollo de PHP
├── .env.example            ← Plantilla de configuración (solo para MySQL/Supabase)
├── README.md               ← Documentación original del proyecto
│
├── public/                 ← ÚNICA carpeta pública: lo que ve el navegador
│   ├── index.html, nosotros.html, como-aprenderas.html   (portada e información)
│   ├── cursos.html, curso.html, inscripcion.html,
│   │   mis-cursos.html, aula.html                         (recorrido del alumno)
│   ├── login.html, registro.html, recuperar.html          (acceso)
│   ├── admin.html, admin-usuarios.html, admin-cursos.html,
│   │   admin-curso.html, admin-estadisticas.html,
│   │   admin-ganancias.html                               (panel de administración)
│   ├── api/index.php       ← Punto de entrada de la API JSON
│   └── assets/
│       ├── css/            ← styles.css, admin.css
│       ├── js/             ← app.js, admin.js, config.js (rutas compartidas)
│       └── img/            ← Imágenes de los cursos y logotipo de sustitución
│
├── src/                    ← Lógica del servidor (PHP)
│   ├── bootstrap.php       ← Configuración, conexión PDO, sesiones
│   ├── api.php             ← Reglas de negocio del alumno
│   └── admin_api.php       ← Reglas de negocio del administrador
│
├── config/                 ← Puntos de unión (contenido del aula, correo, pagos)
├── database/               ← Esquemas SQL: SQLite, MySQL y PostgreSQL (001_* y 002_auth_*)
├── scripts/
│   ├── check-demo.php      ← Verifica que PHP tenga lo necesario
│   ├── setup-demo.php      ← Crea la base SQLite con datos de muestra
│   └── crear-admin.php     ← Crea o promueve a un administrador
├── storage/                ← Aquí se guarda demo.sqlite (y el PHP portable en Windows)
├── tests/                  ← Pruebas automáticas (run.php, admin.php, http.mjs)
└── docs/                   ← ADMIN.md, INTEGRACION.md, VALIDACION.md, FUENTES.md y capturas
```

## Software necesario
 
**Navegador web** Chrome, Edge, Firefox o Safari actual
**PHP 8.2 o superior**
**Extensiones PHP** `pdo_sqlite`, `sqlite3` y `mbstring`
 
En Windows, si `INICIAR-DEMO.cmd` no encuentra PHP, descarga una versión portable (PHP 8.4) dentro de la carpeta del proyecto, verifica su huella SHA-256 y la deja configurada. Para eso requiere **conexión a Internet la primera vez**, y que Windows tenga `curl.exe`, `certutil.exe` y `tar.exe`.

## Pasos para usar en Windows

Si ya tienes PHP 8.2+ instalado, o estás en macOS/Linux:
 
1. Verifica PHP y sus extensiones:
```
php -v
php -m | grep -E "pdo_sqlite|mbstring"      # en Windows (CMD): php -m | findstr /i "sqlite mbstring"
```
2. Abre una terminal **dentro de la carpeta `nexo/`** (la que contiene `router.php`):
```
cd ruta/a/nexo
```
3. Prepara la base de datos de demostración (solo hace falta una vez):
```
php scripts/check-demo.php
php scripts/setup-demo.php
```
4. Inicia el servidor:
```
php -S 127.0.0.1:8765 -t public router.php
```
5. Abre **http://127.0.0.1:8765/cursos.html** en el navegador.
6. Para detener el servidor: **Ctrl+C**.

## Recorrido sugerido para ver todo el sitio
 
**Como alumno**
1. Ve a `cursos.html`, usa el buscador y abre un curso.
2. Elige un grupo y pulsa para inscribirte (en la demo se simula el movimiento; no hay cobro real).
3. Inicia sesión con `alumno@nexo.test` y abre **Mis cursos**: verás cursos activos e historial.
4. Entra al **aula** de un curso inscrito, o prueba la **baja** (pide confirmación).
5. Prueba **Registro** y **Recuperar contraseña** (en la demo el enlace de recuperación se muestra en pantalla, no se envía correo).
**Como administrador**
1. Inicia sesión con `admin@nexo.test`; te lleva a `admin.html`.
2. Explora **Usuarios**, **Cursos** (y sus grupos), **Estadísticas** y **Ganancias** (puedes registrar gastos).
