-- MySQL 8.0.16+ / MariaDB 10.6+. Import into an EMPTY database.
-- All example identities and emails are fictional. Dates are relative to import.
SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE reserva (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    nombre_huesped VARCHAR(120) NOT NULL,
    email_huesped VARCHAR(254) NOT NULL,
    nombre_alojamiento VARCHAR(160) NOT NULL,
    fecha_entrada DATE NOT NULL,
    fecha_salida DATE NOT NULL,
    estado ENUM('pendiente', 'confirmada', 'cancelada') NOT NULL DEFAULT 'pendiente',
    importe DECIMAL(10,2) NOT NULL,
    notas TEXT NOT NULL,
    fecha_creacion DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    version INT UNSIGNED NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    CONSTRAINT chk_reserva_fechas CHECK (fecha_salida > fecha_entrada),
    CONSTRAINT chk_reserva_importe CHECK (importe >= 0),
    CONSTRAINT chk_reserva_version CHECK (version >= 1),
    INDEX idx_reserva_creacion (fecha_creacion, id),
    INDEX idx_reserva_estado_creacion (estado, fecha_creacion, id),
    INDEX idx_reserva_entrada (fecha_entrada),
    INDEX idx_reserva_salida (fecha_salida)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE reserva_evento (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    reserva_id INT UNSIGNED NOT NULL,
    tipo ENUM('creada', 'confirmada', 'cancelada') NOT NULL,
    descripcion VARCHAR(255) NOT NULL,
    fecha_creacion DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    CONSTRAINT fk_evento_reserva FOREIGN KEY (reserva_id) REFERENCES reserva(id) ON DELETE RESTRICT,
    INDEX idx_evento_reserva_fecha (reserva_id, fecha_creacion, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

START TRANSACTION;
INSERT INTO reserva (id, nombre_huesped, email_huesped, nombre_alojamiento, fecha_entrada, fecha_salida, estado, importe, notas, fecha_creacion, version) VALUES
(1, 'Lucía Martín', 'lucia.martin@example.test', 'Casa del Olivo · Ronda', CURRENT_DATE + INTERVAL 2 DAY, CURRENT_DATE + INTERVAL 5 DAY, 'confirmada', 345.00, 'Llegada a partir de las 17:00. Solicita cuna.', UTC_TIMESTAMP - INTERVAL 2 HOUR, 2),
(2, 'Pablo Navarro', 'pablo.navarro@example.test', 'La Casona del Norte · Llanes', CURRENT_DATE + INTERVAL 7 DAY, CURRENT_DATE + INTERVAL 11 DAY, 'pendiente', 520.00, 'Viaja con un perro pequeño.', UTC_TIMESTAMP - INTERVAL 3 HOUR, 1),
(3, 'Elena García', 'elena.garcia@example.test', 'Patio de la Luz · Córdoba', CURRENT_DATE + INTERVAL 1 DAY, CURRENT_DATE + INTERVAL 3 DAY, 'confirmada', 198.50, '', UTC_TIMESTAMP - INTERVAL 5 HOUR, 2),
(4, 'Marcos Ruiz', 'marcos.ruiz@example.test', 'Brisa Atlántica · Cádiz', CURRENT_DATE + INTERVAL 14 DAY, CURRENT_DATE + INTERVAL 19 DAY, 'cancelada', 675.00, 'Cancelación solicitada por el huésped.', UTC_TIMESTAMP - INTERVAL 1 DAY, 3),
(5, 'Sara López', 'sara.lopez@example.test', 'Refugio de Aínsa · Huesca', CURRENT_DATE + INTERVAL 4 DAY, CURRENT_DATE + INTERVAL 7 DAY, 'pendiente', 285.00, 'Consultar disponibilidad de aparcamiento.', UTC_TIMESTAMP - INTERVAL 2 DAY, 1),
(6, 'Hugo Fernández', 'hugo.fernandez@example.test', 'Casa del Olivo · Ronda', CURRENT_DATE + INTERVAL 9 DAY, CURRENT_DATE + INTERVAL 12 DAY, 'confirmada', 360.00, 'Habitación tranquila, si es posible.', UTC_TIMESTAMP - INTERVAL 3 DAY, 2),
(7, 'Nora Sánchez', 'nora.sanchez@example.test', 'El Mirador · Granada', CURRENT_DATE - INTERVAL 3 DAY, CURRENT_DATE + INTERVAL 2 DAY, 'confirmada', 625.00, 'Estancia en curso.', UTC_TIMESTAMP - INTERVAL 4 DAY, 2),
(8, 'Diego Romero', 'diego.romero@example.test', 'La Casona del Norte · Llanes', CURRENT_DATE + INTERVAL 20 DAY, CURRENT_DATE + INTERVAL 22 DAY, 'pendiente', 250.00, '', UTC_TIMESTAMP - INTERVAL 5 DAY, 1),
(9, 'Inés Molina', 'ines.molina@example.test', 'Patio de la Luz · Córdoba', CURRENT_DATE - INTERVAL 10 DAY, CURRENT_DATE - INTERVAL 7 DAY, 'confirmada', 297.75, 'Estancia finalizada.', UTC_TIMESTAMP - INTERVAL 12 DAY, 2),
(10, 'Álvaro Díaz', 'alvaro.diaz@example.test', 'Brisa Atlántica · Cádiz', CURRENT_DATE + INTERVAL 6 DAY, CURRENT_DATE + INTERVAL 8 DAY, 'cancelada', 270.00, '', UTC_TIMESTAMP - INTERVAL 6 DAY, 2),
(11, 'Clara Torres', 'clara.torres@example.test', 'Refugio de Aínsa · Huesca', CURRENT_DATE + INTERVAL 15 DAY, CURRENT_DATE + INTERVAL 18 DAY, 'confirmada', 315.00, 'Desayuno sin gluten. Confirmar con el alojamiento.', UTC_TIMESTAMP - INTERVAL 7 DAY, 2),
(12, 'Mateo Ortega', 'mateo.ortega@example.test', 'El Mirador · Granada', CURRENT_DATE + INTERVAL 23 DAY, CURRENT_DATE + INTERVAL 27 DAY, 'pendiente', 500.00, '', UTC_TIMESTAMP - INTERVAL 8 DAY, 1),
(13, 'Valeria Santos', 'valeria.santos@example.test', 'Casa del Olivo · Ronda', CURRENT_DATE + INTERVAL 30 DAY, CURRENT_DATE + INTERVAL 32 DAY, 'confirmada', 240.00, 'Dos adultos.', UTC_TIMESTAMP - INTERVAL 9 DAY, 2),
(14, 'Leo Castro', 'leo.castro@example.test', 'Patio de la Luz · Córdoba', CURRENT_DATE + INTERVAL 11 DAY, CURRENT_DATE + INTERVAL 12 DAY, 'pendiente', 99.00, 'Estancia de una noche.', UTC_TIMESTAMP - INTERVAL 10 DAY, 1),
(15, 'Julia Vega', 'julia.vega@example.test', 'Brisa Atlántica · Cádiz', CURRENT_DATE + INTERVAL 40 DAY, CURRENT_DATE + INTERVAL 47 DAY, 'confirmada', 945.00, 'Llegada en tren.', UTC_TIMESTAMP - INTERVAL 11 DAY, 2),
(16, 'Adrián Gil', 'adrian.gil@example.test', 'La Casona del Norte · Llanes', CURRENT_DATE + INTERVAL 3 DAY, CURRENT_DATE + INTERVAL 5 DAY, 'cancelada', 260.00, 'Cambio de planes.', UTC_TIMESTAMP - INTERVAL 13 DAY, 2);

INSERT INTO reserva_evento (reserva_id, tipo, descripcion, fecha_creacion)
SELECT id, 'creada', 'Reserva creada con estado pendiente.', fecha_creacion FROM reserva;
INSERT INTO reserva_evento (reserva_id, tipo, descripcion, fecha_creacion)
SELECT id, 'confirmada', 'Estado cambiado de pendiente a confirmada.', fecha_creacion + INTERVAL 15 MINUTE
FROM reserva WHERE estado = 'confirmada' OR id = 4;
INSERT INTO reserva_evento (reserva_id, tipo, descripcion, fecha_creacion)
SELECT id, 'cancelada', CASE WHEN id = 4 THEN 'Estado cambiado de confirmada a cancelada.' ELSE 'Estado cambiado de pendiente a cancelada.' END,
fecha_creacion + INTERVAL 30 MINUTE FROM reserva WHERE estado = 'cancelada';
COMMIT;
