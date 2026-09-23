CREATE TABLE IF NOT EXISTS invoices (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,order_id BIGINT UNSIGNED NOT NULL,parent_invoice_id BIGINT UNSIGNED NULL,type VARCHAR(20) NOT NULL DEFAULT 'invoice',series VARCHAR(30),number VARCHAR(60),status VARCHAR(30) NOT NULL DEFAULT 'issued',total DECIMAL(12,2) NOT NULL DEFAULT 0,currency VARCHAR(3) NOT NULL,link TEXT,raw_json LONGTEXT,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,CONSTRAINT fk_inv_order FOREIGN KEY(order_id) REFERENCES orders(id),CONSTRAINT fk_inv_parent FOREIGN KEY(parent_invoice_id) REFERENCES invoices(id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS oblio_product_mappings (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,channel_code VARCHAR(50) NOT NULL,source_code VARCHAR(190) NOT NULL,oblio_code VARCHAR(190) NOT NULL,oblio_name VARCHAR(255) NULL,mapping_source VARCHAR(30) NOT NULL DEFAULT 'manual',match_reason VARCHAR(60) NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,UNIQUE KEY uq_oblio_mapping(channel_code,source_code),KEY idx_oblio_mapping_source(mapping_source)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS oblio_products_cache (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    source_key CHAR(40) NOT NULL UNIQUE,
    oblio_code VARCHAR(190) NULL,
    name VARCHAR(255) NOT NULL,
    code_client VARCHAR(190) NULL,
    code_ean VARCHAR(190) NULL,
    description LONGTEXT NULL,
    measuring_unit VARCHAR(60) NULL,
    product_type VARCHAR(80) NULL,
    price DECIMAL(16,4) NULL,
    currency VARCHAR(10) NULL,
    vat_name VARCHAR(80) NULL,
    vat_percentage DECIMAL(8,4) NULL,
    vat_included TINYINT(1) NULL,
    stock_json LONGTEXT NULL,
    raw_json LONGTEXT NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    last_synced_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_oblio_products_code(oblio_code),
    KEY idx_oblio_products_client(code_client),
    KEY idx_oblio_products_ean(code_ean),
    KEY idx_oblio_products_name(name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
