PRAGMA foreign_keys = ON;
CREATE TABLE IF NOT EXISTS users (
 id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, email TEXT NOT NULL UNIQUE, password_hash TEXT NOT NULL, role TEXT NOT NULL DEFAULT 'admin', permissions_json TEXT, is_active INTEGER NOT NULL DEFAULT 1, job_title TEXT, avatar_path TEXT, push_orders_enabled INTEGER NOT NULL DEFAULT 1, push_stock_enabled INTEGER NOT NULL DEFAULT 1, push_chat_enabled INTEGER NOT NULL DEFAULT 1, last_active_at TEXT, deleted_at TEXT, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS auth_remember_tokens (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    selector TEXT NOT NULL UNIQUE,
    token_hash TEXT NOT NULL,
    expires_at TEXT NOT NULL,
    last_used_at TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_auth_remember_user ON auth_remember_tokens(user_id);
CREATE INDEX IF NOT EXISTS idx_auth_remember_expires ON auth_remember_tokens(expires_at);

CREATE TABLE IF NOT EXISTS channels (
 id INTEGER PRIMARY KEY AUTOINCREMENT, code TEXT NOT NULL UNIQUE, name TEXT NOT NULL, type TEXT NOT NULL, country TEXT NOT NULL, currency TEXT NOT NULL, enabled INTEGER NOT NULL DEFAULT 1, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS products (
 id INTEGER PRIMARY KEY AUTOINCREMENT, sku TEXT NOT NULL UNIQUE, ean TEXT, name TEXT NOT NULL, description TEXT, short_description TEXT, image_url TEXT, stock INTEGER NOT NULL DEFAULT 0, archived INTEGER NOT NULL DEFAULT 0, cost_price REAL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS channel_products (
 id INTEGER PRIMARY KEY AUTOINCREMENT, channel_id INTEGER NOT NULL, product_id INTEGER NOT NULL, external_id TEXT, external_sku TEXT, price REAL NOT NULL DEFAULT 0, sale_price TEXT, currency TEXT NOT NULL, stock INTEGER NOT NULL DEFAULT 0, status INTEGER NOT NULL DEFAULT 1, remote_status TEXT, permalink TEXT, name TEXT, description TEXT, short_description TEXT, image_json TEXT, categories_json TEXT, tags_json TEXT, brands_json TEXT, seo_title TEXT, seo_description TEXT, seo_focus_keyword TEXT, seo_score INTEGER, raw_json TEXT, vat_id INTEGER, handling_time INTEGER NOT NULL DEFAULT 0, updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE(channel_id, external_id), FOREIGN KEY(channel_id) REFERENCES channels(id), FOREIGN KEY(product_id) REFERENCES products(id)
);
CREATE TABLE IF NOT EXISTS orders (
 id INTEGER PRIMARY KEY AUTOINCREMENT, code TEXT NOT NULL UNIQUE, channel_id INTEGER NOT NULL, external_id TEXT NOT NULL, status TEXT NOT NULL, status_note TEXT, remote_deleted INTEGER NOT NULL DEFAULT 0, currency TEXT NOT NULL, total REAL NOT NULL DEFAULT 0, customer_name TEXT, customer_email TEXT, customer_phone TEXT, billing_json TEXT, shipping_json TEXT, raw_json TEXT, ordered_at TEXT, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE(channel_id, external_id), FOREIGN KEY(channel_id) REFERENCES channels(id)
);
CREATE TABLE IF NOT EXISTS order_items (
 id INTEGER PRIMARY KEY AUTOINCREMENT, order_id INTEGER NOT NULL, external_item_id TEXT, remote_product_id TEXT, product_id INTEGER, sku TEXT, name TEXT NOT NULL, qty REAL NOT NULL DEFAULT 1, unit_price REAL NOT NULL DEFAULT 0, vat_rate REAL, image_url TEXT, product_url TEXT, FOREIGN KEY(order_id) REFERENCES orders(id) ON DELETE CASCADE, FOREIGN KEY(product_id) REFERENCES products(id)
);
CREATE INDEX IF NOT EXISTS idx_order_items_remote_product ON order_items(remote_product_id);
CREATE TABLE IF NOT EXISTS emag_product_media (
 id INTEGER PRIMARY KEY AUTOINCREMENT, channel_id INTEGER NOT NULL, remote_product_id TEXT NOT NULL, source_url TEXT, local_url TEXT, image_hash TEXT, product_url TEXT, synced_at TEXT, checked_at TEXT, last_error TEXT, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE(channel_id,remote_product_id)
);
CREATE INDEX IF NOT EXISTS idx_emag_media_checked ON emag_product_media(channel_id,checked_at);
CREATE TABLE IF NOT EXISTS emag_product_links (
    id INTEGER PRIMARY KEY AUTOINCREMENT, channel_id INTEGER NOT NULL, pnk TEXT NOT NULL, product_id INTEGER NOT NULL, part_number TEXT, product_name TEXT, mapping_source TEXT NOT NULL DEFAULT 'auto', match_reason TEXT, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE(channel_id,pnk)
);
CREATE INDEX IF NOT EXISTS idx_emag_product_link_product ON emag_product_links(product_id);
CREATE TABLE IF NOT EXISTS emag_order_product_backfill (
 id INTEGER PRIMARY KEY AUTOINCREMENT, order_id INTEGER NOT NULL UNIQUE, channel_id INTEGER NOT NULL, checked_at TEXT, resolved_at TEXT, attempt_count INTEGER NOT NULL DEFAULT 0, last_error TEXT, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_emag_order_backfill_due ON emag_order_product_backfill(channel_id,resolved_at,checked_at);
CREATE TABLE IF NOT EXISTS invoices (
 id INTEGER PRIMARY KEY AUTOINCREMENT, order_id INTEGER NOT NULL, parent_invoice_id INTEGER, type TEXT NOT NULL DEFAULT 'invoice', series TEXT, number TEXT, status TEXT NOT NULL DEFAULT 'issued', total REAL NOT NULL DEFAULT 0, currency TEXT NOT NULL, link TEXT, raw_json TEXT, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY(order_id) REFERENCES orders(id), FOREIGN KEY(parent_invoice_id) REFERENCES invoices(id)
);
CREATE TABLE IF NOT EXISTS shipments (
 id INTEGER PRIMARY KEY AUTOINCREMENT, order_id INTEGER NOT NULL, courier TEXT NOT NULL, awb TEXT NOT NULL UNIQUE, awb_barcode TEXT, provider_awb_id TEXT, status TEXT NOT NULL DEFAULT 'created', tracking_url TEXT, raw_json TEXT, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY(order_id) REFERENCES orders(id)
);
CREATE TABLE IF NOT EXISTS inventory_movements (
 id INTEGER PRIMARY KEY AUTOINCREMENT, product_id INTEGER NOT NULL, order_id INTEGER, type TEXT NOT NULL, quantity INTEGER NOT NULL, note TEXT, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY(product_id) REFERENCES products(id), FOREIGN KEY(order_id) REFERENCES orders(id)
);
CREATE TABLE IF NOT EXISTS sync_logs (
 id INTEGER PRIMARY KEY AUTOINCREMENT, channel_id INTEGER, level TEXT NOT NULL, action TEXT NOT NULL, message TEXT NOT NULL, context_json TEXT, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY(channel_id) REFERENCES channels(id)
);
CREATE INDEX IF NOT EXISTS idx_orders_status ON orders(status);
CREATE INDEX IF NOT EXISTS idx_orders_ordered_at ON orders(ordered_at);
CREATE INDEX IF NOT EXISTS idx_orders_created_at ON orders(created_at);
CREATE INDEX IF NOT EXISTS idx_orders_active_ordered ON orders(remote_deleted,ordered_at,id);
CREATE INDEX IF NOT EXISTS idx_orders_active_status_ordered ON orders(remote_deleted,status,ordered_at,id);
CREATE INDEX IF NOT EXISTS idx_orders_active_channel_ordered ON orders(remote_deleted,channel_id,ordered_at,id);
CREATE INDEX IF NOT EXISTS idx_order_items_order ON order_items(order_id);
CREATE INDEX IF NOT EXISTS idx_invoice_order ON invoices(order_id);
CREATE INDEX IF NOT EXISTS idx_shipment_order ON shipments(order_id);
CREATE INDEX IF NOT EXISTS idx_shipment_awb_barcode ON shipments(awb_barcode);
CREATE INDEX IF NOT EXISTS idx_shipment_provider_awb ON shipments(provider_awb_id);
CREATE INDEX IF NOT EXISTS idx_sync_log_level_action_created ON sync_logs(level,action,created_at);
CREATE INDEX IF NOT EXISTS idx_sync_log_channel_created ON sync_logs(channel_id,created_at);
CREATE TABLE IF NOT EXISTS modules (
 id INTEGER PRIMARY KEY AUTOINCREMENT, slug TEXT NOT NULL UNIQUE, name TEXT NOT NULL, version TEXT NOT NULL, description TEXT, enabled INTEGER NOT NULL DEFAULT 0, installed_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS notifications (
 id INTEGER PRIMARY KEY AUTOINCREMENT, type TEXT NOT NULL, title TEXT NOT NULL, message TEXT NOT NULL, order_id INTEGER, channel_id INTEGER, product_id INTEGER, severity TEXT NOT NULL DEFAULT 'info', data_json TEXT, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE(type,order_id), FOREIGN KEY(order_id) REFERENCES orders(id) ON DELETE CASCADE, FOREIGN KEY(channel_id) REFERENCES channels(id) ON DELETE SET NULL
);
CREATE TABLE IF NOT EXISTS notification_reads (
 user_id INTEGER NOT NULL, notification_id INTEGER NOT NULL, read_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY(user_id,notification_id), FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE, FOREIGN KEY(notification_id) REFERENCES notifications(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_notification_created ON notifications(created_at);

CREATE TABLE IF NOT EXISTS settings (id INTEGER PRIMARY KEY AUTOINCREMENT,setting_key TEXT NOT NULL UNIQUE,value TEXT,scope TEXT NOT NULL DEFAULT 'app',updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP);
CREATE INDEX IF NOT EXISTS idx_settings_scope ON settings(scope);


CREATE TABLE IF NOT EXISTS user_activity_logs (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 user_id INTEGER,
 user_name TEXT NOT NULL,
 user_email TEXT,
 user_role TEXT,
 action_key TEXT NOT NULL,
 action_label TEXT NOT NULL,
 category TEXT NOT NULL DEFAULT 'system',
 entity_type TEXT,
 entity_id TEXT,
 route TEXT,
 method TEXT,
 status TEXT NOT NULL DEFAULT 'success',
 ip_address TEXT,
 user_agent TEXT,
 context_json TEXT,
 created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_activity_created ON user_activity_logs(created_at);
CREATE INDEX IF NOT EXISTS idx_activity_user_created ON user_activity_logs(user_id,created_at);
CREATE INDEX IF NOT EXISTS idx_activity_category_created ON user_activity_logs(category,created_at);
