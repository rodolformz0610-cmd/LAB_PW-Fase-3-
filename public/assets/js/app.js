'use strict';

(() => {
  const config = window.NEXO_CONFIG;
  const page = document.body.dataset.page;
  const root = document.querySelector('#page');
  const params = new URLSearchParams(location.search);
  const state = { user: null, demo: false, csrf: '', withdrawalFee: 0 };
  const money = value => Number(value) === 0 ? 'Gratis' : new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN', maximumFractionDigits: 2 }).format(Number(value));
  const date = value => new Intl.DateTimeFormat('es-MX', { day: 'numeric', month: 'short', year: 'numeric' }).format(new Date(String(value).slice(0, 10) + 'T12:00:00'));
  const esc = value => String(value ?? '').replace(/[&<>"']/g, char => ({ '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' }[char]));
  const url = value => {
    try { const parsed = new URL(value, location.href); return ['http:', 'https:'].includes(parsed.protocol) ? parsed.href : '#'; }
    catch { return '#'; }
  };
  const route = key => url(config.routes[key] || 'index.html');
  const link = (href, text, cls = 'text-link') => `<a class="${cls}" href="${esc(url(href))}">${esc(text)}</a>`;
  const image = course => `<img class="course-img" src="${esc(url(course.imagen_url || 'assets/img/placeholder.svg'))}" alt="" loading="lazy" width="172" height="132">`;
  const category = course => ({ 'Liderazgo en proyectos creativos':'Creatividad · Jóvenes', 'Programación básica con Python':'Práctico · Desarrolladores', 'Finanzas Personales':'Financiero · Adultos', 'Comunicación efectiva':'Comunicativo · Profesionales' }[course.nombre] || 'Aprendizaje · En línea');
  const notice = (text, type = '') => `<div class="notice ${type}">${esc(text)}</div>`;
  const crumb = (title, href = route('courses'), parent = 'Cursos') => `<div class="breadcrumb">${link(href, parent, '')}<span aria-hidden="true">/</span><span>${esc(title)}</span></div>`;
  const empty = (title, text, href, label) => `<div class="empty"><div class="empty-icon" aria-hidden="true">◇</div><h2>${esc(title)}</h2><p>${esc(text)}</p>${href ? link(href, label, 'btn btn-purple') : ''}</div>`;

  async function api(action, body, query = {}) {
    const endpoint = new URL(config.apiBase, location.href);
    endpoint.searchParams.set('action', action);
    Object.entries(query).forEach(([key, value]) => endpoint.searchParams.set(key, value));
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), 15000);
    try {
      const response = await fetch(endpoint, { method: body === undefined ? 'GET' : 'POST', credentials: 'same-origin', signal: controller.signal,
        headers: body === undefined ? {} : { 'Content-Type':'application/json', 'X-CSRF-Token':state.csrf },
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
  function toast(text) { const item = document.querySelector('#toast'); item.textContent = text; item.hidden = false; setTimeout(() => { item.hidden = true; }, 5000); }
  function fail(error) {
    root.innerHTML = `${empty('No pudimos cargar esta página', error.message, null)}<div class="button-row" style="justify-content:center;margin-top:20px"><button class="btn" id="retry">Volver a intentar</button>${link(route('courses'),'Ver cursos','btn btn-secondary')}</div>`;
    document.querySelector('#retry').addEventListener('click', () => location.reload());
  }
  function nav() {
    const navLink = (key, title, selected) => `<a href="${esc(route(key))}" ${selected ? 'aria-current="page"' : ''}>${title}</a>`;
    const account = state.user
      ? `<details class="account"><summary class="pill">${state.user.rol === 'Alumno' ? 'Usuario Registrado' : 'Administrador'}</summary><div class="account-menu"><div class="account-name"><strong class="small">${esc(state.user.nombre)} ${esc(state.user.apellidos)}</strong><div class="small muted">${esc(state.user.email)}</div></div>${state.user.rol === 'Alumno' ? navLink('myCourses','Mis cursos',page === 'my-courses') : config.routes.admin ? navLink('admin','Panel administrador',false) : '<p class="small muted">Panel pendiente de integrar por el equipo.</p>'}<button type="button" id="logout">Cerrar sesión</button></div></details>`
      : `<a class="pill" href="${esc(route('login'))}">Login</a>`;
    document.querySelector('#header').innerHTML = `<a class="brand" href="${esc(route('home'))}" aria-label="Nexo, inicio"><span class="brand-mark" aria-hidden="true">N</span><span>nexo</span></a><nav class="site-nav" aria-label="Navegación principal">${navLink('courses','Cursos',['courses','course','enrollment'].includes(page))}${navLink('about','Quiénes somos',page === 'about')}${navLink('learning','Cómo aprenderás',page === 'learning')}${account}</nav>`;
    document.querySelectorAll('[data-route]').forEach(a => { a.href = route(a.dataset.route); });
    document.querySelector('#logout')?.addEventListener('click', async event => {
      event.target.disabled = true;
      try { await api('logout', {}); location.assign(route('home')); }
      catch (error) { event.target.disabled = false; toast(error.message); }
    });
  }
  function requireStudent() {
    if (!state.user) {
      const target = new URL(route('login')); target.searchParams.set('next', location.pathname.split('/').pop() + location.search); location.assign(target); return false;
    }
    if (state.user.rol !== 'Alumno') { root.innerHTML = empty('Esta es el área de alumnos', 'Puedes explorar el catálogo con tu cuenta de administrador. Las inscripciones requieren una cuenta de alumno.', route('courses'), 'Explorar cursos'); return false; }
    return true;
  }
  function nextUrl() {
    const value = params.get('next');
    if (value && /^(mis-cursos|inscripcion|curso|aula)\.html(?:\?[^#]*)?$/.test(value)) return url(value);
    return route('myCourses');
  }
  function card(course) {
    return `<a class="course-card" href="curso.html?id=${Number(course.id_curso)}">${image(course)}<div class="course-body"><p class="course-category">${esc(category(course))}</p><h2 class="course-title">${esc(course.nombre)}</h2><p class="course-meta">${Number(course.duracion_horas)} horas · ${esc(money(course.precio))}</p></div><span class="card-arrow" aria-hidden="true">↗</span></a>`;
  }
  async function showCourses() {
    const courses = await api('courses');
    root.innerHTML = `<h1>Te ofrecemos los siguientes cursos:</h1><div class="toolbar"><label class="search"><span aria-hidden="true">⌕</span><input id="course-search" type="search" placeholder="Buscar un curso" aria-label="Buscar un curso"></label><span class="count" id="course-count" aria-live="polite"></span></div><div class="course-list" id="course-list"></div>`;
    const render = () => {
      const term = document.querySelector('#course-search').value.trim().normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
      const filtered = courses.filter(c => (c.nombre + ' ' + c.descripcion).normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().includes(term));
      document.querySelector('#course-count').textContent = `${filtered.length} ${filtered.length === 1 ? 'curso disponible' : 'cursos disponibles'}`;
      document.querySelector('#course-list').innerHTML = filtered.length ? filtered.map(card).join('') : empty(term ? 'No encontramos ese curso' : 'Próximamente, nuevos cursos', term ? 'Prueba con otro nombre o una palabra diferente.' : 'Vuelve pronto para conocer la oferta de cursos.', null);
    };
    document.querySelector('#course-search').addEventListener('input', render); render();
  }
  function groups(course, selected) {
    return course.grupos.map(g => {
      const spots = Math.max(0, Number(g.cupo_maximo) - Number(g.inscritos));
      return `<label class="group-option"><input type="radio" name="id_grupo" value="${Number(g.id_grupo)}" ${Number(selected) === Number(g.id_grupo) ? 'checked' : ''} ${spots ? '' : 'disabled'} required><span><strong>${esc(g.nombre)}</strong><small>${esc(date(g.fecha_inicio))} – ${esc(date(g.fecha_fin))}</small><small>${esc(g.profesor_nombre)} ${esc(g.profesor_apellidos)}</small><small class="spots">${spots ? `${spots} ${spots === 1 ? 'lugar disponible' : 'lugares disponibles'}` : 'Cupo completo'}</small></span></label>`;
    }).join('');
  }
  async function showCourse() {
    const course = await api('course', undefined, { id: params.get('id') || '' });
    document.title = `${course.nombre} | Nexo`;
    const enrolled = course.inscripcion?.estado === 'Activa';
    const available = course.grupos.some(g => Number(g.inscritos) < Number(g.cupo_maximo));
    const admin = state.user && state.user.rol !== 'Alumno';
    root.innerHTML = `${crumb(course.nombre)}<div class="detail-grid"><section><img class="detail-photo" src="${esc(url(course.imagen_url || 'assets/img/placeholder.svg'))}" alt=""><p class="course-category">${esc(category(course))}</p><h1 class="detail-title">${esc(course.nombre)}</h1><p class="lead">${esc(course.descripcion)}</p><div class="facts"><div><span>Duración</span><strong>${Number(course.duracion_horas)} horas</strong></div><div><span>Modalidad</span><strong>En línea</strong></div><div><span>Acceso</span><strong>Desde Mis cursos</strong></div></div><h2>Un paso más en tu aprendizaje</h2><p class="muted">Elige un grupo y confirma tu inscripción. Encontrarás el material disponible y la información de tu grupo en Mis cursos.</p></section><aside class="panel"><p class="eyebrow">Inscripción al curso</p><p class="price">${esc(money(course.precio))} ${Number(course.precio) ? '<small>MXN</small>' : ''}</p>${enrolled ? `${notice('Ya tienes una inscripción activa en este curso.','success')}${link('aula.html?id='+Number(course.id_curso),'Ingresar al curso','btn btn-wide')}` : `<form id="group-form"><h2>Elige tu grupo</h2>${course.grupos.length ? groups(course, '') : '<p class="muted small">Aún no hay grupos disponibles.</p>'}${admin ? notice('Solo las cuentas de alumno pueden inscribirse.') : ''}<button class="btn btn-wide" ${!available || admin ? 'disabled' : ''}>${state.user ? 'Continuar inscripción' : 'Inicia sesión para inscribirte'}</button></form>`}</aside></div>`;
    document.querySelector('#group-form')?.addEventListener('submit', event => {
      event.preventDefault(); const group = new FormData(event.target).get('id_grupo');
      const next = `inscripcion.html?id=${Number(course.id_curso)}&grupo=${encodeURIComponent(group)}`;
      if (!state.user) { const target = new URL(route('login')); target.searchParams.set('next', next); location.assign(target); }
      else location.assign(next);
    });
  }
  async function showEnrollment() {
    if (!requireStudent()) return;
    const course = await api('course', undefined, { id: params.get('id') || '' });
    if (course.inscripcion?.estado === 'Activa') { root.innerHTML = empty('Ya formas parte de este curso', 'Tu inscripción está activa. Puedes acceder al contenido desde Mis cursos.', route('myCourses'), 'Ir a Mis cursos'); return; }
    const requested = params.get('grupo');
    const selected = course.grupos.find(g => Number(g.id_grupo) === Number(requested) && Number(g.inscritos) < Number(g.cupo_maximo))?.id_grupo || course.grupos.find(g => Number(g.inscritos) < Number(g.cupo_maximo))?.id_grupo;
    root.innerHTML = `${crumb('Inscripción','curso.html?id='+Number(course.id_curso),course.nombre)}<h1>Inscríbete a tu próximo curso</h1><div class="detail-grid"><section><div class="course-card">${image(course)}<div class="course-body"><p class="course-category">${esc(category(course))}</p><h2 class="course-title">${esc(course.nombre)}</h2><p class="course-meta">${Number(course.duracion_horas)} horas · En línea</p></div></div><div class="panel" style="margin-top:25px"><h2>Tus datos de alumno</h2><dl class="summary-list"><div><dt>Nombre</dt><dd>${esc(state.user.nombre)} ${esc(state.user.apellidos)}</dd></div><div><dt>Correo</dt><dd>${esc(state.user.email)}</dd></div></dl><p class="small muted">La inscripción quedará vinculada a esta cuenta.</p></div></section><aside class="panel"><h2>Confirma tu inscripción</h2><form id="enroll-form">${groups(course,selected) || '<p class="muted">No hay grupos disponibles.</p>'}<dl class="summary-list"><div><dt>Total</dt><dd><strong>${esc(money(course.precio))}${Number(course.precio) ? ' MXN' : ''}</strong></dd></div></dl>${state.demo ? notice('Demostración académica: la inscripción y el pago se simulan. No se realiza ningún cobro.') : Number(course.precio) ? notice('Para confirmar, el pago debe estar verificado por la plataforma.') : ''}<label class="check"><input type="checkbox" name="confirm" required><span>Confirmo el curso y el grupo seleccionados.</span></label><p id="enroll-message" class="form-message" role="alert"></p><button class="btn btn-wide" ${selected ? '' : 'disabled'}>Confirmar inscripción</button></form><div style="margin-top:15px;text-align:center">${link('curso.html?id='+Number(course.id_curso),'Volver al curso')}</div></aside></div>`;
    document.querySelector('#enroll-form').addEventListener('submit', async event => {
      event.preventDefault(); const button = event.target.querySelector('button'); button.disabled = true; button.textContent = 'Confirmando…';
      try {
        await api('enroll', { id_grupo: Number(new FormData(event.target).get('id_grupo')), expected_price: String(course.precio) });
        root.innerHTML = `<section class="success-view"><div class="success-symbol" aria-hidden="true">✓</div><h1>¡Ya eres parte del curso!</h1><p class="lead">Tu inscripción a <strong>${esc(course.nombre)}</strong> se completó. Todo está listo para empezar.</p>${state.demo ? notice('Inscripción de demostración. No se realizó un cobro real.') : ''}<div class="button-row">${link('aula.html?id='+Number(course.id_curso),'Ingresar al curso','btn btn-purple')}${link(route('myCourses'),'Ver Mis cursos','btn btn-secondary')}</div></section>`;
        document.querySelector('#main').focus();
      } catch (error) { document.querySelector('#enroll-message').textContent = error.message; button.disabled = false; button.textContent = 'Confirmar inscripción'; }
    });
  }
  async function showMyCourses() {
    if (!requireStudent()) return;
    let courses = await api('my-courses'); let tab = 'Activa'; let selected;
    root.innerHTML = `<div class="page-top"><h1>Mis cursos</h1>${link(route('courses'),'Explorar cursos ↗')}</div><div class="tabs" role="tablist" aria-label="Estado de mis cursos"><button class="tab" id="active-tab" role="tab" aria-controls="my-list" aria-selected="true" data-status="Activa">Inscritos <span id="active-count"></span></button><button class="tab" id="history-tab" role="tab" aria-controls="my-list" aria-selected="false" tabindex="-1" data-status="Baja">Historial <span id="history-count"></span></button></div><div id="my-list" class="course-list" role="tabpanel" aria-labelledby="active-tab" tabindex="0"></div>`;
    const render = () => {
      document.querySelector('#active-count').textContent = courses.filter(c => c.estado === 'Activa').length;
      document.querySelector('#history-count').textContent = courses.filter(c => c.estado === 'Baja').length;
      document.querySelectorAll('.tab').forEach(button => { const active = button.dataset.status === tab; button.setAttribute('aria-selected', active); button.tabIndex = active ? 0 : -1; });
      document.querySelector('#my-list').setAttribute('aria-labelledby', tab === 'Activa' ? 'active-tab' : 'history-tab');
      const filtered = courses.filter(c => c.estado === tab);
      document.querySelector('#my-list').innerHTML = filtered.length ? filtered.map(c => `<article class="course-card">${image(c)}<div class="course-body"><p class="course-category">${esc(category(c))}</p><h2 class="course-title">${esc(c.nombre)}</h2><p class="course-meta">${esc(c.grupo_nombre)} · ${tab === 'Baja' ? 'Baja: '+esc(date(c.fecha_baja)) : Number(c.duracion_horas)+' horas'}</p></div><div class="course-actions">${tab === 'Activa' ? `${Number(c.curso_activo) && Number(c.grupo_activo) ? link('aula.html?id='+Number(c.id_curso),'Ingresar','btn btn-small') : '<span class="badge gray">No disponible</span>'}<button class="btn btn-small btn-danger" data-withdraw="${Number(c.id_inscripcion)}" aria-label="Eliminar inscripción a ${esc(c.nombre)}">Eliminar</button>` : `<span class="badge gray">Baja</span>${Number(c.curso_activo) ? link('curso.html?id='+Number(c.id_curso),'Ver curso','btn btn-small btn-secondary') : ''}`}</div></article>`).join('') : empty(tab === 'Activa' ? 'Tu próximo curso te espera' : 'Tu historial está al día', tab === 'Activa' ? 'Todavía no tienes cursos inscritos. Explora el catálogo y encuentra algo que te interese.' : 'Aquí aparecerán los cursos que decidas dar de baja.', tab === 'Activa' ? route('courses') : null, 'Explorar cursos');
    };
    const chooseTab = button => { tab = button.dataset.status; render(); };
    document.querySelectorAll('.tab').forEach(button => {
      button.addEventListener('click', () => chooseTab(button));
      button.addEventListener('keydown', event => { if (['ArrowLeft','ArrowRight','Home','End'].includes(event.key)) { event.preventDefault(); const target = document.querySelector(button.id === 'active-tab' ? '#history-tab' : '#active-tab'); chooseTab(target); target.focus(); } });
    });
    const dialog = document.querySelector('#withdraw-dialog');
    document.querySelector('#my-list').addEventListener('click', event => {
      const button = event.target.closest('[data-withdraw]'); if (!button) return;
      selected = courses.find(c => Number(c.id_inscripcion) === Number(button.dataset.withdraw));
      document.querySelector('#withdraw-description').textContent = `${selected.nombre}. ${Number(state.withdrawalFee) ? 'Costo de baja: '+money(state.withdrawalFee)+' MXN.' : 'La baja no tiene costo.'}`;
      document.querySelector('#withdraw-message').textContent = ''; dialog.showModal(); document.querySelector('#cancel-withdraw').focus();
    });
    document.querySelector('#cancel-withdraw').onclick = () => dialog.close();
    document.querySelector('#confirm-withdraw').onclick = async event => {
      const button = event.target; button.disabled = true;
      try { const result = await api('withdraw', { id_inscripcion:Number(selected.id_inscripcion) }); dialog.close(); courses = await api('my-courses'); render(); toast(result.message); document.querySelector('#active-tab').focus(); }
      catch (error) { if (dialog.open) document.querySelector('#withdraw-message').textContent = error.message; else toast(error.message); }
      finally { button.disabled = false; }
    };
    render();
  }
  async function showClassroom() {
    if (!requireStudent()) return;
    const result = await api('classroom', undefined, { id:params.get('id') || '' });
    root.innerHTML = `${crumb('Aula',route('myCourses'),'Mis cursos')}<p class="eyebrow">${esc(result.curso.grupo_nombre)}</p><h1>${esc(result.curso.nombre)}</h1>${result.demo ? notice('Material de demostración. Puedes recorrer los módulos y sus actividades.') : ''}${result.modules.length ? '<div class="classroom-grid"><nav class="module-list" id="modules" aria-label="Módulos del curso"></nav><article class="lesson" id="lesson" tabindex="-1"></article></div>' : empty('El material estará disponible pronto','Tu inscripción está activa. El equipo todavía no ha publicado el contenido de este curso.',route('myCourses'),'Volver a Mis cursos')}`;
    if (!result.modules.length) return;
    const show = index => {
      const module = result.modules[index];
      document.querySelector('#modules').innerHTML = result.modules.map((m,i) => `<button type="button" class="module-button" data-module="${i}" aria-pressed="${index === i}"><span class="small muted">${String(i+1).padStart(2,'0')} · </span>${esc(m.title)}${m.minutes ? `<small>${Number(m.minutes)} minutos de lectura</small>` : ''}</button>`).join('');
      document.querySelector('#lesson').innerHTML = `<p class="eyebrow">Módulo ${index+1} de ${result.modules.length}</p><h2>${esc(module.title)}</h2>${module.paragraphs.map(p => `<p>${esc(p)}</p>`).join('')}${module.code ? `<pre><code>${esc(module.code.replaceAll('\\n','\n'))}</code></pre>` : ''}${module.exercise ? `<section class="exercise"><h3>Ponlo en práctica</h3><p>${esc(module.exercise)}</p></section>` : ''}${index < result.modules.length-1 ? '<button class="btn btn-secondary" id="next-module">Siguiente módulo →</button>' : link(route('myCourses'),'Volver a Mis cursos','btn btn-secondary')}`;
      document.querySelector('#next-module')?.addEventListener('click', () => { show(index+1); document.querySelector('#lesson').focus(); });
    };
    document.querySelector('#modules').addEventListener('click', event => { const button = event.target.closest('[data-module]'); if (button) { show(Number(button.dataset.module)); document.querySelector('#lesson').focus(); } });
    show(0);
  }
  const passwordInput = (name = 'password', title = 'Contraseña', autocomplete = 'current-password') => `<div class="field"><label for="${name}">${title}</label><div class="password-row"><input id="${name}" name="${name}" type="password" ${autocomplete === 'new-password' ? 'minlength="10" maxlength="72"' : ''} autocomplete="${autocomplete}" required><button type="button" class="password-toggle" data-password="${name}" aria-label="Mostrar ${title.toLowerCase()}">Ver</button></div>${autocomplete === 'new-password' ? '<p class="hint">Usa al menos 10 caracteres; máximo 72 bytes.</p>' : ''}</div>`;
  function wirePasswords() { document.querySelectorAll('[data-password]').forEach(button => button.addEventListener('click', () => { const field = document.getElementById(button.dataset.password); const visible = field.type === 'password'; field.type = visible ? 'text' : 'password'; button.textContent = visible ? 'Ocultar' : 'Ver'; button.setAttribute('aria-label', `${visible ? 'Ocultar' : 'Mostrar'} contraseña`); })); }
  function wireForm(id, action, complete) {
    document.getElementById(id).addEventListener('submit', async event => {
      event.preventDefault(); const form = event.target; const data = Object.fromEntries(new FormData(form));
      const message = form.querySelector('[role=alert]'); const button = form.querySelector('[type=submit]'); message.textContent = '';
      if (data.confirm_password !== undefined && data.confirm_password !== data.password) { message.textContent = 'Las contraseñas no coinciden.'; return; }
      button.disabled = true; const label = button.textContent; button.textContent = 'Procesando…';
      try { await complete(await api(action, data)); } catch (error) { message.textContent = error.message; }
      finally { button.disabled = false; button.textContent = label; }
    });
    wirePasswords();
  }
  function showLogin() {
    root.innerHTML = `<section class="auth"><div class="auth-panel"><h1>Iniciar sesión</h1><form id="login-form"><div class="field"><label for="email">Email</label><input id="email" name="email" type="email" placeholder="ejemplo@ejemplo.com" maxlength="120" autocomplete="username" required></div>${passwordInput()}<p class="form-message" role="alert"></p><button class="btn btn-wide" type="submit">Iniciar sesión</button></form><div style="margin-top:15px">${link(route('recovery'),'¿Olvidaste tu contraseña?')}</div></div><div class="auth-bottom"><p>¿Aún no eres miembro?</p>${link(route('register')+(params.get('next') ? '?next='+encodeURIComponent(params.get('next')) : ''),'Registrarse','btn btn-wide')}</div>${state.demo ? '<div class="demo-banner"><strong>Cuenta para probar el proyecto</strong><span>alumno@nexo.test</span><br><code>NexoAlumno2026!</code><br><button class="btn btn-small btn-secondary" id="fill-demo">Usar datos de demostración</button></div>' : ''}</section>`;
    wireForm('login-form','login', async user => { location.assign(user.rol === 'Alumno' ? nextUrl() : config.routes.admin ? route('admin') : route('courses')); });
    document.querySelector('#fill-demo')?.addEventListener('click', () => { document.querySelector('#email').value='alumno@nexo.test'; document.querySelector('#password').value='NexoAlumno2026!'; });
  }
  function showRegister() {
    root.innerHTML = `<section class="auth" style="max-width:470px"><div class="auth-panel"><h1>Crea tu cuenta de alumno</h1><form id="register-form"><div class="form-grid"><div class="field"><label for="nombre">Nombre</label><input id="nombre" name="nombre" maxlength="60" autocomplete="given-name" required></div><div class="field"><label for="apellidos">Apellidos</label><input id="apellidos" name="apellidos" maxlength="80" autocomplete="family-name" required></div></div><div class="field"><label for="email">Email</label><input id="email" name="email" type="email" maxlength="120" autocomplete="email" required></div>${passwordInput('password','Contraseña','new-password')}${passwordInput('confirm_password','Confirmar contraseña','new-password')}<p class="form-message" role="alert"></p><button class="btn btn-wide" type="submit">Registrarse</button></form></div><div class="auth-bottom"><p>¿Ya tienes una cuenta?</p>${link(route('login')+(params.get('next') ? '?next='+encodeURIComponent(params.get('next')) : ''),'Iniciar sesión')}</div></section>`;
    wireForm('register-form','register', async () => location.assign(nextUrl()));
  }
  function showRecovery() {
    const token = params.get('token');
    root.innerHTML = `<section class="auth"><div class="auth-panel"><h1 class="forgot-title">${token ? 'Confirmar nueva contraseña' : 'Recuperar contraseña'}</h1><p class="small muted">${token ? 'Escribe tu nueva contraseña para recuperar el acceso.' : 'Ingresa el correo asociado a tu cuenta. Te enviaremos un enlace para cambiar tu contraseña.'}</p><form id="recovery-form">${token ? `<input type="hidden" name="token" value="${esc(token)}">${passwordInput('password','Nueva contraseña','new-password')}${passwordInput('confirm_password','Confirmar nueva contraseña','new-password')}` : '<div class="field"><label for="email">Email</label><input id="email" name="email" type="email" maxlength="120" autocomplete="email" required></div>'}<p class="form-message" role="alert"></p><button class="btn btn-wide" type="submit">${token ? 'Actualizar contraseña' : 'Enviar enlace'}</button></form><div id="recovery-result" role="status"></div><div style="margin-top:18px">${link(route('login'),'Cancelar')}</div></div></section>`;
    wireForm('recovery-form',token ? 'reset' : 'forgot', async result => {
      document.querySelector('#recovery-result').innerHTML = `<div style="margin-top:18px">${notice(result.message,'success')}${result.demo_url ? `<div class="demo-banner"><strong>Demostración sin envío de correo</strong>${link(result.demo_url,'Abrir enlace de recuperación')}</div>` : token ? link(route('login'),'Ir a iniciar sesión','btn btn-wide') : ''}</div>`;
      if (token) { document.querySelector('#recovery-form').hidden=true; history.replaceState(null,'','recuperar.html'); }
    });
  }
  async function showHome() {
    const courses = await api('courses'); const featured = courses.filter(c => Number(c.destacado)).slice(0,3);
    const subtitles = ['Ideas que cobran vida','Habilidades para el futuro','Lidera tu próximo reto'];
    root.innerHTML = `<section class="hero"><h1>Cursos para avanzar, a cualquier edad.</h1><div class="feature-grid">${featured.map((c,i) => `<a class="feature-card" href="curso.html?id=${Number(c.id_curso)}"><img src="${esc(url(c.imagen_url || 'assets/img/placeholder.svg'))}" alt="" width="400" height="230"><div class="feature-copy"><p>${esc(category(c))}</p><h2>${esc(subtitles[i] || c.nombre)} <span aria-hidden="true">↗</span></h2><span class="small muted">${esc(c.nombre)}</span></div></a>`).join('')}</div><div style="margin-top:32px">${link(route('courses'),'Conoce todos los cursos →')}</div></section>`;
  }
  function showAbout() {
    root.innerHTML = `<section class="prose"><h1>Nosotros:</h1><p><strong>Nexo</strong> nació en Monterrey, Nuevo León, a partir de un grupo de universitarios con la <strong class="purple">inquietud de aprender y prepararse</strong> para enfrentar los retos del mundo profesional.</p><p>Nuestra misión es ofrecer cursos accesibles, prácticos y especializados para estudiantes, profesionales y cualquier persona interesada en adquirir conocimientos útiles para el ámbito laboral.</p><p>Buscamos conectar a las personas con nuevas oportunidades de aprendizaje y crecimiento, acercando la educación a las áreas que demanda la industria actual.</p><p>Creemos que <strong class="purple">aprender no termina al graduarse</strong>; por eso construimos una plataforma donde la curiosidad, la especialización y el desarrollo profesional estén al alcance de todos.</p><h2>¿Quieres formar parte de Nexo?</h2><p>Si te apasiona la educación, la tecnología o compartir conocimientos, conoce nuestros cursos y encuentra tu siguiente oportunidad para aprender.</p><div class="contact"><h2>Contacto:</h2><p>Monterrey, Nuevo León, México</p><p class="small muted">Los datos de contacto oficiales serán proporcionados por el equipo de Nexo.</p></div></section>`;
  }
  function showLearning() {
    root.innerHTML = `<section class="prose"><h1>Así funciona:</h1><ol class="steps">${['Regístrate en la plataforma','Busca tu curso','Inscríbete con tus datos','Realiza módulos y actividades','Concluye tu recorrido de aprendizaje'].map((s,i) => `<li><span class="step-number" aria-hidden="true">${i+1}</span><p>${esc(s)}</p></li>`).join('')}</ol><p class="small muted">La emisión de certificados se integrará con el módulo del equipo responsable.</p>${link(route('courses'),'Encuentra tu próximo curso','btn')}</section>`;
  }
  document.addEventListener('error', event => { if (event.target.tagName === 'IMG' && !event.target.src.endsWith('placeholder.svg')) event.target.src = 'assets/img/placeholder.svg'; }, true);
  document.addEventListener('click', event => { if (!event.target.closest('.account')) document.querySelector('.account')?.removeAttribute('open'); });
  async function init() {
    try {
      const session = await api('session'); Object.assign(state,session); nav();
      const renderers = { home:showHome, courses:showCourses, course:showCourse, enrollment:showEnrollment, 'my-courses':showMyCourses, classroom:showClassroom, login:showLogin, register:showRegister, recovery:showRecovery, about:showAbout, learning:showLearning };
      await renderers[page]();
    } catch (error) { fail(error); }
  }
  init();
})();
