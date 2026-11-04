-- Check-in (arrival) requests now store "coming from" in its own column
-- instead of reusing `destination`. Checkout requests keep `destination`.
ALTER TABLE outing_request
    MODIFY destination VARCHAR(255) NULL,
    ADD COLUMN coming_from VARCHAR(255) NULL AFTER destination;

-- Move existing check-in values over.
UPDATE outing_request
   SET coming_from = destination, destination = NULL
 WHERE type = 'checkin';
