DROP TABLE IF EXISTS razorpay_subscriptions;

ALTER TABLE licenses
    DROP COLUMN IF EXISTS subscription_id,
    DROP COLUMN IF EXISTS billing_cycle;
