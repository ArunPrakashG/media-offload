/**
 * WordPress Admin helper — wraps common WP-admin UI interactions.
 */
import { expect, type Page } from "@playwright/test";
import * as fs from "fs";
import * as path from "path";

export const WP_URL = process.env.WP_URL ?? "http://localhost:8080";
export const WP_USER = process.env.WP_USER ?? "admin";
export const WP_PASS = process.env.WP_PASS ?? "password";

/** MinIO / S3 credentials for the local nimbus-test bucket. */
export const MINIO = {
  endpoint: process.env.MINIO_ENDPOINT ?? "http://minio:9000",
  /** URL reachable from the browser / inside WordPress container */
  endpointPublic: process.env.MINIO_PUBLIC_URL ?? "http://localhost:9000",
  bucket: process.env.MINIO_BUCKET ?? "nimbus-test",
  accessKey: process.env.MINIO_ACCESS_KEY ?? "minioadmin",
  secretKey: process.env.MINIO_SECRET_KEY ?? "minioadmin",
  region: process.env.MINIO_REGION ?? "us-east-1",
};

// ── Auth helpers ──────────────────────────────────────────────────────────────

/**
 * Log in to WP admin and store the session in the browser context's storage
 * state so subsequent navigations don't need to log in again.
 */
export async function wpLogin(page: Page): Promise<void> {
  await page.goto("/wp-login.php");
  await page.fill("#user_login", WP_USER);
  await page.fill("#user_pass", WP_PASS);
  await page.click("#wp-submit");
  await page.waitForURL(/wp-admin/);
}

// ── Nimbus settings helpers ───────────────────────────────────────────────────

export async function openNimbusSettings(page: Page, tab = ""): Promise<void> {
  const url = tab
    ? `/wp-admin/upload.php?page=nimbus&tab=${tab}`
    : "/wp-admin/upload.php?page=nimbus";
  await page.goto(url);
}

/**
 * Configure Nimbus storage credentials via the Settings tab to point at the
 * local MinIO S3-compatible bucket.
 *
 * Field names follow the pattern `nimbus_settings[key]` — keys come from
 * SettingsRegistrar (bucket, region, access_key, secret_key, endpoint,
 * custom_domain, use_path_style_endpoint).
 */
export async function configureMinIOCredentials(page: Page): Promise<void> {
  await openNimbusSettings(page, "storage");

  const opt = NIMBUS_OPTION_KEY;

  // Provider selector.
  const providerSelect = page.locator(`select[name="${opt}[provider]"]`);
  if (await providerSelect.isVisible({ timeout: 2_000 }).catch(() => false)) {
    await providerSelect.selectOption("s3");
  }

  // Core credential fields.
  await setField(page, `${opt}[bucket]`, MINIO.bucket);
  await setField(page, `${opt}[region]`, MINIO.region);
  await setField(page, `${opt}[access_key]`, MINIO.accessKey);
  await setField(page, `${opt}[secret_key]`, MINIO.secretKey);
  await setField(page, `${opt}[endpoint]`, MINIO.endpoint);
  await setField(
    page,
    `${opt}[custom_domain]`,
    `${MINIO.endpointPublic}/${MINIO.bucket}`,
  );

  // Force path-style for MinIO compatibility.
  const pathStyle = page.locator(
    `input[name="${opt}[use_path_style_endpoint]"]`,
  );
  if (await pathStyle.isVisible({ timeout: 1_000 }).catch(() => false)) {
    if (!(await pathStyle.isChecked())) await pathStyle.check();
  }

  // Enable URL rewriting so source_url gets updated.
  const serveFromStorage = page.locator(
    `input[name="${opt}[serve_from_storage]"]`,
  );
  if (await serveFromStorage.isVisible({ timeout: 1_000 }).catch(() => false)) {
    if (!(await serveFromStorage.isChecked())) await serveFromStorage.check();
  }

  // Enable offloading.
  const enabled = page.locator(`input[name="${opt}[enabled]"]`);
  if (await enabled.isVisible({ timeout: 1_000 }).catch(() => false)) {
    if (!(await enabled.isChecked())) await enabled.check();
  }

  await page.click(
    'input[type="submit"][name="submit"], button[type="submit"]',
  );
  await expect(page.locator(".notice-success, .updated").first()).toBeVisible({
    timeout: 10_000,
  });
}

