-- Links a standard outing to the arrival permission (checkin request)
-- that was used when the student came back, so history can show one
-- outing instead of two rows.
ALTER TABLE standard_outing
    ADD COLUMN arrival_request_id INT NULL AFTER is_late_return;

-- Backfill: an arrival permission closed by a standard check-in has
-- actual_return_at within a few seconds of that check-in.
UPDATE standard_outing so
JOIN outing_request r
  ON r.std_no = so.std_no
 AND r.type = 'checkin'
 AND r.status = 'checked_in'
 AND ABS(TIMESTAMPDIFF(SECOND, r.actual_return_at, so.checked_in_at)) <= 5
SET so.arrival_request_id = r.id
WHERE so.checked_in_at IS NOT NULL
  AND so.arrival_request_id IS NULL;
