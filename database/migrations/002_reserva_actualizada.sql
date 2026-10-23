-- Existing installations only. Add the event type without changing existing data.
ALTER TABLE reserva_evento MODIFY tipo ENUM('creada', 'confirmada', 'cancelada', 'actualizada') NOT NULL;
