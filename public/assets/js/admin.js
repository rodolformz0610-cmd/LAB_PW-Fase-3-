'use strict';

(() => {
  const config = window.NEXO_CONFIG;
  const page = document.body.dataset.page;
  const root = document.querySelector('#page');
  const params = new URLSearchParams(location.search);
  const state = { user: null, csrf: '' };
  const MONTHS = ['Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];

  /* ---------- utilidades ---------- */
  const esc = value => String(value ?? '').replace(/[&<>"']/g, c => ({ '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' }[c]));
  const money = value => new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN', maximumFractionDigits: 2 }).format(Number(value) || 0);
  const num = value => new Intl.NumberFormat('es-MX', { maximumFractionDigits: 1 }).format(Number(value) || 0);
  const date = value => value ? new Intl.DateTimeFormat('es-MX', { day: 'numeric', month: 'short', year: 'numeric' }).format(new Date(String(value).slice(0, 10) + 'T12:00:00')) : '';
  const safeUrl = value => { try { const u = new URL(value, location.href); return ['http:', 'https:'].includes(u.protocol) ? u.href : '#'; } catch { return '#'; } };
  const route = key => safeUrl(config.routes[key] || 'index.html');
  const link = (href, text, cls = 'text-link') => `<a class="${cls}" href="${esc(href)}">${esc(text)}</a>`;
  const notice = (text, type = '') => `<div class="notice ${type}">${esc(text)}</div>`;
  const $ = selector => document.querySelector(selector);
  const active = value => Number(value) === 1;
  const fullName = user => `${user.nombre} ${user.apellidos}`;

  async function api(action, body, query = {}) {
    const endpoint = new URL(config.apiBase, location.href);
    endpoint.searchParams.set('action', action);
    Object.entries(query).forEach(([k, v]) => endpoint.searchParams.set(k, v));
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), 15000);
    try {
      const response = await fetch(endpoint, { method: body === undefined ? 'GET' : 'POST', credentials: 'same-origin', signal: controller.signal,
        headers: body === undefined ? {} : { 'Content-Type': 'application/json', 'X-CSRF-Token': state.csrf },
        body: body === undefined ? undefined : JSON.stringify(body) });
      let payload;
      try { payload = await response.json(); } catch { throw new Error('No se pudo leer la respuesta. Abre la página desde el servidor PHP indicado en la guía.'); }
      if (!response.ok || !payload.ok) { const error = new Error(payload.error || 'No fue posible completar la operación.'); error.status = response.status; throw error; }
      if (payload.csrf) state.csrf = payload.csrf;
      return payload.data;
    } catch (error) {
      if (error.name === 'AbortError') throw new Error('El servidor tardó demasiado. Comprueba la conexión e intenta de nuevo.');
      if (error instanceof TypeError) throw new Error('No hay conexión con el servidor. Revisa tu conexión y vuelve a intentar.');
      throw error;
    } finally { clearTimeout(timeout); }
  }
  function toast(text) { const item = $('#toast'); item.textContent = text; item.hidden = false; clearTimeout(toast.timer); toast.timer = setTimeout(() => { item.hidden = true; }, 5000); }
  function formError(form, text) { form.querySelector('[role=alert]').textContent = text || ''; }

  /* Diálogo de confirmación reutilizable. Devuelve una promesa con true/false. */
  function confirmAction(title, text, label = 'Confirmar') {
    let dialog = $('#confirm-dialog');
    if (!dialog) {
      document.body.insertAdjacentHTML('beforeend', '<dialog id="confirm-dialog" aria-labelledby="confirm-title"><h2 id="confirm-title"></h2><p id="confirm-text"></p><div class="button-row"><button class="btn btn-secondary" type="button" id="confirm-no">Cancelar</button><button class="btn btn-danger" type="button" id="confirm-yes"></button></div></dialog>');
      dialog = $('#confirm-dialog');
    }
    $('#confirm-title').textContent = title; $('#confirm-text').textContent = text; $('#confirm-yes').textContent = label;
    return new Promise(resolve => {
      const finish = result => { dialog.close(); resolve(result); };
      $('#confirm-no').onclick = () => finish(false);
      $('#confirm-yes').onclick = () => finish(true);
      dialog.oncancel = () => resolve(false);
      dialog.showModal(); $('#confirm-no').focus();
    });
  }

  /* ---------- encabezado y acceso ---------- */
  function nav() {
    const account = `<details class="account"><summary class="pill">Administrador</summary><div class="account-menu"><div class="account-name"><strong class="small">${esc(fullName(state.user))}</strong><div class="small muted">${esc(state.user.email)}</div></div><a href="admin.html">Panel administrador</a><button type="button" id="logout">Cerrar sesión</button></div></details>`;
    $('#header').innerHTML = `<a class="brand" href="${esc(route('home'))}" aria-label="Nexo, inicio"><span class="brand-mark" aria-hidden="true">N</span><span>nexo</span></a><nav class="site-nav" aria-label="Navegación principal"><a href="${esc(route('courses'))}">Cursos</a><a href="${esc(route('about'))}">Quiénes somos</a><a href="${esc(route('learning'))}">Cómo aprenderás</a>${account}</nav>`;
    document.querySelectorAll('[data-route]').forEach(a => { a.href = route(a.dataset.route); });
    $('#logout').addEventListener('click', async event => {
      event.target.disabled = true;
      try { await api('logout', {}); location.assign(route('home')); } catch (error) { event.target.disabled = false; toast(error.message); }
    });
  }
  function accessDenied() {
    const loggedIn = Boolean(state.user);
    root.innerHTML = `<div class="empty"><div class="empty-icon" aria-hidden="true">◇</div><h2>${loggedIn ? 'Sección exclusiva para administradores' : 'Inicia sesión para continuar'}</h2><p>${loggedIn ? 'Tu cuenta no tiene permisos para ver el panel de administración.' : 'Necesitas una cuenta de administrador para entrar al panel.'}</p>${link(loggedIn ? route('courses') : route('login'), loggedIn ? 'Ir a los cursos' : 'Iniciar sesión', 'btn btn-purple')}</div>`;
  }
  const back = (href = 'admin.html', text = 'Volver al menú') => link(href, text);
  const pageTop = (title, right = '') => `<div class="page-top"><h1>${esc(title)}</h1>${right}</div>`;

  /* ---------- selector de mes y año ---------- */
  function period() {
    const now = new Date();
    const month = Number(params.get('mes')) || now.getUTCMonth() + 1;
    const year = Number(params.get('anio')) || now.getUTCFullYear();
    return { month: Math.min(12, Math.max(1, month)), year };
  }
  function periodForm(selected) {
    const thisYear = new Date().getUTCFullYear();
    const years = []; for (let y = thisYear + 1; y >= thisYear - 6; y--) years.push(y);
    if (!years.includes(selected.year)) years.push(selected.year);
    years.sort((a, b) => b - a);
    return `<form class="period" id="period-form"><div class="field"><label for="mes">Mes</label><select id="mes" name="mes">${MONTHS.map((m, i) => `<option value="${i + 1}" ${i + 1 === selected.month ? 'selected' : ''}>${m}</option>`).join('')}</select></div><div class="field"><label for="anio">Año</label><select id="anio" name="anio">${years.map(y => `<option ${y === selected.year ? 'selected' : ''}>${y}</option>`).join('')}</select></div><button class="btn" type="submit">Consultar</button></form>`;
  }
  function wirePeriod(onChange) {
    $('#period-form').addEventListener('submit', event => {
      event.preventDefault();
      const data = new FormData(event.target);
      const next = new URLSearchParams({ mes: data.get('mes'), anio: data.get('anio') });
      history.replaceState(null, '', `${location.pathname}?${next}`);
      params.set('mes', data.get('mes')); params.set('anio', data.get('anio'));
      onChange();
    });
  }

  /* ---------- 6. Menú administrador ---------- */
  async function showMenu() {
    const overview = await api('admin-overview');
    root.innerHTML = `${pageTop('Menú Administrador')}<div class="admin-list">
      <section class="admin-card"><div><h2>Usuarios</h2><p class="muted">${overview.usuarios} ${overview.usuarios === 1 ? 'cuenta registrada' : 'cuentas registradas'}</p></div><div class="actions">${link('admin-usuarios.html', 'Editar', 'btn btn-small')}</div></section>
      <section class="admin-card"><div><h2>Cursos</h2><p class="muted">${overview.cursos} ${overview.cursos === 1 ? 'curso' : 'cursos'} · ${overview.grupos_activos} ${overview.grupos_activos === 1 ? 'grupo activo' : 'grupos activos'}</p></div><div class="actions">${link('admin-curso.html?nuevo=1', 'Agregar', 'btn btn-small')}${link('admin-cursos.html', 'Editar', 'btn btn-small')}</div></section>
      <section class="admin-card"><div><h2>Reportes Mensuales</h2><p class="muted">Inscripciones, ganancias y gastos por mes</p></div><div class="actions">${link('admin-estadisticas.html', 'Estadísticas', 'btn btn-small')}${link('admin-ganancias.html', 'Ganancias', 'btn btn-small')}</div></section></div>`;
  }

  /* ---------- 7. Usuarios ---------- */
  async function showUsers() {
    let users = await api('admin-users');
    const draw = () => {
      root.innerHTML = `${pageTop('Editar Usuarios', back())}<div class="toolbar-admin"><label class="search"><span aria-hidden="true">⌕</span><input id="user-search" type="search" placeholder="Buscar por nombre o correo" aria-label="Buscar usuario"></label><button class="btn" id="new-user" type="button">Agregar usuario</button></div><p class="count" id="user-count" aria-live="polite"></p><div class="admin-list" id="user-list"></div>`;
      const render = () => {
        const term = $('#user-search').value.trim().normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
        const list = users.filter(u => `${fullName(u)} ${u.email}`.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().includes(term));
        $('#user-count').textContent = `${list.length} ${list.length === 1 ? 'usuario' : 'usuarios'}`;
        $('#user-list').innerHTML = list.length ? list.map(u => `<article class="admin-card"><div class="grow"><h2>${esc(fullName(u))}</h2><p class="muted">${esc(u.email)} · ${Number(u.inscripciones_activas)} ${Number(u.inscripciones_activas) === 1 ? 'curso activo' : 'cursos activos'}</p></div><div class="actions"><span class="badge ${u.rol === 'Administrador' ? '' : 'gray'}">${esc(u.rol)}</span>${active(u.activo) ? '' : '<span class="badge off">Inactivo</span>'}<button class="btn btn-small" type="button" data-edit="${Number(u.id_usuario)}" aria-label="Editar a ${esc(fullName(u))}">Editar</button></div></article>`).join('') : '<div class="empty"><h2>No hay resultados</h2><p>Prueba con otro nombre o correo.</p></div>';
      };
      $('#user-search').addEventListener('input', render);
      $('#user-list').addEventListener('click', event => { const b = event.target.closest('[data-edit]'); if (b) editUser(users.find(u => Number(u.id_usuario) === Number(b.dataset.edit))); });
      $('#new-user').addEventListener('click', () => editUser(null));
      render();
    };
    const editUser = user => {
      const isNew = !user;
      const u = user || { nombre: '', apellidos: '', email: '', rol: 'Alumno', activo: 1 };
      const self = !isNew && Number(u.id_usuario) === Number(state.user.id_usuario);
      root.innerHTML = `${pageTop(isNew ? 'Agregar usuario' : 'Editar Usuarios')}<section class="admin-panel"><form id="user-form" novalidate><div class="admin-panel-head"><h2>${isNew ? 'Usuario nuevo' : esc(fullName(u))}</h2><div class="button-row"><button class="btn btn-small" type="submit">Guardar</button><button class="btn btn-small btn-secondary" type="button" id="cancel">Cancelar</button>${isNew || self ? '' : '<button class="btn btn-small btn-danger" type="button" id="delete">Eliminar</button>'}</div></div>
        <div class="form-grid"><div class="field"><label for="nombre">Nombre</label><input id="nombre" name="nombre" maxlength="60" value="${esc(u.nombre)}" required></div><div class="field"><label for="apellidos">Apellidos</label><input id="apellidos" name="apellidos" maxlength="80" value="${esc(u.apellidos)}" required></div></div>
        <div class="field"><label for="email">Email</label><input id="email" name="email" type="email" maxlength="120" value="${esc(u.email)}" autocomplete="off" required></div>
        <div class="form-grid"><div class="field"><label for="rol">Rol</label><select id="rol" name="rol" ${self ? 'disabled' : ''}><option ${u.rol === 'Alumno' ? 'selected' : ''}>Alumno</option><option ${u.rol === 'Administrador' ? 'selected' : ''}>Administrador</option></select>${self ? '<p class="hint">No puedes cambiar tu propio rol.</p>' : ''}</div>
        <div class="field"><label for="password">${isNew ? 'Contraseña inicial' : 'Nueva contraseña'}</label><input id="password" name="password" type="password" minlength="10" maxlength="72" autocomplete="new-password" ${isNew ? 'required' : ''}><p class="hint">${isNew ? 'Mínimo 10 caracteres.' : 'Déjala vacía para conservar la actual.'}</p></div></div>
        <label class="inline-check"><input type="checkbox" name="activo" ${active(u.activo) ? 'checked' : ''} ${self ? 'disabled' : ''}> Cuenta activa (puede iniciar sesión)</label>
        <p class="form-message" role="alert"></p></form></section>`;
      const form = $('#user-form');
      $('#cancel').addEventListener('click', draw);
      form.addEventListener('submit', async event => {
        event.preventDefault(); formError(form, '');
        const data = new FormData(form); const button = form.querySelector('[type=submit]'); button.disabled = true;
        const body = { id: isNew ? null : u.id_usuario, nombre: data.get('nombre'), apellidos: data.get('apellidos'), email: data.get('email'),
          rol: self ? u.rol : data.get('rol'), activo: self ? true : data.get('activo') === 'on', password: data.get('password') || '' };
        try {
          const saved = await api('admin-user-save', body);
          users = await api('admin-users'); draw(); toast(isNew ? `Usuario creado: ${fullName(saved)}.` : 'Cambios guardados.');
        } catch (error) { formError(form, error.message); button.disabled = false; }
      });
      $('#delete')?.addEventListener('click', async () => {
        if (!await confirmAction('¿Eliminar este usuario?', `${fullName(u)}. Si tiene inscripciones o pagos, la cuenta se desactivará para conservar el historial.`, 'Eliminar')) return;
        try { const result = await api('admin-user-delete', { id: u.id_usuario }); users = await api('admin-users'); draw(); toast(result.message); }
        catch (error) { formError(form, error.message); }
      });
      $('#nombre').focus();
    };
    draw();
  }

  /* ---------- 7. Lista de cursos ---------- */
  async function showCourses() {
    const courses = await api('admin-courses');
    root.innerHTML = `${pageTop('Editar Cursos', back())}<div class="toolbar-admin"><span class="count">${courses.length} ${courses.length === 1 ? 'curso' : 'cursos'}</span>${link('admin-curso.html?nuevo=1', 'Agregar curso', 'btn')}</div><div class="admin-list">${courses.length ? courses.map(c => `<article class="admin-card has-image"><img class="course-img" src="${esc(safeUrl(c.imagen_url || 'assets/img/placeholder.svg'))}" alt="" loading="lazy" width="120" height="96"><div class="grow"><h2>${esc(c.nombre)}</h2><p class="muted">${Number(c.duracion_horas)} horas · ${esc(money(c.precio))} · ${Number(c.grupos)} ${Number(c.grupos) === 1 ? 'grupo' : 'grupos'} · ${Number(c.inscritos)} ${Number(c.inscritos) === 1 ? 'alumno activo' : 'alumnos activos'}</p></div><div class="actions">${active(c.destacado) ? '<span class="badge">Destacado</span>' : ''}${active(c.activo) ? '' : '<span class="badge off">Inactivo</span>'}${link('admin-curso.html?id=' + Number(c.id_curso), 'Editar', 'btn btn-small')}</div></article>`).join('') : '<div class="empty"><h2>Aún no hay cursos</h2><p>Agrega el primero para que aparezca en el catálogo.</p></div>'}</div>`;
  }

  /* ---------- 8. Agregar / editar un curso y sus grupos ---------- */
  async function showCourse() {
    const isNew = params.has('nuevo') && !params.get('id');
    const [admins, course] = await Promise.all([api('admin-admins'), isNew ? Promise.resolve(null) : api('admin-course', undefined, { id: params.get('id') || '' })]);
    const c = course || { nombre: '', descripcion: '', duracion_horas: '', precio: '0', imagen_url: '', destacado: 0, activo: 1, id_admin: state.user.id_usuario, grupos: [] };
    // Si el responsable guardado ya no es administrador activo, se obliga a elegir uno en lugar de cambiarlo en silencio.
    const adminOptions = selected => (!isNew && selected && !admins.some(a => Number(a.id_usuario) === Number(selected)) ? '<option value="" selected disabled>Elige un administrador activo</option>' : '')
      + admins.map(a => `<option value="${Number(a.id_usuario)}" ${Number(a.id_usuario) === Number(selected) ? 'selected' : ''}>${esc(fullName(a))}</option>`).join('');
    document.title = `${isNew ? 'Agregar curso' : c.nombre} | Nexo`;
    root.innerHTML = `${pageTop(isNew ? 'Agregar un curso' : 'Editar un curso', back('admin-cursos.html', 'Volver a cursos'))}
      <section class="admin-panel"><form id="course-form" novalidate><div class="admin-panel-head"><h2>${isNew ? 'Curso nuevo' : esc(c.nombre)}</h2><div class="button-row"><button class="btn btn-small" type="submit">Guardar</button><button class="btn btn-small btn-secondary" type="button" id="cancel">Cancelar</button>${isNew ? '' : '<button class="btn btn-small btn-danger" type="button" id="delete">Eliminar</button>'}</div></div>
      <div class="field"><label for="nombre">Nombre del curso</label><input id="nombre" name="nombre" maxlength="100" value="${esc(c.nombre)}" required></div>
      <div class="field"><label for="descripcion">Descripción</label><textarea id="descripcion" name="descripcion" maxlength="4000" required>${esc(c.descripcion)}</textarea></div>
      <div class="form-grid-3"><div class="field"><label for="duracion_horas">Duración (horas)</label><input id="duracion_horas" name="duracion_horas" type="number" min="1" max="10000" step="1" value="${esc(c.duracion_horas)}" required></div><div class="field"><label for="precio">Precio (MXN)</label><input id="precio" name="precio" type="number" min="0" max="99999999" step="0.01" value="${esc(c.precio)}" required><p class="hint">Usa 0 para un curso gratuito.</p></div><div class="field"><label for="id_admin">Responsable</label><select id="id_admin" name="id_admin">${adminOptions(c.id_admin)}</select></div></div>
      <div class="field"><label for="imagen_url">Imagen (URL o ruta assets/img/…)</label><input id="imagen_url" name="imagen_url" maxlength="255" value="${esc(c.imagen_url)}" placeholder="assets/img/mi-curso.jpg"></div>
      <label class="inline-check"><input type="checkbox" name="destacado" ${active(c.destacado) ? 'checked' : ''}> Mostrar como destacado en el menú principal</label>
      <label class="inline-check"><input type="checkbox" name="activo" ${active(c.activo) ? 'checked' : ''}> Curso activo (visible en el catálogo)</label>
      <p class="form-message" role="alert"></p></form></section>
      <div id="groups-area"></div>`;
    const form = $('#course-form');
    $('#cancel').addEventListener('click', () => location.assign('admin-cursos.html'));
    form.addEventListener('submit', async event => {
      event.preventDefault(); formError(form, '');
      const data = new FormData(form); const button = form.querySelector('[type=submit]'); button.disabled = true;
      const body = { id: isNew ? null : c.id_curso, nombre: data.get('nombre'), descripcion: data.get('descripcion'), duracion_horas: data.get('duracion_horas'),
        precio: String(data.get('precio')), id_admin: data.get('id_admin'), imagen_url: data.get('imagen_url'), destacado: data.get('destacado') === 'on', activo: data.get('activo') === 'on' };
      try {
        const saved = await api('admin-course-save', body);
        if (isNew) { location.assign(`admin-curso.html?id=${Number(saved.id_curso)}&creado=1`); return; }
        toast('Cambios guardados.'); button.disabled = false; $('#main').querySelector('.admin-panel-head h2').textContent = saved.nombre;
      } catch (error) { formError(form, error.message); button.disabled = false; }
    });
    $('#delete')?.addEventListener('click', async () => {
      if (!await confirmAction('¿Eliminar este curso?', `${c.nombre}. Si ya tiene inscripciones, se desactivará para conservar el historial; si no, se borrará junto con sus grupos.`, 'Eliminar')) return;
      try { const result = await api('admin-course-delete', { id: c.id_curso }); if (result.deleted) { sessionStorage.setItem('nexo-admin-toast', result.message); location.assign('admin-cursos.html'); } else { toast(result.message); setTimeout(() => location.reload(), 1200); } }
      catch (error) { formError(form, error.message); }
    });
    if (params.get('creado')) { toast('Curso creado. Ahora puedes agregarle grupos.'); history.replaceState(null, '', `admin-curso.html?id=${Number(c.id_curso)}`); }
    if (!isNew) groupsSection(c, admins, adminOptions);
    else $('#groups-area').innerHTML = notice('Guarda el curso para poder agregarle grupos con profesor, cupo y fechas.');
  }

  function groupsSection(course, admins, adminOptions) {
    let groups = course.grupos;
    const area = $('#groups-area');
    document.body.insertAdjacentHTML('beforeend', `<dialog id="group-dialog" class="wide" aria-labelledby="group-title"><form id="group-form" novalidate><h2 id="group-title">Grupo</h2><input type="hidden" name="id"><div class="field"><label for="g-nombre">Nombre del grupo</label><input id="g-nombre" name="nombre" maxlength="50" required></div><div class="form-grid"><div class="field"><label for="g-prof">Profesor</label><select id="g-prof" name="id_profesor"></select></div><div class="field"><label for="g-cupo">Cupo máximo</label><input id="g-cupo" name="cupo_maximo" type="number" min="1" step="1" required></div></div><div class="form-grid"><div class="field"><label for="g-inicio">Fecha de inicio</label><input id="g-inicio" name="fecha_inicio" type="date" required></div><div class="field"><label for="g-fin">Fecha de fin</label><input id="g-fin" name="fecha_fin" type="date" required></div></div><label class="inline-check"><input type="checkbox" name="activo"> Grupo activo (acepta inscripciones)</label><p class="form-message" role="alert"></p><div class="button-row"><button class="btn btn-secondary" type="button" id="g-cancel">Cancelar</button><button class="btn" type="submit">Guardar grupo</button></div></form></dialog>`);
    const dialog = $('#group-dialog'); const gform = $('#group-form');
    const render = () => {
      area.innerHTML = `<section class="admin-panel"><div class="admin-panel-head"><h2>Grupos del curso</h2><button class="btn btn-small" type="button" id="add-group">Añadir grupo</button></div>${groups.length ? `<div class="table-wrap"><table class="data-table"><thead><tr><th>Grupo</th><th>Profesor</th><th>Fechas</th><th class="num">Inscritos / cupo</th><th>Estado</th><th><span class="sr-only">Acciones</span></th></tr></thead><tbody>${groups.map(g => `<tr><td><strong>${esc(g.nombre)}</strong></td><td>${esc(g.profesor_nombre)} ${esc(g.profesor_apellidos)}</td><td>${esc(date(g.fecha_inicio))} – ${esc(date(g.fecha_fin))}</td><td class="num">${Number(g.inscritos)} / ${Number(g.cupo_maximo)}</td><td>${active(g.activo) ? '<span class="badge">Activo</span>' : '<span class="badge off">Inactivo</span>'}</td><td class="num"><button class="btn btn-small" type="button" data-group="${Number(g.id_grupo)}">Editar</button> <button class="btn btn-small btn-danger" type="button" data-group-delete="${Number(g.id_grupo)}" aria-label="Eliminar ${esc(g.nombre)}">Eliminar</button></td></tr>`).join('')}</tbody></table></div>` : '<p class="muted">Este curso todavía no tiene grupos, por lo que nadie puede inscribirse.</p>'}</section>`;
    };
    const reload = async () => { groups = (await api('admin-course', undefined, { id: course.id_curso })).grupos; render(); };
    const open = group => {
      const g = group || { id: '', nombre: '', id_profesor: state.user.id_usuario, cupo_maximo: 20, fecha_inicio: '', fecha_fin: '', activo: 1 };
      $('#group-title').textContent = group ? `Editar ${group.nombre}` : 'Añadir grupo';
      gform.elements.id.value = group ? group.id_grupo : '';
      gform.elements.nombre.value = g.nombre; gform.elements.cupo_maximo.value = g.cupo_maximo;
      gform.elements.fecha_inicio.value = String(g.fecha_inicio).slice(0, 10); gform.elements.fecha_fin.value = String(g.fecha_fin).slice(0, 10);
      gform.elements.activo.checked = active(g.activo);
      $('#g-prof').innerHTML = adminOptions(g.id_profesor);
      formError(gform, ''); dialog.showModal(); gform.elements.nombre.focus();
    };
    area.addEventListener('click', async event => {
      if (event.target.closest('#add-group')) return open(null);
      const edit = event.target.closest('[data-group]');
      if (edit) return open(groups.find(g => Number(g.id_grupo) === Number(edit.dataset.group)));
      const del = event.target.closest('[data-group-delete]');
      if (del) {
        const g = groups.find(x => Number(x.id_grupo) === Number(del.dataset.groupDelete));
        if (!await confirmAction('¿Eliminar este grupo?', `${g.nombre}. Si ya tiene inscripciones se desactivará para conservar el historial.`, 'Eliminar')) return;
        try { const result = await api('admin-group-delete', { id: g.id_grupo }); await reload(); toast(result.message); } catch (error) { toast(error.message); }
      }
    });
    $('#g-cancel').addEventListener('click', () => dialog.close());
    gform.addEventListener('submit', async event => {
      event.preventDefault(); formError(gform, '');
      const data = new FormData(gform); const button = gform.querySelector('[type=submit]'); button.disabled = true;
      const body = { id: data.get('id') || null, id_curso: course.id_curso, nombre: data.get('nombre'), id_profesor: data.get('id_profesor'), cupo_maximo: data.get('cupo_maximo'),
        fecha_inicio: data.get('fecha_inicio'), fecha_fin: data.get('fecha_fin'), activo: data.get('activo') === 'on' };
      try { await api('admin-group-save', body); dialog.close(); await reload(); toast('Grupo guardado.'); }
      catch (error) { formError(gform, error.message); } finally { button.disabled = false; }
    });
    render();
  }

  /* ---------- 9. Estadísticas ---------- */
  async function showStats() {
    const selected = period();
    root.innerHTML = `${pageTop('Estadísticas de la Página', back())}${periodForm(selected)}<div id="stats-result" aria-live="polite"><p class="loading">Cargando…</p></div>`;
    const load = async () => {
      const p = period(); const out = $('#stats-result');
      try {
        const s = await api('admin-stats', undefined, { mes: p.month, anio: p.year });
        const label = `${MONTHS[s.periodo.mes - 1].toLowerCase()} ${s.periodo.anio}`;
        out.innerHTML = `<div class="metric-grid"><section class="metric"><h2>Alumnos inscritos</h2><p class="sub">Inscripciones a cursos · ${esc(label)}</p><p class="big">${num(s.alumnos_inscritos)}</p><p class="unit">alumnos este mes</p></section><section class="metric"><h2>Cursos añadidos</h2><p class="sub">Nuevos en la página · ${esc(label)}</p><p class="big">${num(s.cursos_anadidos)}</p><p class="unit">cursos este mes</p></section><section class="metric"><h2>Promedio semanal</h2><p class="sub">Alumnos inscritos por semana · ${esc(label)}</p><p class="big">${num(s.promedio_semanal)}</p><p class="unit">alumnos por semana</p></section></div>
          <h2 class="section-title">Inscripciones por curso</h2>${s.por_curso.length ? `<div class="table-wrap"><table class="data-table"><thead><tr><th>Curso</th><th class="num">Inscripciones</th></tr></thead><tbody>${s.por_curso.map(r => `<tr><td>${esc(r.nombre)}</td><td class="num">${Number(r.inscripciones)}</td></tr>`).join('')}</tbody></table></div>` : '<p class="muted">No hubo inscripciones en este periodo.</p>'}
          <p class="small muted" style="margin-top:14px">Bajas en el periodo: ${Number(s.bajas)}. El promedio semanal divide las inscripciones entre las semanas del mes (días ÷ 7). Los periodos se calculan en horario UTC.</p>`;
      } catch (error) { out.innerHTML = notice(error.message, 'error'); }
    };
    wirePeriod(load); load();
  }

  /* ---------- 10. Ganancias y gastos ---------- */
  async function showEarnings() {
    const selected = period();
    root.innerHTML = `${pageTop('Reporte de Ganancias Mensual', back())}${periodForm(selected)}<div id="earn-result" aria-live="polite"><p class="loading">Cargando…</p></div>`;
    document.body.insertAdjacentHTML('beforeend', `<dialog id="detail-dialog" class="wide" aria-labelledby="detail-title"><h2 id="detail-title"></h2><div id="detail-body"></div><div class="button-row"><button class="btn" type="button" id="detail-close">Cerrar</button></div></dialog><dialog id="expense-dialog" aria-labelledby="expense-title"><form id="expense-form" novalidate><h2 id="expense-title">Registrar gasto</h2><div class="field"><label for="e-concepto">Concepto</label><input id="e-concepto" name="concepto" maxlength="150" required></div><div class="form-grid"><div class="field"><label for="e-monto">Monto (MXN)</label><input id="e-monto" name="monto" type="number" min="0.01" max="99999999" step="0.01" required></div><div class="field"><label for="e-fecha">Fecha</label><input id="e-fecha" name="fecha_gasto" type="date" required></div></div><div class="field"><label for="e-curso">Curso relacionado (opcional)</label><select id="e-curso" name="id_curso"><option value="">Sin curso</option></select></div><p class="form-message" role="alert"></p><div class="button-row"><button class="btn btn-secondary" type="button" id="e-cancel">Cancelar</button><button class="btn" type="submit">Guardar gasto</button></div></form></dialog>`);
    const detailDialog = $('#detail-dialog'); const expenseDialog = $('#expense-dialog'); const eform = $('#expense-form');
    $('#detail-close').addEventListener('click', () => detailDialog.close());
    $('#e-cancel').addEventListener('click', () => expenseDialog.close());
    const reportRow = (title, sub, amount, kind, label, detail) => `<section class="money-row"><div class="grow"><h3>${esc(title)}</h3><p class="muted">${esc(sub)}</p></div><span class="badge ${kind}">${esc(label)}</span><span class="amount">${esc(money(amount))}</span><button class="btn btn-small" type="button" data-detail="${detail}">Ver detalle</button></section>`;
    const load = async () => {
      const p = period(); const out = $('#earn-result');
      try {
        const e = await api('admin-earnings', undefined, { mes: p.month, anio: p.year });
        const label = `${MONTHS[e.periodo.mes - 1]} ${e.periodo.anio}`;
        const plural = (n, one, many) => `${n} ${n === 1 ? one : many}`;
        out.innerHTML = `<h2 class="section-title">Ganancias</h2>${reportRow('Ganancias de Inscripciones a Cursos', `${plural(e.inscripciones.cantidad, 'inscripción', 'inscripciones')} · ${label}`, e.inscripciones.total, 'in', 'Ingreso', 'inscripciones')}${reportRow('Ganancias de bajas a Cursos', `${plural(e.bajas.cantidad, 'baja', 'bajas')} · ${label}`, e.bajas.total, 'in', 'Ingreso', 'bajas')}${reportRow('Ganancias finales de este mes', `Inscripciones + bajas a cursos · ${label}`, e.ganancias_total, 'total', 'Total', 'total')}
          <h2 class="section-title">Gastos</h2>${reportRow('Gastos finales de este mes en el mantenimiento de Cursos', `${plural(e.gastos.cantidad, 'gasto registrado', 'gastos registrados')} · ${label}`, e.gastos.total, 'out', 'Egreso', 'gastos')}
          <div class="net-line"><span>Resultado del mes (ganancias − gastos)</span><span>${esc(money(e.neto))}</span></div>
          <div class="button-row" style="margin-top:22px"><button class="btn" type="button" id="add-expense">Registrar gasto</button></div>
          <p class="small muted" style="margin-top:14px">Los importes son los cobrados al momento de cada movimiento; cambiar el precio de un curso no altera meses anteriores. Los periodos se calculan en horario UTC.</p>`;
      } catch (error) { out.innerHTML = notice(error.message, 'error'); }
    };
    const showDetail = async kind => {
      const p = period(); const titles = { inscripciones: 'Detalle: inscripciones', bajas: 'Detalle: bajas', total: 'Detalle: ganancias del mes', gastos: 'Detalle: gastos' };
      $('#detail-title').textContent = titles[kind]; $('#detail-body').innerHTML = '<p class="loading">Cargando…</p>'; detailDialog.showModal();
      try {
        const result = await api('admin-detail', undefined, { tipo: kind, mes: p.month, anio: p.year });
        if (!result.filas.length) { $('#detail-body').innerHTML = '<p class="muted">No hay movimientos en este periodo.</p>'; return; }
        const total = result.filas.reduce((sum, r) => sum + Number(r.monto), 0);
        $('#detail-body').innerHTML = kind === 'gastos'
          ? `<div class="table-wrap"><table class="data-table"><thead><tr><th>Fecha</th><th>Concepto</th><th>Curso</th><th class="num">Monto</th><th><span class="sr-only">Acciones</span></th></tr></thead><tbody>${result.filas.map(r => `<tr><td>${esc(date(r.fecha_gasto))}</td><td>${esc(r.concepto)}</td><td>${esc(r.curso || '—')}</td><td class="num">${esc(money(r.monto))}</td><td class="num"><button class="btn btn-small btn-danger" type="button" data-expense-delete="${Number(r.id_gasto)}" aria-label="Eliminar gasto ${esc(r.concepto)}">Eliminar</button></td></tr>`).join('')}</tbody></table></div><p class="small muted">Total mostrado: ${esc(money(total))}</p>`
          : `<div class="table-wrap"><table class="data-table"><thead><tr><th>Fecha</th><th>Alumno</th><th>Curso</th><th>Tipo</th><th class="num">Monto</th></tr></thead><tbody>${result.filas.map(r => `<tr><td>${esc(date(r.fecha_pago))}</td><td>${esc(r.nombre)} ${esc(r.apellidos)}</td><td>${esc(r.curso)}</td><td>${r.tipo === 'Baja' ? 'Baja' : 'Inscripción'}</td><td class="num">${esc(money(r.monto))}</td></tr>`).join('')}</tbody></table></div><p class="small muted">Total mostrado: ${esc(money(total))}${result.filas.length >= 500 ? ' (se muestran los 500 movimientos más recientes)' : ''}</p>`;
      } catch (error) { $('#detail-body').innerHTML = notice(error.message, 'error'); }
    };
    $('#earn-result').addEventListener('click', async event => {
      const detail = event.target.closest('[data-detail]'); if (detail) return showDetail(detail.dataset.detail);
      if (event.target.closest('#add-expense')) {
        try {
          const courses = await api('admin-courses');
          $('#e-curso').innerHTML = '<option value="">Sin curso</option>' + courses.map(c => `<option value="${Number(c.id_curso)}">${esc(c.nombre)}</option>`).join('');
        } catch (error) { toast(error.message); return; }
        eform.reset(); formError(eform, ''); eform.elements.fecha_gasto.value = new Date().toISOString().slice(0, 10); expenseDialog.showModal(); eform.elements.concepto.focus();
      }
    });
    $('#detail-body').addEventListener('click', async event => {
      const del = event.target.closest('[data-expense-delete]'); if (!del) return;
      if (!await confirmAction('¿Eliminar este gasto?', 'El reporte del mes se recalculará sin este gasto.', 'Eliminar')) return;
      try { const result = await api('admin-expense-delete', { id: Number(del.dataset.expenseDelete) }); toast(result.message); await showDetail('gastos'); await load(); }
      catch (error) { toast(error.message); }
    });
    eform.addEventListener('submit', async event => {
      event.preventDefault(); formError(eform, '');
      const data = new FormData(eform); const button = eform.querySelector('[type=submit]'); button.disabled = true;
      try {
        await api('admin-expense-save', { concepto: data.get('concepto'), monto: String(data.get('monto')), fecha_gasto: data.get('fecha_gasto'), id_curso: data.get('id_curso') || null });
        expenseDialog.close(); toast('Gasto registrado. Si su fecha cae en otro mes, aparecerá al consultar ese mes.'); await load();
      } catch (error) { formError(eform, error.message); } finally { button.disabled = false; }
    });
    wirePeriod(load); load();
  }

  /* ---------- arranque ---------- */
  document.addEventListener('error', event => { if (event.target.tagName === 'IMG' && !event.target.src.endsWith('placeholder.svg')) event.target.src = 'assets/img/placeholder.svg'; }, true);
  document.addEventListener('click', event => { if (!event.target.closest('.account')) document.querySelector('.account')?.removeAttribute('open'); });
  async function init() {
    try {
      const session = await api('session'); state.user = session.user;
      if (!state.user || state.user.rol !== 'Administrador') {
        if (state.user) nav(); else $('#header').innerHTML = `<a class="brand" href="${esc(route('home'))}"><span class="brand-mark" aria-hidden="true">N</span><span>nexo</span></a>`;
        accessDenied(); return;
      }
      nav();
      const pending = sessionStorage.getItem('nexo-admin-toast'); if (pending) { sessionStorage.removeItem('nexo-admin-toast'); toast(pending); }
      const renderers = { 'admin-menu': showMenu, 'admin-users': showUsers, 'admin-courses': showCourses, 'admin-course': showCourse, 'admin-stats': showStats, 'admin-earnings': showEarnings };
      await renderers[page]();
    } catch (error) {
      root.innerHTML = `<div class="empty"><div class="empty-icon" aria-hidden="true">◇</div><h2>No pudimos cargar esta página</h2><p>${esc(error.message)}</p><button class="btn" id="retry" type="button">Volver a intentar</button></div>`;
      $('#retry').addEventListener('click', () => location.reload());
    }
  }
  init();
})();