const NIMBUS_OPTION_KEY = "nimbus_settings";

/** Helper: fill a named `<input>` or `<textarea>`. */
async function setField(
  page: Page,
  name: string,
  value: string,
): Promise<void> {
  const el = page
    .locator(`input[name="${name}"], textarea[name="${name}"]`)
    .first();
  if (await el.isVisible({ timeout: 1_000 }).catch(() => false)) {
    await el.fill(value);
  }
}

// ── Media helpers ─────────────────────────────────────────────────────────────

/**
 * Upload a file via the WP REST API and return the attachment URL and ID.
 *
 * Using the REST API (instead of browser UI) is more reliable in headless tests
 * and fires the same PHP hooks (filter_attachment_metadata etc.) that the
 * plugin listens to, so the offload lifecycle still gets exercised.
 */
export async function uploadMedia(
  page: Page,
  filePath: string,
): Promise<{ attachmentId: number; localUrl: string }> {
  const fileName = path.basename(filePath);
  const fileBuffer = fs.readFileSync(filePath);
  const ext = path.extname(filePath).toLowerCase();
  const mimeTypes: Record<string, string> = {
    ".jpg": "image/jpeg",
    ".jpeg": "image/jpeg",
    ".png": "image/png",
    ".gif": "image/gif",
    ".webp": "image/webp",
  };
  const contentType = mimeTypes[ext] ?? "application/octet-stream";

  // WP REST API write operations via cookie auth require a nonce in X-WP-Nonce.
  // Navigate to a WP admin page to get the nonce injected into wpApiSettings.
  await page.goto("/wp-admin/");
  const nonce: string = await page.evaluate(
    () => (window as any).wpApiSettings?.nonce ?? "",
  );

  const response = await page.request.post("/?rest_route=/wp/v2/media", {
    headers: {
      "Content-Disposition": `attachment; filename="${fileName}"`,
      "Content-Type": contentType,
      ...(nonce
        ? { "X-WP-Nonce": nonce }
        : {
            Authorization:
              "Basic " +
              Buffer.from(`${WP_USER}:${WP_PASS}`).toString("base64"),
          }),
    },
    data: fileBuffer,
  });

  if (!response.ok()) {
    const body = await response.text().catch(() => "");
    throw new Error(
      `Media upload failed ${response.status()}: ${body.slice(0, 200)}`,
    );
  }

  // WP may output PHP notices before the JSON body — strip non-JSON prefix.
  const rawText = await response.text();
  const jsonStart = rawText.indexOf("{");
  const json =
    jsonStart >= 0 ? JSON.parse(rawText.slice(jsonStart)) : JSON.parse(rawText);

  const attachmentId = (json.id as number) ?? 0;
  const localUrl = (json.source_url as string) ?? "";

  return { attachmentId, localUrl };
}

/**
 * Trigger WP-Cron via a page-load hit (unreliable in test containers due to
 * cron-lock semantics). Prefer `forceOffload` for offload lifecycle tests.
 */
export async function runWpCron(page: Page): Promise<void> {
  // Call without `doing_wp_cron` param so WP acquires a fresh lock.
  await page.request.get("/wp-cron.php").catch(() => {});
}

/**
 * Force-run the Nimbus offload synchronously for a given attachment ID by
 * calling the test-only REST endpoint registered by mu-plugins/nimbus-test-helpers.php.
 * Returns the `source_url` after the offload completes.
 */
export async function forceOffload(
  page: Page,
  attachmentId: number,
): Promise<string> {
  // Always use getWpNonce which navigates to /wp-admin/ if the nonce is not
  // available on the current page (e.g. after visiting the Nimbus settings tab
  // which may not enqueue wp-api-settings on every sub-page).
  const nonce = await getWpNonce(page);
  const resp = await page.request.post(
    `/?rest_route=/nimbus-test/v1/offload/${attachmentId}`,
    { headers: { "X-WP-Nonce": nonce } },
  );
  if (!resp.ok()) {
    console.warn(`forceOffload ${attachmentId} returned ${resp.status()}`);
    return "";
  }
  const rawText = await resp.text();
  const jsonStart = rawText.indexOf("{");
  const json = jsonStart >= 0 ? JSON.parse(rawText.slice(jsonStart)) : {};
  return (json.source_url as string) ?? "";
}

