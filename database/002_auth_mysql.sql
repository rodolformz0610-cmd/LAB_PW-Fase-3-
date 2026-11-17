-- Extension opcional para el login incluido. No forma parte del modelo original.
CREATE TABLE password_reset_tokens (
 token_hash CHAR(64) PRIMARY KEY,
 id_usuario INTEGER NOT NULL,
 expires_at DATETIME NOT NULL,
 used_at DATETIME,
 FOREIGN KEY(id_usuario) REFERENCES usuarios(id_usuario) ON DELETE CASCADE
) ENGINE=InnoDB;
CREATE INDEX idx_reset_usuario ON password_reset_tokens(id_usuario);
