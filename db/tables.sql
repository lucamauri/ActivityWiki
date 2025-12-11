-- Language: mysql
-- ActivityWiki tables - Database agnostic schema
-- Supports: MySQL/MariaDB, PostgreSQL, SQLite
CREATE TABLE IF NOT EXISTS activitypub_activities (
    id INTEGER PRIMARY KEY AUTO_INCREMENT,
    activity_id VARCHAR(255) UNIQUE NOT NULL,
    activity_type VARCHAR(20) NOT NULL,
    object_type VARCHAR(20) NOT NULL,
    page_id INTEGER,
    page_title VARCHAR(255),
    user_id INTEGER,
    activity_json LONGTEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    published BOOLEAN DEFAULT FALSE
);
CREATE TABLE IF NOT EXISTS activitypub_config (
    id INTEGER PRIMARY KEY AUTO_INCREMENT,
    setting_name VARCHAR(100) UNIQUE NOT NULL,
    setting_value LONGTEXT
);
-- Indexes (supported by all databases)
CREATE INDEX IF NOT EXISTS idx_activitypub_page ON activitypub_activities (page_id);
CREATE INDEX IF NOT EXISTS idx_activitypub_user ON activitypub_activities (user_id);
CREATE INDEX IF NOT EXISTS idx_activitypub_published ON activitypub_activities (published);