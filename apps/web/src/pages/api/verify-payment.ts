export const prerender = false;

import type { APIRoute } from "astro";
import { createHmac, timingSafeEqual } from "node:crypto";

export const POST: APIRoute = async ({ request }) => {
  const keySecret = import.meta.env.RAZORPAY_KEY_SECRET;
  const backendUrl = import.meta.env.BACKEND_URL;
  const adminSecret = import.meta.env.ADMIN_API_SECRET;

  if (!keySecret) {
    return json({ error: "Payment gateway not configured." }, 503);
  }

  let body: {
    subscriptionId?: string;
    paymentId?: string;
    signature?: string;
    plan?: string;
    billingCycle?: string;
    email?: string;
  };

  try {
    body = await request.json();
  } catch {
    return json({ error: "Invalid request body." }, 400);
  }

  const { subscriptionId, paymentId, signature, plan, billingCycle, email } =
    body;

  if (!subscriptionId || !paymentId || !signature) {
    return json({ error: "Missing payment details." }, 400);
  }

  // Razorpay subscription payment signature: HMAC-SHA256(paymentId + "|" + subscriptionId, key_secret).
  // Using timing-safe comparison to prevent timing side-channel attacks.
  const expected = createHmac("sha256", keySecret)
    .update(`${paymentId}|${subscriptionId}`)
    .digest("hex");

  const expectedBuf = Buffer.from(expected, "hex");
  const receivedBuf = Buffer.from(signature, "hex");

  const valid =
    expectedBuf.length === receivedBuf.length &&
    timingSafeEqual(expectedBuf, receivedBuf);

  if (!valid) {
    return json({ error: "Payment verification failed." }, 400);
  }

  // Issue a license via the Go backend.
  if (!backendUrl || !adminSecret) {
    console.warn(
      "[verify-payment] BACKEND_URL or ADMIN_API_SECRET not configured",
      {
        subscriptionId,
        paymentId,
        plan,
        email,
      },
    );
    return json({ success: true });
  }

  const issueResp = await fetch(`${backendUrl}/admin/v1/licenses/issue`, {
    method: "POST",
    headers: {
      "Content-Type": "application/json",
      Authorization: `Bearer ${adminSecret}`,
    },
    body: JSON.stringify({
      email,
      tier: plan,
      payment_id: paymentId,
      subscription_id: subscriptionId,
      billing_cycle: billingCycle ?? "monthly",
    }),
  }).catch((err) => {
    console.error("[verify-payment] License issuance request failed", err);
    return null;
  });

  if (!issueResp?.ok) {
    const detail = await issueResp?.text().catch(() => "");
    console.error("[verify-payment] License issuance failed", {
      status: issueResp?.status,
      detail,
    });
    return json(
      {
        error:
          "Payment captured but license issuance failed. Email hello@usenimbus.dev with your payment ID.",
      },
      500,
    );
  }

  console.info("[verify-payment] License issued", {
    subscriptionId,
    paymentId,
    plan,
    email,
  });

  return json({ success: true });
};

function json(data: unknown, status = 200) {
  return new Response(JSON.stringify(data), {
    status,
    headers: { "Content-Type": "application/json" },
  });
}
