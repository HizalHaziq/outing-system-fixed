# Inspection and migration map

Baseline: ea54541. The original 45 public PHP files, 23 private PHP modules, README, both SQL trees and incremental patches were inspected before implementation. Original source is retained in Git history. Application CSS is assets/app.css (custom Metronic-compatible selectors); login uses login.css, particles.min.js and login-particles.js. The bundled theme assets, images, fonts and icons are reused, without a starter kit or frontend framework.

| Audience | Workflows and permissions |
|---|---|
| Student | Own dashboard, rotating QR, own requests (create/edit/cancel pending), own history, group invitations and membership, own photo/profile/password, own notifications |
| Guard | Gate scans and movement actions only; standard history, current outings, dashboard, profile/password; no review actions or special workflow reports |
| Warden | Approve/reject pending requests, request status and special history, violations, current outings, dashboard; no gate writes |
| Admin | Reports and request visibility, CSV import, private photos, staff accounts and settings; no approval or gate writes |

Student and staff use separate tables, natural login identifiers and password_hash. All identifiers and hashes are retained. standard_outing and outing_request reference student.std_no; reviewer/gate actors reference staff.staff_id. outing_event is the special audit trail. An arrival request may be linked from standard_outing.arrival_request_id and must not appear as a second outing in history. Group membership, movement log, trip links and scan audit remain separate records. Photos are associated by student number, outside the web root.

Special states: pending -> approved/rejected/cancelled; approved checkout -> checked_out -> overdue/checked_in; approved arrival -> overdue/checked_in. Pending arrival -> expired when max(arrival minus review lead, submission plus review lead) passes. Unreviewed arrival is covered only in its requested curfew window, then recorded with unreviewed_by_staff. Standard outing: open (checked_in_at null) -> returned, with immutable late-return flag. Returns are always recordable by an active guard, including for an inactive student. Groups support standard trips only; invitations need acceptance, max four invitees, 24-hour departure lifetime, auto-close after every departed person returns.

Verified defects: consolidated schemas omit coming_from, unreviewed_by_staff, arrival_request_id, expired enums and nullable destination. db.php never loads env_loader and defaults to root/empty password. Standard inserts lack exclusion or transaction; special departures do not exclude open standard trips. Active special lookup excludes overdue. Gate write functions do not enforce student activation. Group batches commit per member. Notification dedupe is SELECT then INSERT and races. Profile/photo endpoints skip full session/account checks. Dashboard special-out count includes overdue arrival permissions. QR verification is scoped to subject only and can outlive the state scanned. Unreviewed arrival coverage has no lower window bound and could excuse an earlier unrelated curfew. Staff password reset does not force a change. Settings write partially on validation failure. Import accepts malformed short ICs with a shared password fallback.

Time policy: Asia/Kuala_Lumpur for application and MySQL session (+08:00); existing DATETIME values remain local. Curfew start inclusive, end exclusive, including midnight wrap. No approval or QR permits bypassing a fresh server-side decision.
