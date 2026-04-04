-- Add subscription tracking fields to licenses.
ALTER TABLE licenses
    ADD COLUMN subscription_id   TEXT,
    ADD COLUMN billing_cycle     TEXT NOT NULL DEFAULT 'monthly';

CREATE INDEX idx_licenses_subscription_id ON licenses(subscription_id)
    WHERE subscription_id IS NOT NULL;

-- Razorpay subscription records.
-- status values: created | authenticated | active | paused | cancelled | completed | expired
CREATE TABLE razorpay_subscriptions (
    id              UUID        PRIMARY KEY DEFAULT gen_random_uuid(),
    license_id      UUID        REFERENCES licenses(id) ON DELETE SET NULL,
    rzp_sub_id      TEXT        UNIQUE NOT NULL,
    plan_id         TEXT        NOT NULL,
    tier            TEXT        NOT NULL,
    billing_cycle   TEXT        NOT NULL DEFAULT 'monthly',
    customer_email  TEXT        NOT NULL DEFAULT '',
    status          TEXT        NOT NULL DEFAULT 'created',
    current_start   TIMESTAMPTZ,
    current_end     TIMESTAMPTZ,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_rzp_sub_rzp_id     ON razorpay_subscriptions(rzp_sub_id);
CREATE INDEX idx_rzp_sub_license_id ON razorpay_subscriptions(license_id)
    WHERE license_id IS NOT NULL;
