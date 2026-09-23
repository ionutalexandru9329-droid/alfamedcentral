CREATE TABLE IF NOT EXISTS chat_rooms (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 type TEXT NOT NULL DEFAULT 'direct',
 name TEXT,
 direct_key TEXT UNIQUE,
 created_by INTEGER NOT NULL,
 created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS chat_room_members (
 room_id INTEGER NOT NULL,
 user_id INTEGER NOT NULL,
 joined_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
 muted INTEGER NOT NULL DEFAULT 0,
 PRIMARY KEY(room_id,user_id)
);
CREATE INDEX IF NOT EXISTS idx_chat_member_user ON chat_room_members(user_id);
CREATE TABLE IF NOT EXISTS chat_messages (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 room_id INTEGER NOT NULL,
 sender_id INTEGER NOT NULL,
 message TEXT,
 attachment_name TEXT,
 attachment_path TEXT,
 attachment_mime TEXT,
 attachment_size INTEGER,
 edited_at TEXT,
 deleted_at TEXT,
 created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_chat_messages_room ON chat_messages(room_id,id);
CREATE TABLE IF NOT EXISTS chat_message_reads (
 user_id INTEGER NOT NULL,
 message_id INTEGER NOT NULL,
 read_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY(user_id,message_id)
);
