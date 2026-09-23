CREATE TABLE IF NOT EXISTS users (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,name VARCHAR(150) NOT NULL,email VARCHAR(190) NOT NULL UNIQUE,password_hash VARCHAR(255) NOT NULL,role VARCHAR(30) NOT NULL DEFAULT 'admin',permissions_json LONGTEXT NULL,is_active TINYINT(1) NOT NULL DEFAULT 1,job_title VARCHAR(150) NULL,avatar_path TEXT NULL,push_orders_enabled TINYINT(1) NOT NULL DEFAULT 1,push_stock_enabled TINYINT(1) NOT NULL DEFAULT 1,push_chat_enabled TINYINT(1) NOT NULL DEFAULT 1,last_active_at DATETIME NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,deleted_at DATETIME NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS auth_remember_tokens (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,user_id BIGINT UNSIGNED NOT NULL,selector CHAR(24) NOT NULL UNIQUE,token_hash CHAR(64) NOT NULL,expires_at DATETIME NOT NULL,last_used_at DATETIME NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,KEY idx_auth_remember_user(user_id),KEY idx_auth_remember_expires(expires_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS channels (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,code VARCHAR(40) NOT NULL UNIQUE,name VARCHAR(150) NOT NULL,type VARCHAR(30) NOT NULL,country VARCHAR(10) NOT NULL,currency VARCHAR(3) NOT NULL,enabled TINYINT(1) NOT NULL DEFAULT 1,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS products (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,sku VARCHAR(190) NOT NULL UNIQUE,ean VARCHAR(64),name VARCHAR(255) NOT NULL,description LONGTEXT,short_description LONGTEXT,image_url TEXT,stock INT NOT NULL DEFAULT 0,archived TINYINT(1) NOT NULL DEFAULT 0,cost_price DECIMAL(12,4),created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS channel_products (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,channel_id BIGINT UNSIGNED NOT NULL,product_id BIGINT UNSIGNED NOT NULL,external_id VARCHAR(190),external_sku VARCHAR(190),price DECIMAL(12,4) NOT NULL DEFAULT 0,sale_price VARCHAR(60) NULL,currency VARCHAR(3) NOT NULL,stock INT NOT NULL DEFAULT 0,status TINYINT(1) NOT NULL DEFAULT 1,remote_status VARCHAR(40) NULL,permalink TEXT,name VARCHAR(255) NULL,description LONGTEXT,short_description LONGTEXT,image_json LONGTEXT,categories_json LONGTEXT,tags_json LONGTEXT,brands_json LONGTEXT,seo_title TEXT,seo_description TEXT,seo_focus_keyword TEXT,seo_score INT NULL,raw_json LONGTEXT,vat_id INT NULL,handling_time INT NOT NULL DEFAULT 0,updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,UNIQUE KEY uq_channel_external(channel_id,external_id),KEY idx_cp_product(product_id),CONSTRAINT fk_cp_channel FOREIGN KEY(channel_id) REFERENCES channels(id),CONSTRAINT fk_cp_product FOREIGN KEY(product_id) REFERENCES products(id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS orders (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,code VARCHAR(40) NOT NULL UNIQUE,channel_id BIGINT UNSIGNED NOT NULL,external_id VARCHAR(190) NOT NULL,status VARCHAR(40) NOT NULL,status_note TEXT NULL,remote_deleted TINYINT(1) NOT NULL DEFAULT 0,currency VARCHAR(3) NOT NULL,total DECIMAL(12,2) NOT NULL DEFAULT 0,customer_name VARCHAR(255),customer_email VARCHAR(255),customer_phone VARCHAR(80),billing_json LONGTEXT,shipping_json LONGTEXT,raw_json LONGTEXT,ordered_at DATETIME NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,UNIQUE KEY uq_order_external(channel_id,external_id),KEY idx_status(status),KEY idx_ordered_at(ordered_at),KEY idx_orders_created_at(created_at),KEY idx_orders_active_ordered(remote_deleted,ordered_at,id),KEY idx_orders_active_status_ordered(remote_deleted,status,ordered_at,id),KEY idx_orders_active_channel_ordered(remote_deleted,channel_id,ordered_at,id),CONSTRAINT fk_order_channel FOREIGN KEY(channel_id) REFERENCES channels(id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS order_items (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,order_id BIGINT UNSIGNED NOT NULL,external_item_id VARCHAR(190),remote_product_id VARCHAR(190),product_id BIGINT UNSIGNED NULL,sku VARCHAR(190),name VARCHAR(255) NOT NULL,qty DECIMAL(12,3) NOT NULL DEFAULT 1,unit_price DECIMAL(12,4) NOT NULL DEFAULT 0,vat_rate DECIMAL(6,2),image_url TEXT,product_url TEXT,KEY idx_item_order(order_id),KEY idx_order_items_remote_product(remote_product_id),CONSTRAINT fk_item_order FOREIGN KEY(order_id) REFERENCES orders(id) ON DELETE CASCADE,CONSTRAINT fk_item_product FOREIGN KEY(product_id) REFERENCES products(id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS emag_product_media (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,channel_id BIGINT UNSIGNED NOT NULL,remote_product_id VARCHAR(190) NOT NULL,source_url TEXT,local_url TEXT,image_hash CHAR(64),product_url TEXT,synced_at DATETIME NULL,checked_at DATETIME NULL,last_error TEXT,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,UNIQUE KEY uq_emag_media_channel_product(channel_id,remote_product_id),KEY idx_emag_media_checked(channel_id,checked_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS emag_product_links (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,channel_id BIGINT UNSIGNED NOT NULL,pnk VARCHAR(100) NOT NULL,product_id BIGINT UNSIGNED NOT NULL,part_number VARCHAR(190) NULL,product_name VARCHAR(500) NULL,mapping_source VARCHAR(30) NOT NULL DEFAULT 'auto',match_reason VARCHAR(80) NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,UNIQUE KEY uq_emag_product_link_channel_pnk(channel_id,pnk),KEY idx_emag_product_link_product(product_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS emag_order_product_backfill (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,order_id BIGINT UNSIGNED NOT NULL,channel_id BIGINT UNSIGNED NOT NULL,checked_at DATETIME NULL,resolved_at DATETIME NULL,attempt_count INT NOT NULL DEFAULT 0,last_error TEXT,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,UNIQUE KEY uq_emag_order_backfill_order(order_id),KEY idx_emag_order_backfill_due(channel_id,resolved_at,checked_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS invoices (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,order_id BIGINT UNSIGNED NOT NULL,parent_invoice_id BIGINT UNSIGNED NULL,type VARCHAR(20) NOT NULL DEFAULT 'invoice',series VARCHAR(30),number VARCHAR(60),status VARCHAR(30) NOT NULL DEFAULT 'issued',total DECIMAL(12,2) NOT NULL DEFAULT 0,currency VARCHAR(3) NOT NULL,link TEXT,raw_json LONGTEXT,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,CONSTRAINT fk_inv_order FOREIGN KEY(order_id) REFERENCES orders(id),CONSTRAINT fk_inv_parent FOREIGN KEY(parent_invoice_id) REFERENCES invoices(id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS shipments (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,order_id BIGINT UNSIGNED NOT NULL,courier VARCHAR(80) NOT NULL,awb VARCHAR(190) NOT NULL UNIQUE,awb_barcode VARCHAR(190) NULL,provider_awb_id VARCHAR(80) NULL,status VARCHAR(40) NOT NULL DEFAULT 'created',tracking_url TEXT,raw_json LONGTEXT,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,KEY idx_shipment_awb_barcode(awb_barcode),KEY idx_shipment_provider_awb(provider_awb_id),CONSTRAINT fk_ship_order FOREIGN KEY(order_id) REFERENCES orders(id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS inventory_movements (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,product_id BIGINT UNSIGNED NOT NULL,order_id BIGINT UNSIGNED NULL,type VARCHAR(40) NOT NULL,quantity INT NOT NULL,note VARCHAR(255),created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,CONSTRAINT fk_mov_product FOREIGN KEY(product_id) REFERENCES products(id),CONSTRAINT fk_mov_order FOREIGN KEY(order_id) REFERENCES orders(id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS sync_logs (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,channel_id BIGINT UNSIGNED NULL,level VARCHAR(20) NOT NULL,action VARCHAR(80) NOT NULL,message TEXT NOT NULL,context_json LONGTEXT,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,KEY idx_sync_log_level_action_created(level,action,created_at),KEY idx_sync_log_channel_created(channel_id,created_at),CONSTRAINT fk_log_channel FOREIGN KEY(channel_id) REFERENCES channels(id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
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
 product_id BIGINT UNSIGNED NULL,
 severity VARCHAR(20) NOT NULL DEFAULT 'info',
 data_json LONGTEXT,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_notification_order_type(type,order_id),
 KEY idx_notification_created(created_at),
 KEY idx_notification_order(order_id),
 KEY idx_notification_channel(channel_id),
 KEY idx_notification_product(product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS notification_reads (
 user_id BIGINT UNSIGNED NOT NULL,
 notification_id BIGINT UNSIGNED NOT NULL,
 read_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY(user_id,notification_id),
 KEY idx_nr_notification(notification_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS settings (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,setting_key VARCHAR(190) NOT NULL UNIQUE,value LONGTEXT NULL,scope VARCHAR(100) NOT NULL DEFAULT 'app',updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,KEY idx_settings_scope(scope)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


CREATE TABLE IF NOT EXISTS user_activity_logs (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 user_id BIGINT UNSIGNED NULL,
 user_name VARCHAR(150) NOT NULL,
 user_email VARCHAR(190) NULL,
 user_role VARCHAR(30) NULL,
 action_key VARCHAR(100) NOT NULL,
 action_label VARCHAR(500) NOT NULL,
 category VARCHAR(50) NOT NULL DEFAULT 'system',
 entity_type VARCHAR(50) NULL,
 entity_id VARCHAR(190) NULL,
 route VARCHAR(500) NULL,
 method VARCHAR(15) NULL,
 status VARCHAR(20) NOT NULL DEFAULT 'success',
 ip_address VARCHAR(64) NULL,
 user_agent VARCHAR(500) NULL,
 context_json LONGTEXT NULL,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 KEY idx_activity_created(created_at),
 KEY idx_activity_user_created(user_id,created_at),
 KEY idx_activity_category_created(category,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
