-- Reverts migration_missed_arrival.sql (missed arrival feature removed).
-- Run ONCE, only if migration_missed_arrival.sql was already run.
-- Afterwards delete migration_missed_arrival.sql from the project.

-- Rows that were flagged "missed" go back to their previous state:
-- unreviewed requests -> expired, staff-approved ones -> overdue.
UPDATE outing_request
   SET status = IF(unreviewed_by_staff = 1, 'expired', 'overdue')
 WHERE status = 'missed';

DELETE FROM outing_event WHERE event_type IN ('missed_arrival', 'missed_decided');
DELETE FROM notification WHERE type IN ('missed_arrival', 'missed_waived');

ALTER TABLE outing_request
    DROP INDEX idx_request_missed,
    DROP COLUMN missed_note,
    DROP COLUMN missed_decided_at,
    DROP COLUMN missed_decided_by,
    DROP COLUMN missed_review,
    MODIFY status ENUM('pending','approved','rejected','checked_out',
                       'checked_in','overdue','cancelled','expired')
        NOT NULL DEFAULT 'pending';

ALTER TABLE outing_event
    MODIFY event_type ENUM('submitted','approved','rejected','checked_out',
                           'checked_in','overdue_flagged','cancelled','expired')
        NOT NULL;
