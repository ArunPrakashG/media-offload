export const prerender = false;

import type { APIRoute } from "astro";
import Razorpay from "razorpay";

// Razorpay plan IDs are created once in the Razorpay Dashboard and stored as env vars.
// Key format: RAZORPAY_PLAN_{TIER}_ANNUAL
const PLAN_ENV_MAP: Record<string, string> = {
  personal: "RAZORPAY_PLAN_PERSONAL_ANNUAL",
  pro: "RAZORPAY_PLAN_PRO_ANNUAL",
  agency: "RAZORPAY_PLAN_AGENCY_ANNUAL",
};

const TIER_LABELS: Record<string, string> = {
  personal: "Personal",
  pro: "Pro",
  agency: "Agency",
};

export const POST: APIRoute = async ({ request }) => {
  const keyId = import.meta.env.RAZORPAY_KEY_ID;
  const keySecret = import.meta.env.RAZORPAY_KEY_SECRET;

  if (!keyId || !keySecret) {
    return json({ error: "Payment gateway not configured." }, 503);
  }

  let body: { plan?: string; billing_cycle?: string; email?: string };
  try {
    body = await request.json();
  } catch {
    return json({ error: "Invalid request body." }, 400);
  }

  const plan = (body.plan ?? "").toLowerCase();
  const email = (body.email ?? "").trim();

  if (!TIER_LABELS[plan]) {
    return json({ error: "Invalid plan." }, 400);
  }

  const planId =
    (import.meta.env as Record<string, string>)[PLAN_ENV_MAP[plan] ?? ""] ?? "";

  if (!planId) {
    return json(
      { error: `Plan ID for ${plan} is not configured. Contact support.` },
      503,
    );
  }

  try {
    const razorpay = new Razorpay({ key_id: keyId, key_secret: keySecret });

    // total_count: 120 = 10 years of monthly / ~10 annual cycles (perpetual for practical purposes).
    const subscription = await razorpay.subscriptions.create({
      plan_id: planId,
      total_count: 10, // up to 10 annual renewal cycles
      quantity: 1,
      notes: {
        tier: plan,
        billing_cycle: "annual",
        email: email,
      },
    } as Parameters<typeof razorpay.subscriptions.create>[0]);

    return json({
      subscriptionId: subscription.id,
      keyId,
      plan,
      billingCycle: "annual",
      planLabel: TIER_LABELS[plan],
    });
  } catch (err) {
    console.error("[create-subscription]", err);
    return json(
      { error: "Failed to create subscription. Please try again." },
      502,
    );
  }
};

function json(data: unknown, status = 200) {
  return new Response(JSON.stringify(data), {
    status,
    headers: { "Content-Type": "application/json" },
  });
}