/**
 * Get the WP REST API nonce from `wpApiSettings`, navigating to a WP admin
 * page first if needed.
 */
export async function getWpNonce(page: Page): Promise<string> {
  const nonce: string = await page.evaluate(
    () => (window as any).wpApiSettings?.nonce ?? "",
  );
  if (nonce) return nonce;
  await page.goto("/wp-admin/");
  return page.evaluate(() => (window as any).wpApiSettings?.nonce ?? "");
}

/**
 * Call a Nimbus REST API endpoint (`/nimbus/v1{endpoint}`) with the current
 * session nonce.  Returns `{ ok, status, body }` where `body` is the parsed
 * JSON value (object, array, or raw string on parse failure).
 */
export async function callNimbusApi(
  page: Page,
  endpoint: string,
  method: "GET" | "POST" | "DELETE" = "GET",
  data?: Record<string, unknown>,
): Promise<{ ok: boolean; status: number; body: unknown }> {
  const nonce = await getWpNonce(page);
  const resp = await page.request.fetch(`/?rest_route=/nimbus/v1${endpoint}`, {
    method,
    headers: {
      "X-WP-Nonce": nonce,
      ...(data !== undefined ? { "Content-Type": "application/json" } : {}),
    },
    ...(data !== undefined ? { data } : {}),
  });
  const rawText = await resp.text().catch(() => "{}");
  const start = rawText.search(/[{[]/);
  let body: unknown = null;
  try {
    body = start >= 0 ? JSON.parse(rawText.slice(start)) : rawText;
  } catch {
    body = rawText;
  }
  return { ok: resp.ok(), status: resp.status(), body };
}

/**
 * Read the raw value of a postmeta key directly from the DB (via the
 * nimbus-test mu-plugin endpoint).  Returns `null` when the meta is absent.
 */
export async function getPostMeta(
  page: Page,
  postId: number,
  metaKey: string,
): Promise<unknown> {
  const nonce = await getWpNonce(page);
  const resp = await page.request.get(
    `/?rest_route=/nimbus-test/v1/meta/${postId}/${encodeURIComponent(metaKey)}`,
    { headers: { "X-WP-Nonce": nonce } },
  );
  const rawText = await resp.text().catch(() => "{}");
  const start = rawText.search(/[{[]/);
  const json: Record<string, unknown> =
    start >= 0 ? JSON.parse(rawText.slice(start)) : {};
  return json.value ?? null;
}

/**
 * Read the raw (un-rendered) post_content stored in the DB for a post.
 * This lets tests verify what is actually persisted vs. what WP renders.
 */
export async function getRawPostContent(
  page: Page,
  postId: number,
): Promise<string> {
  const nonce = await getWpNonce(page);
  const resp = await page.request.get(
    `/?rest_route=/nimbus-test/v1/post/${postId}/raw`,
    { headers: { "X-WP-Nonce": nonce } },
  );
  const rawText = await resp.text().catch(() => "{}");
  const start = rawText.search(/[{[]/);
  const json: Record<string, unknown> =
    start >= 0 ? JSON.parse(rawText.slice(start)) : {};
  return (json.post_content as string) ?? "";
}

/**
 * Fetch the current value of `source_url` for an attachment via the REST API.
 */
export async function getAttachmentSourceUrl(
  page: Page,
  attachmentId: number,
): Promise<string> {
  const nonce = await getWpNonce(page);
  const headers = nonce
    ? { "X-WP-Nonce": nonce }
    : {
        Authorization:
          "Basic " + Buffer.from(`${WP_USER}:${WP_PASS}`).toString("base64"),
      };
  const response = await page.request.get(
    `/?rest_route=/wp/v2/media/${attachmentId}`,
    { headers },
  );
  const rawText = await response.text().catch(() => "{}");
  const jsonStart = rawText.indexOf("{");
  const json = jsonStart >= 0 ? JSON.parse(rawText.slice(jsonStart)) : {};
  return (json?.source_url as string) ?? "";
}

// ── Nimbus setting manipulation ───────────────────────────────────────────────

/**
 * Set a single `nimbus_settings` key via the test-helper REST endpoint.
 * Returns the previous value of the key so callers can restore it.
 *
 * Example: const prev = await setNimbusSetting(page, 'remove_local_copies', true);
 */
export async function setNimbusSetting(
  page: Page,
  key: string,
  value: unknown,
): Promise<unknown> {
  const nonce = await getWpNonce(page);
  const resp = await page.request.post("/?rest_route=/nimbus-test/v1/setting", {
    headers: {
      "X-WP-Nonce": nonce,
      "Content-Type": "application/json",
    },
    data: { key, value },
  });
  if (!resp.ok()) {
    throw new Error(
      `setNimbusSetting(${key}) failed with status ${resp.status()}`,
    );
  }
  const raw = await resp.text();
  const start = raw.indexOf("{");
  const json: Record<string, unknown> =
    start >= 0 ? JSON.parse(raw.slice(start)) : {};
  return json.old_value ?? null;
}

/**
 * Synchronously delete local copies of an attachment by firing the cleanup
 * hook immediately via the test-helper REST endpoint.
 * Returns whether the local file still exists after cleanup.
 */
export async function cleanupLocalFiles(
  page: Page,
  attachmentId: number,
): Promise<boolean> {
  const nonce = await getWpNonce(page);
  const resp = await page.request.post(
    `/?rest_route=/nimbus-test/v1/cleanup-local/${attachmentId}`,
    { headers: { "X-WP-Nonce": nonce } },
  );
  if (!resp.ok()) {
    console.warn(
      `cleanupLocalFiles(${attachmentId}) returned ${resp.status()}`,
    );
    return false;
  }
  const raw = await resp.text();
  const start = raw.indexOf("{");
  const json: Record<string, unknown> =
    start >= 0 ? JSON.parse(raw.slice(start)) : {};
  return (json.local_file_exists as boolean) ?? false;
}

/**
 * Upload a file via the WP REST API, overriding the filename in the
 * Content-Disposition header.  Useful for testing filename-collision logic
 * with specific names that differ from the fixture file's basename.
 */
export async function uploadMediaWithFilename(
  page: Page,
  filePath: string,
  filename: string,
): Promise<{ attachmentId: number; localUrl: string }> {
  const fileBuffer = fs.readFileSync(filePath);
  const ext = path.extname(filename).toLowerCase();
  const mimeTypes: Record<string, string> = {
    ".jpg": "image/jpeg",
    ".jpeg": "image/jpeg",
    ".png": "image/png",
    ".gif": "image/gif",
    ".webp": "image/webp",
  };
  const contentType = mimeTypes[ext] ?? "application/octet-stream";

  await page.goto("/wp-admin/");
  const nonce: string = await page.evaluate(
    () => (window as any).wpApiSettings?.nonce ?? "",
  );

  const response = await page.request.post("/?rest_route=/wp/v2/media", {
    headers: {
      "Content-Disposition": `attachment; filename="${filename}"`,
      "Content-Type": contentType,
      ...(nonce
        ? { "X-WP-Nonce": nonce }
        : {
            Authorization:
              "Basic " +
              Buffer.from(`${WP_USER}:${WP_PASS}`).toString("base64"),
          }),
    },
    data: fileBuffer,
  });

  if (!response.ok()) {
    const body = await response.text().catch(() => "");
    throw new Error(
      `Media upload failed ${response.status()}: ${body.slice(0, 200)}`,
    );
  }

  const rawText = await response.text();
  const jsonStart = rawText.indexOf("{");
  const json =
    jsonStart >= 0 ? JSON.parse(rawText.slice(jsonStart)) : JSON.parse(rawText);

  return {
    attachmentId: (json.id as number) ?? 0,
    localUrl: (json.source_url as string) ?? "",
  };
}
