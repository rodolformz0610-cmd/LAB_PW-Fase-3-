/* RUTAS COMPARTIDAS: sustituir aqui por las paginas del equipo al integrar.
 * Rutas relativas a public/; tambien se admiten URL http(s) para navegacion.
 * apiBase debe estar en el mismo origen para compartir la sesion PHP.
 */
window.NEXO_CONFIG = Object.freeze({
  apiBase: 'api/index.php',
  routes: {
    home: 'index.html', courses: 'cursos.html', about: 'nosotros.html',
    learning: 'como-aprenderas.html', login: 'login.html', register: 'registro.html',
    recovery: 'recuperar.html', myCourses: 'mis-cursos.html',
    // Completar cuando el equipo entregue el panel. Vacio oculta el enlace.
    admin: 'admin.html'
  }
});
