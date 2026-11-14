-- Ejecutar con: mysql -u root < sql/schema.sql
CREATE DATABASE IF NOT EXISTS cabanas CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE cabanas;

CREATE TABLE cabanas (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(100) NOT NULL,
  capacidad INT NOT NULL CHECK (capacidad > 0),
  precio_noche INT NOT NULL CHECK (precio_noche >= 0), -- CLP
  activa BOOLEAN NOT NULL DEFAULT TRUE
) ENGINE=InnoDB;

CREATE TABLE reservas (
  id INT AUTO_INCREMENT PRIMARY KEY,
  cabana_id INT NOT NULL,
  nombre VARCHAR(100) NOT NULL,
  ciudad VARCHAR(100) NOT NULL,
  telefono VARCHAR(30) NOT NULL,
  telefono2 VARCHAR(30) NULL, -- opcional
  email VARCHAR(254) NOT NULL,
  email2 VARCHAR(254) NULL, -- opcional
  fecha_entrada DATE NOT NULL,
  fecha_salida DATE NOT NULL, -- no cuenta como noche
  personas INT NOT NULL CHECK (personas > 0),
  monto INT NOT NULL, -- CLP, total de la estadía, lo calcula el servidor
  abono INT NOT NULL, -- mínimo a transferir en 48 h (porcentaje de config.php), no reembolsable
  pagado INT NULL, -- lo que transfirió: el abono o el total; el resto se paga al llegar
  estado ENUM('pendiente','aceptada','rechazada','pagada','cancelada') NOT NULL DEFAULT 'pendiente', -- 'pagada' y 'cancelada' ya no se usan
  creada_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  aceptada_en DATETIME NULL, -- desde aquí corren las 48 h para pagar
  recordatorio_en DATETIME NULL, -- cuándo se envió el recordatorio de pago (a las 24 h); NULL = aún no
  FOREIGN KEY (cabana_id) REFERENCES cabanas(id),
  CHECK (fecha_salida > fecha_entrada),
  INDEX (cabana_id, estado, fecha_entrada)
) ENGINE=InnoDB;

-- Días cerrados por el admin (mantenimiento). fecha_fin también queda cerrado.
CREATE TABLE bloqueos (
  id INT AUTO_INCREMENT PRIMARY KEY,
  cabana_id INT NOT NULL,
  fecha_inicio DATE NOT NULL,
  fecha_fin DATE NOT NULL,
  motivo VARCHAR(200) NULL,
  creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (cabana_id) REFERENCES cabanas(id),
  CHECK (fecha_fin >= fecha_inicio),
  INDEX (cabana_id, fecha_inicio)
) ENGINE=InnoDB;

CREATE TABLE admins (
  id INT AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(254) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL
) ENGINE=InnoDB;

-- Cabañas: se agregan con INSERT, sin cambiar código.
INSERT INTO cabanas (nombre, capacidad, precio_noche) VALUES ('Cabaña 1', 6, 80000);
-- Para sumar la segunda más adelante:
-- INSERT INTO cabanas (nombre, capacidad, precio_noche) VALUES ('Cabaña 2', 6, 80000);
