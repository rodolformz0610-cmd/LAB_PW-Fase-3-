-- Extension opcional para el login incluido. No forma parte del modelo original.
CREATE TABLE password_reset_tokens (
 token_hash CHAR(64) PRIMARY KEY,
 id_usuario INTEGER NOT NULL,
 expires_at TIMESTAMP NOT NULL,
 used_at TIMESTAMP,
 FOREIGN KEY(id_usuario) REFERENCES usuarios(id_usuario) ON DELETE CASCADE
);
CREATE INDEX idx_reset_usuario ON password_reset_tokens(id_usuario);
ALTER TABLE password_reset_tokens ENABLE ROW LEVEL SECURITY;
