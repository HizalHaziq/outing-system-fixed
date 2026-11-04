-- Canonical non-destructive reference schema; prefer php artisan migrate for clean installs AND upgrades.
-- Select the database explicitly with your database client. Does not create or drop a database.
SET NAMES utf8mb4;
SET time_zone = '+08:00';
CREATE TABLE IF NOT EXISTS student (
    id                       INT AUTO_INCREMENT PRIMARY KEY,
    std_no                   VARCHAR(20)  NOT NULL UNIQUE,
    std_name                 VARCHAR(100) NOT NULL,
    ic_no                    VARCHAR(20)  NOT NULL,
    program                  VARCHAR(100),
    email                    VARCHAR(100),
    phone                    VARCHAR(20),
    emergency_contact_name   VARCHAR(100),
    emergency_contact_phone  VARCHAR(20),
    password_hash            VARCHAR(255) NOT NULL,
    is_active                TINYINT(1)   NOT NULL DEFAULT 1,
    created_at               TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    must_change_password     TINYINT(1)   NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------------
-- staff (role-based: admin = view/report only, warden = approves,
-- guard = gate). must_change_password defaults to 0.
-- ------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS staff (
    id                   INT AUTO_INCREMENT PRIMARY KEY,
    staff_id             VARCHAR(20)  NOT NULL UNIQUE,
    name                 VARCHAR(100) NOT NULL,
    email                VARCHAR(100),
    role                 ENUM('admin','warden','guard') NOT NULL,
    password_hash        VARCHAR(255) NOT NULL,
    is_active            TINYINT(1)   NOT NULL DEFAULT 1,
    created_at           TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    must_change_password TINYINT(1)   NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------------
-- app_setting: DB-backed runtime settings (see settings.php)
-- ------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS app_setting (
    setting_key   VARCHAR(64)  NOT NULL PRIMARY KEY,
    setting_value VARCHAR(255) NOT NULL,
    updated_by    VARCHAR(20)  NULL,
    updated_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO app_setting (setting_key, setting_value) VALUES
    ('curfew_start', '23:00:00'),
    ('curfew_end', '06:00:00'),
    ('special_tolerance_minutes', '15'),
    ('arrival_review_lead_minutes', '30'),
    ('qr_refresh_seconds', '30'),
    ('allow_manual_gate_entry', '1');

-- ------------------------------------------------------------------
-- outing_request: SPECIAL/CURFEW track (approval gated).
--   type 'checkout' = permission to leave during curfew (guard checks
--                     out, later closes the same request on return)
--   type 'checkin'  = pre-cleared arrival during curfew
-- ------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS outing_request (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    std_no              VARCHAR(20) NOT NULL,
    type                ENUM('checkout','checkin') NOT NULL DEFAULT 'checkout',
    reason              VARCHAR(255) NOT NULL,
    destination         VARCHAR(255) NULL,
    coming_from VARCHAR(255) NULL,
    unreviewed_by_staff TINYINT(1) NOT NULL DEFAULT 0,
    requested_out_at    DATETIME NULL,
    expected_return_at  DATETIME NULL,
    status              ENUM('pending','approved','rejected','checked_out',
                              'checked_in','overdue','cancelled','expired')
                         NOT NULL DEFAULT 'pending',
    reviewed_by         VARCHAR(20) NULL,
    reviewed_at         DATETIME NULL,
    rejection_reason    VARCHAR(255) NULL,
    actual_out_at       DATETIME NULL,
    actual_return_at    DATETIME NULL,
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_request_student FOREIGN KEY (std_no)
        REFERENCES student(std_no),
    CONSTRAINT fk_request_reviewer FOREIGN KEY (reviewed_by)
        REFERENCES staff(staff_id),
    INDEX idx_request_stdno (std_no),
    INDEX idx_request_status (status),
    INDEX idx_request_type (type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------------
-- standard_outing: STANDARD/QR track (no approval workflow)
-- ------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS standard_outing (
    id                INT AUTO_INCREMENT PRIMARY KEY,
    std_no            VARCHAR(20) NOT NULL,
    checked_out_at    DATETIME NOT NULL,
    checked_out_by    VARCHAR(20) NOT NULL,
    gate_location_out VARCHAR(100) NULL,
    checked_in_at     DATETIME NULL,
    checked_in_by     VARCHAR(20) NULL,
    gate_location_in  VARCHAR(100) NULL,
    is_late_return    TINYINT(1) NOT NULL DEFAULT 0,
    arrival_request_id INT NULL,
    created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_standard_student FOREIGN KEY (std_no)
        REFERENCES student(std_no),
    CONSTRAINT fk_standard_out_staff FOREIGN KEY (checked_out_by)
        REFERENCES staff(staff_id),
    CONSTRAINT fk_standard_in_staff FOREIGN KEY (checked_in_by)
        REFERENCES staff(staff_id),
    INDEX idx_standard_stdno (std_no),
    INDEX idx_standard_open (std_no, checked_in_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------------
-- outing_event: audit trail for the special track
-- ------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS outing_event (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    request_id     INT NOT NULL,
    event_type     ENUM('submitted','approved','rejected','checked_out',
                         'checked_in','overdue_flagged','cancelled','expired')
                    NOT NULL,
    actor_type     ENUM('student','staff','system') NOT NULL,
    actor_id       VARCHAR(20) NULL,
    gate_location  VARCHAR(100) NULL,
    note           VARCHAR(255) NULL,
    event_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_event_request FOREIGN KEY (request_id)
        REFERENCES outing_request(id),
    INDEX idx_event_request (request_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------------
-- login_failure: login throttling (auth.php)
-- ------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS login_failure (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    account_type  ENUM('staff','student') NOT NULL,
    identifier    VARCHAR(64) NOT NULL,
    ip            VARCHAR(45) NOT NULL,
    attempted_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_account (account_type, identifier, attempted_at),
    INDEX idx_ip (ip, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------------
-- notification: student header bell (notifications.php)
-- ------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS notification (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    std_no           VARCHAR(20)  NOT NULL,
    type             VARCHAR(30)  NOT NULL,
    message          VARCHAR(255) NOT NULL,
    reference_table  VARCHAR(30)  NULL,
    reference_id     INT          NULL,
    is_read          TINYINT(1)   NOT NULL DEFAULT 0,
    created_at       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_notification_student FOREIGN KEY (std_no)
        REFERENCES student(std_no) ON DELETE CASCADE,
    INDEX idx_notification_unread (std_no, is_read),
    INDEX idx_notification_dedupe (std_no, type, reference_table, reference_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------------
-- Dynamic QR + group outings (totp.php / groups.php)
-- ------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS qr_secret (
    subject     VARCHAR(40) NOT NULL PRIMARY KEY,   -- 'S:<std_no>' or 'G:<group_id>'
    secret      VARCHAR(64) NOT NULL,
    last_ts     BIGINT      NOT NULL DEFAULT 0,
    created_at  TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS outing_group (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    lead_std_no   VARCHAR(20) NOT NULL,
    status        ENUM('active','closed') NOT NULL DEFAULT 'active',
    created_at    TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at    DATETIME    NOT NULL,
    CONSTRAINT fk_group_lead FOREIGN KEY (lead_std_no) REFERENCES student(std_no),
    INDEX idx_group_lead (lead_std_no, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS outing_group_member (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    group_id    INT NOT NULL,
    std_no      VARCHAR(20) NOT NULL,
    status      ENUM('invited','accepted','declined','removed','left') NOT NULL DEFAULT 'invited',
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    responded_at DATETIME NULL,
    CONSTRAINT fk_gm_group FOREIGN KEY (group_id) REFERENCES outing_group(id),
    CONSTRAINT fk_gm_student FOREIGN KEY (std_no) REFERENCES student(std_no),
    INDEX idx_gm_group (group_id, status),
    INDEX idx_gm_student (std_no, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS outing_group_scan (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    group_id        INT NOT NULL,
    guard_id        VARCHAR(20) NOT NULL,
    gate_location   VARCHAR(100) NULL,
    processed_count INT NOT NULL DEFAULT 0,
    skipped_count   INT NOT NULL DEFAULT 0,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_gs_group FOREIGN KEY (group_id) REFERENCES outing_group(id),
    CONSTRAINT fk_gs_guard FOREIGN KEY (guard_id) REFERENCES staff(staff_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS outing_group_log (
    group_id  INT NOT NULL,
    std_no    VARCHAR(20) NOT NULL,
    out_at    DATETIME NULL,
    in_at     DATETIME NULL,
    PRIMARY KEY (group_id, std_no),
    CONSTRAINT fk_gl_group FOREIGN KEY (group_id) REFERENCES outing_group(id),
    CONSTRAINT fk_gl_student FOREIGN KEY (std_no) REFERENCES student(std_no)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS outing_group_link (
    kind      ENUM('standard','special') NOT NULL,
    ref_id    INT NOT NULL,
    group_id  INT NOT NULL,
    PRIMARY KEY (kind, ref_id),
    INDEX idx_gl_group (group_id),
    CONSTRAINT fk_glink_group FOREIGN KEY (group_id) REFERENCES outing_group(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
