CREATE TABLE IF NOT EXISTS modules (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 slug VARCHAR(80) NOT NULL UNIQUE,
 name VARCHAR(190) NOT NULL,
 version VARCHAR(40) NOT NULL,
 description TEXT,
 enabled TINYINT(1) NOT NULL DEFAULT 0,
 installed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS notifications (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 type VARCHAR(60) NOT NULL,
 title VARCHAR(255) NOT NULL,
 message TEXT NOT NULL,
 order_id BIGINT UNSIGNED NULL,
 channel_id BIGINT UNSIGNED NULL,
 data_json LONGTEXT,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_notification_order_type(type,order_id),
 KEY idx_notification_created(created_at),
 KEY idx_notification_order(order_id),
 KEY idx_notification_channel(channel_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS notification_reads (
 user_id BIGINT UNSIGNED NOT NULL,
 notification_id BIGINT UNSIGNED NOT NULL,
 read_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY(user_id,notification_id),
 KEY idx_nr_notification(notification_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
