CREATE TABLE IF NOT EXISTS users (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    username      TEXT NOT NULL UNIQUE,
    display_name  TEXT NOT NULL,
    password_hash TEXT NOT NULL,
    role          TEXT NOT NULL CHECK (role IN ('manager','intake','surveyor','drafter')),
    active        INTEGER NOT NULL DEFAULT 1,
    created_at    TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS work_orders (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    code        TEXT UNIQUE,
    title       TEXT NOT NULL,
    client      TEXT NOT NULL,
    description TEXT NOT NULL DEFAULT '',
    priority    TEXT NOT NULL DEFAULT 'normal' CHECK (priority IN ('low','normal','high','urgent')),
    stage       TEXT NOT NULL DEFAULT 'new' CHECK (stage IN ('new','survey','drafting','review','done','hold')),
    held_from   TEXT,
    created_by  INTEGER NOT NULL REFERENCES users(id),
    created_at  TEXT NOT NULL,
    updated_at  TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_orders_stage ON work_orders(stage);
CREATE INDEX IF NOT EXISTS idx_orders_updated ON work_orders(updated_at);

CREATE TABLE IF NOT EXISTS events (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    order_id   INTEGER NOT NULL REFERENCES work_orders(id) ON DELETE CASCADE,
    user_id    INTEGER NOT NULL REFERENCES users(id),
    type       TEXT NOT NULL CHECK (type IN ('created','transition','comment')),
    action     TEXT,
    from_stage TEXT,
    to_stage   TEXT,
    note       TEXT,
    created_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_events_order ON events(order_id);

CREATE TABLE IF NOT EXISTS login_attempts (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    username     TEXT NOT NULL,
    ip           TEXT NOT NULL,
    attempted_at INTEGER NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_attempts ON login_attempts(username, ip, attempted_at);
