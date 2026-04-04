// Thin proxy — all Razorpay logic (credentials, subscription creation) lives in
// the Go backend. This route simply forwards the request and streams the response.
export const prerender = false;

import type { APIRoute } from "astro";

export const POST: APIRoute = async ({ request }) => {
  const backendUrl = import.meta.env.BACKEND_URL;
  if (!backendUrl) {
    return json({ error: "Backend not configured." }, 503);
  }

  let body: unknown;
  try {
    body = await request.json();
  } catch {
    return json({ error: "Invalid request body." }, 400);
  }

  const upstream = await fetch(
    `${backendUrl}/v1/checkout/create-subscription`,
    {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(body),
    },
  ).catch(() => null);

  if (!upstream) {
    return json(
      { error: "Failed to reach payment gateway. Please try again." },
      502,
    );
  }

  const data = await upstream.json().catch(() => ({}));
  return json(data, upstream.status);
};

function json(data: unknown, status = 200) {
  return new Response(JSON.stringify(data), {
    status,
    headers: { "Content-Type": "application/json" },
  });
}
