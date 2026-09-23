CREATE TABLE IF NOT EXISTS invoices (id INTEGER PRIMARY KEY AUTOINCREMENT,order_id INTEGER NOT NULL,parent_invoice_id INTEGER,type TEXT NOT NULL DEFAULT 'invoice',series TEXT,number TEXT,status TEXT NOT NULL DEFAULT 'issued',total REAL NOT NULL DEFAULT 0,currency TEXT NOT NULL,link TEXT,raw_json TEXT,created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,FOREIGN KEY(order_id) REFERENCES orders(id),FOREIGN KEY(parent_invoice_id) REFERENCES invoices(id));
CREATE TABLE IF NOT EXISTS oblio_product_mappings (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    channel_code TEXT NOT NULL,
    source_code TEXT NOT NULL,
    oblio_code TEXT NOT NULL,
    oblio_name TEXT,
    mapping_source TEXT NOT NULL DEFAULT 'manual',
    match_reason TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(channel_code, source_code)
);
CREATE INDEX IF NOT EXISTS idx_oblio_mapping_source ON oblio_product_mappings(mapping_source);
CREATE TABLE IF NOT EXISTS oblio_products_cache (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    source_key TEXT NOT NULL UNIQUE,
    oblio_code TEXT,
    name TEXT NOT NULL,
    code_client TEXT,
    code_ean TEXT,
    description TEXT,
    measuring_unit TEXT,
    product_type TEXT,
    price REAL,
    currency TEXT,
    vat_name TEXT,
    vat_percentage REAL,
    vat_included INTEGER,
    stock_json TEXT,
    raw_json TEXT,
    active INTEGER NOT NULL DEFAULT 1,
    last_synced_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_oblio_products_code ON oblio_products_cache(oblio_code);
CREATE INDEX IF NOT EXISTS idx_oblio_products_client ON oblio_products_cache(code_client);
CREATE INDEX IF NOT EXISTS idx_oblio_products_ean ON oblio_products_cache(code_ean);
CREATE INDEX IF NOT EXISTS idx_oblio_products_name ON oblio_products_cache(name);
