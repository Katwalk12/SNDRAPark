-- Reason shown to drivers when a floor or slot is taken out of service.
--
-- The admin must supply one when deactivating, and it is cleared automatically
-- when the floor or slot is brought back. A slot with no reason of its own
-- falls back to its floor's reason, so deactivating a whole floor explains
-- every slot on it without touching them individually.

ALTER TABLE parking_floors
    ADD COLUMN unavailable_reason VARCHAR(255) NULL DEFAULT NULL AFTER is_active;

ALTER TABLE parking_slots
    ADD COLUMN unavailable_reason VARCHAR(255) NULL DEFAULT NULL AFTER manual_status;
