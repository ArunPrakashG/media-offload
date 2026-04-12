DROP INDEX IF EXISTS uq_licenses_subscription_id;

CREATE INDEX idx_licenses_subscription_id ON licenses (subscription_id)
    WHERE subscription_id IS NOT NULL;
