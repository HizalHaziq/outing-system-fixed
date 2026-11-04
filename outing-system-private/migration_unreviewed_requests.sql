-- Fair handling of arrival (checkin) requests staff never reviewed in time.
-- Run ONCE on an existing database. Existing rows are unchanged
-- (unreviewed_by_staff defaults to 0).

ALTER TABLE outing_request
    MODIFY status ENUM('pending','approved','rejected','checked_out',
                       'checked_in','overdue','cancelled','expired')
        NOT NULL DEFAULT 'pending',
    ADD COLUMN unreviewed_by_staff TINYINT(1) NOT NULL DEFAULT 0 AFTER rejection_reason;

ALTER TABLE outing_event
    MODIFY event_type ENUM('submitted','approved','rejected','checked_out',
                           'checked_in','overdue_flagged','cancelled','expired')
        NOT NULL;
