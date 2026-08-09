-- SQLite equivalent of db/schema.sql, for local logic testing only.
-- Production deployments use db/schema.sql against MySQL.

CREATE TABLE admin_users (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    username        TEXT NOT NULL UNIQUE,
    password_hash   TEXT NOT NULL,
    created_at      TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE login_attempts (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    username        TEXT NOT NULL,
    ip_address      TEXT NOT NULL,
    success         INTEGER NOT NULL DEFAULT 0,
    created_at      TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE api_requests (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    ip_address      TEXT NOT NULL,
    endpoint        TEXT NOT NULL,
    created_at      TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE customers (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    name            TEXT NOT NULL,
    email           TEXT,
    phone           TEXT,
    notes           TEXT,
    created_at      TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE licenses (
    id                  INTEGER PRIMARY KEY AUTOINCREMENT,
    customer_id         INTEGER NOT NULL,
    license_key         TEXT NOT NULL UNIQUE,
    plan                TEXT NOT NULL,
    status              TEXT NOT NULL DEFAULT 'active',
    max_activations     INTEGER NOT NULL DEFAULT 1,
    issued_at           TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at          TEXT NULL,
    last_verified_at    TEXT NULL,
    notes               TEXT,
    created_at          TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (customer_id) REFERENCES customers(id)
);

CREATE TABLE license_activations (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    license_id      INTEGER NOT NULL,
    hwid            TEXT NOT NULL,
    is_active       INTEGER NOT NULL DEFAULT 1,
    activated_at    TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_seen_at    TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ip_address      TEXT,
    FOREIGN KEY (license_id) REFERENCES licenses(id),
    UNIQUE (license_id, hwid)
);

CREATE TABLE verification_log (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    license_id      INTEGER NULL,
    license_key     TEXT NOT NULL,
    hwid            TEXT,
    result          TEXT NOT NULL,
    ip_address      TEXT,
    created_at      TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE subscription_events (
    id                  INTEGER PRIMARY KEY AUTOINCREMENT,
    license_id          INTEGER NOT NULL,
    event_type          TEXT NOT NULL,
    previous_expires_at TEXT NULL,
    new_expires_at      TEXT NULL,
    note                TEXT,
    created_by          TEXT,
    created_at          TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
