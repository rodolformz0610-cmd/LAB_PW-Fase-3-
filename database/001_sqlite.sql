-- Nexo | Modelo de la fase 2. Seis tablas, con los nombres del documento.
-- No ejecutar sobre las tablas existentes del equipo sin revisar sus migraciones.
PRAGMA foreign_keys=ON;

CREATE TABLE usuarios (
 id_usuario INTEGER PRIMARY KEY AUTOINCREMENT,
 nombre VARCHAR(60) NOT NULL,
 apellidos VARCHAR(80) NOT NULL,
 email VARCHAR(120) NOT NULL UNIQUE,
 password_hash VARCHAR(255) NOT NULL,
 rol TEXT CHECK (rol IN ('Alumno','Administrador')) NOT NULL DEFAULT 'Alumno',
 fecha_registro DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 activo SMALLINT NOT NULL DEFAULT 1 CHECK (activo IN (0,1))
);
CREATE TABLE cursos (
 id_curso INTEGER PRIMARY KEY AUTOINCREMENT,
 id_admin INTEGER NOT NULL REFERENCES usuarios(id_usuario),
 nombre VARCHAR(100) NOT NULL,
 descripcion TEXT NOT NULL,
 duracion_horas INTEGER NOT NULL CHECK (duracion_horas>0),
 precio DECIMAL(10,2) NOT NULL CHECK (precio>=0),
 imagen_url VARCHAR(255),
 destacado SMALLINT NOT NULL DEFAULT 0 CHECK (destacado IN (0,1)),
 activo SMALLINT NOT NULL DEFAULT 1 CHECK (activo IN (0,1)),
 fecha_creacion DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE grupos (
 id_grupo INTEGER PRIMARY KEY AUTOINCREMENT,
 id_curso INTEGER NOT NULL,
 id_profesor INTEGER NOT NULL REFERENCES usuarios(id_usuario),
 nombre VARCHAR(50) NOT NULL,
 cupo_maximo INTEGER NOT NULL CHECK (cupo_maximo>0),
 fecha_inicio DATE NOT NULL,
 fecha_fin DATE NOT NULL,
 activo SMALLINT NOT NULL DEFAULT 1 CHECK (activo IN (0,1)),
 CONSTRAINT fk_grupo_curso FOREIGN KEY(id_curso) REFERENCES cursos(id_curso) ON DELETE CASCADE,
 CONSTRAINT uq_grupo_curso UNIQUE(id_grupo,id_curso),
 CONSTRAINT ck_grupo_fechas CHECK(fecha_fin>=fecha_inicio)
);
CREATE TABLE inscripciones (
 id_inscripcion INTEGER PRIMARY KEY AUTOINCREMENT,
 id_usuario INTEGER NOT NULL REFERENCES usuarios(id_usuario),
 id_grupo INTEGER NOT NULL,
 id_curso INTEGER NOT NULL,
 fecha_inscripcion DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 estado TEXT CHECK (estado IN ('Activa','Baja')) NOT NULL DEFAULT 'Activa',
 fecha_baja DATETIME,
 CONSTRAINT uq_usuario_curso UNIQUE(id_usuario,id_curso),
 CONSTRAINT fk_inscripcion_grupo_curso FOREIGN KEY(id_grupo,id_curso) REFERENCES grupos(id_grupo,id_curso),
 CONSTRAINT ck_fecha_baja CHECK ((estado='Activa' AND fecha_baja IS NULL) OR (estado='Baja' AND fecha_baja IS NOT NULL))
);
CREATE TABLE pagos (
 id_pago INTEGER PRIMARY KEY AUTOINCREMENT,
 id_usuario INTEGER NOT NULL REFERENCES usuarios(id_usuario),
 id_inscripcion INTEGER NOT NULL REFERENCES inscripciones(id_inscripcion),
 tipo TEXT CHECK (tipo IN ('Inscripcion','Baja')) NOT NULL,
 monto DECIMAL(10,2) NOT NULL CHECK(monto>=0),
 fecha_pago DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE gastos (
 id_gasto INTEGER PRIMARY KEY AUTOINCREMENT,
 id_admin INTEGER NOT NULL REFERENCES usuarios(id_usuario),
 id_curso INTEGER,
 concepto VARCHAR(150) NOT NULL,
 monto DECIMAL(10,2) NOT NULL CHECK(monto>=0),
 fecha_gasto DATE NOT NULL DEFAULT (CURRENT_DATE),
 CONSTRAINT fk_gasto_curso FOREIGN KEY(id_curso) REFERENCES cursos(id_curso) ON DELETE SET NULL
);
CREATE INDEX idx_inscripciones_fecha ON inscripciones(fecha_inscripcion);
CREATE INDEX idx_inscripciones_grupo_estado ON inscripciones(id_grupo,estado);
CREATE INDEX idx_pagos_fecha ON pagos(fecha_pago);
CREATE INDEX idx_gastos_fecha ON gastos(fecha_gasto);
CREATE INDEX idx_cursos_fecha ON cursos(fecha_creacion);
