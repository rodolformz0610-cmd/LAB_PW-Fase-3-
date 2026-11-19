// Ejecutar con un servidor de DEMOSTRACION activo. No modifica inscripciones.
import assert from 'node:assert/strict';
import http from 'node:http';
import https from 'node:https';
const fetch = (input, options={}) => new Promise((resolve,reject) => {
  const endpoint = new URL(input);
  const request = (endpoint.protocol === 'https:' ? https : http).request(endpoint, {method: options.method || 'GET',headers:options.headers}, response => {
    const chunks=[]; response.on('data',chunk=>chunks.push(chunk));
    response.on('end',()=>{ const text=Buffer.concat(chunks).toString(); resolve({status:response.statusCode,headers:{get:key=>Array.isArray(response.headers[key]) ? response.headers[key].join(',') : response.headers[key]},json:async()=>JSON.parse(text)}); });
  });
  request.on('error',reject); if (options.body) request.write(options.body); request.end();
});
const base = process.argv[2] || 'http://127.0.0.1:8765/';
const session = await fetch(new URL('api/index.php?action=session',base));
assert.equal(session.status,200);
const initial = await session.json();
assert.equal(initial.data.demo,true,'Esta prueba solo debe ejecutarse en demostracion.');
const cookie = session.headers.get('set-cookie').split(';')[0];
const call = (action, options={}) => fetch(new URL(`api/index.php?action=${action}`,base),{...options,headers:{cookie,...options.headers}});
assert.equal((await call('my-courses')).status,401,'Privacidad sin sesion');
assert.equal((await call('enroll',{method:'POST',headers:{'Content-Type':'application/json'},body:'{"id_grupo":1}'})).status,419,'CSRF requerido');
assert.equal((await call('withdraw')).status,405,'Las escrituras no usan GET');
assert.equal((await call('not-found')).status,404,'Rutas desconocidas');
const courses = await (await call('courses')).json();
assert.ok(courses.data.length>0);
assert.ok(courses.data.every(c=>!('password_hash' in c)));
for (const file of ['index.html','cursos.html','curso.html','inscripcion.html','mis-cursos.html','aula.html','login.html','registro.html','recuperar.html','nosotros.html','como-aprenderas.html','assets/css/styles.css','assets/js/app.js','assets/img/liderazgo.jpg']) {
  assert.equal((await fetch(new URL(file,base))).status,200,file);
}
for (const file of ['.env','src/bootstrap.php','config/integrations.php','storage/demo.sqlite','database/001_mysql.sql']) {
  assert.equal((await fetch(new URL(file,base))).status,404,`Privado: ${file}`);
}
console.log('HTTP correcto: CSRF, permisos, metodos, rutas, 11 paginas, recursos y archivos privados.');
