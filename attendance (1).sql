CREATE TABLE IF NOT EXISTS attendance (
    academic_number TEXT NOT NULL PRIMARY KEY,
    name TEXT NOT NULL,
    mobile TEXT NOT NULL,
    group_name TEXT NOT NULL,
    latitude REAL NOT NULL,
    longitude REAL NOT NULL,
    code TEXT NOT NULL,
    responses_json TEXT NOT NULL DEFAULT '[]',
    response_count INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_attendance_created_at ON attendance (created_at);
CREATE INDEX IF NOT EXISTS idx_attendance_group_name ON attendance (group_name);