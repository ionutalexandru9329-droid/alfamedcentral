CREATE TABLE IF NOT EXISTS shipments (id INTEGER PRIMARY KEY AUTOINCREMENT,order_id INTEGER NOT NULL,courier TEXT NOT NULL,awb TEXT NOT NULL UNIQUE,awb_barcode TEXT,provider_awb_id TEXT,status TEXT NOT NULL DEFAULT 'created',tracking_url TEXT,raw_json TEXT,created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,FOREIGN KEY(order_id) REFERENCES orders(id));

CREATE INDEX IF NOT EXISTS idx_shipment_awb_barcode ON shipments(awb_barcode);
CREATE INDEX IF NOT EXISTS idx_shipment_provider_awb ON shipments(provider_awb_id);
