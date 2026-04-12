-- Upgrade the non-unique index on licenses.subscription_id to a true UNIQUE
-- constraint so that concurrent verify-payment + subscription.activated webhook
-- calls can never produce duplicate license rows for the same subscription.

DROP INDEX IF EXISTS idx_licenses_subscription_id;

CREATE UNIQUE INDEX uq_licenses_subscription_id
    ON licenses (subscription_id)
    WHERE subscription_id IS NOT NULL;
