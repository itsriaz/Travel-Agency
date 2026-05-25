-- Treasury P2: Link customer receipts to treasury accounts
-- Safe additive patch only.
-- Does not delete or rewrite existing receipt data.
-- treasury_account_id remains nullable so existing rows stay valid.
-- Review before running. Intended to be applied once per database.

SET @schema_name := DATABASE();

-- 1) Add nullable treasury_account_id column if it does not already exist.
SET @has_treasury_account_column := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @schema_name
      AND TABLE_NAME = 'customer_receipts'
      AND COLUMN_NAME = 'treasury_account_id'
);

SET @add_treasury_account_column_sql := IF(
    @has_treasury_account_column = 0,
    'ALTER TABLE customer_receipts ADD COLUMN treasury_account_id BIGINT UNSIGNED NULL AFTER exchange_rate_to_booking',
    'SELECT ''customer_receipts.treasury_account_id already exists'''
);

PREPARE add_treasury_account_column_stmt FROM @add_treasury_account_column_sql;
EXECUTE add_treasury_account_column_stmt;
DEALLOCATE PREPARE add_treasury_account_column_stmt;

-- 2) Add index on treasury_account_id if it does not already exist.
SET @has_treasury_account_index := (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = @schema_name
      AND TABLE_NAME = 'customer_receipts'
      AND INDEX_NAME = 'idx_customer_receipts_treasury_account'
);

SET @add_treasury_account_index_sql := IF(
    @has_treasury_account_index = 0,
    'ALTER TABLE customer_receipts ADD INDEX idx_customer_receipts_treasury_account (treasury_account_id)',
    'SELECT ''idx_customer_receipts_treasury_account already exists'''
);

PREPARE add_treasury_account_index_stmt FROM @add_treasury_account_index_sql;
EXECUTE add_treasury_account_index_stmt;
DEALLOCATE PREPARE add_treasury_account_index_stmt;

-- 3) Add foreign key to treasury_accounts(id) if it does not already exist.
SET @has_treasury_account_fk := (
    SELECT COUNT(*)
    FROM information_schema.REFERENTIAL_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = @schema_name
      AND TABLE_NAME = 'customer_receipts'
      AND CONSTRAINT_NAME = 'fk_customer_receipts_treasury_account'
);

SET @add_treasury_account_fk_sql := IF(
    @has_treasury_account_fk = 0,
    'ALTER TABLE customer_receipts ADD CONSTRAINT fk_customer_receipts_treasury_account FOREIGN KEY (treasury_account_id) REFERENCES treasury_accounts(id)',
    'SELECT ''fk_customer_receipts_treasury_account already exists'''
);

PREPARE add_treasury_account_fk_stmt FROM @add_treasury_account_fk_sql;
EXECUTE add_treasury_account_fk_stmt;
DEALLOCATE PREPARE add_treasury_account_fk_stmt;
