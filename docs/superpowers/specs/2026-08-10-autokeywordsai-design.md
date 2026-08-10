# AutoKeywordsAI — design

**Date:** 2026-08-10
**Status:** approved (design); implementation plan not yet written

## Problem

WooCommerce stores running Rank Math SEO ship products with an empty focus keyword
(`rank_math_focus_keyword`). Rank Math scores every other on-page signal against that
field, so an empty one means no SEO analysis at all. Filling it by hand does not scale past
a few dozen products, and Rank Math's own AI feature (Content AI) is a paid credit product.

The driving case is a store whose catalogue is already published on live hosting — so the
fix has to run in production wp-admin, not from a local WP-CLI shell.

## Goal

A general-purpose WordPress plugin that generates Rank Math focus keywords for WooCommerce
products using an LLM, with the provider configurable so store owners can use a free tier
(Gemini) or any OpenAI-compatible endpoint.

## Scope

**In scope**

- Write `rank_math_focus_keyword` only (primary + up to 2 secondary keywords).
- Bulk fill for products whose keyword is currently empty.
- Auto-fill a newly published product that has no keyword.
- Two providers: Gemini, and OpenAI-compatible (configurable base URL).
- Settings screen with masked API key, model, target language, rate limit, test button.

**Explicitly out of scope (YAGNI)**

- `rank_math_title` and `rank_math_description`. Can be added later behind the same
  writer seam; deliberately excluded to keep the review surface small.
- A review/approval queue. Keywords are written directly, but only where the field is
  empty, so no manual work is ever overwritten.
- Overwriting or regenerating existing keywords.
- Yoast support. The writer is a separate class so a second writer is additive, but no
  Yoast code is written now.
- wordpress.org submission (readme.txt, review guidelines, screenshots).
- Keyword *validation* against real search volume. An LLM invents plausible keywords; it
  has no search-volume data. This is documented as a known limitation, not solved.

## Decisions and rationale

| Decision | Rationale |
|---|---|
| A plugin, not a WP-CLI script | The catalogue is live in production; a local shell script cannot reach it, and the shop owner (not a developer) must be able to run it. |
| Standalone repo, plugin at repo root | It is general-purpose, not store-specific; repo-is-plugin is the cleanest layout for a distributable zip. |
| Write directly, skip filled fields | Chosen over a review queue for simplicity. Skipping non-empty fields means hand-tuned keywords are safe, which removes most of the risk a queue would have mitigated. |
| Action Scheduler for all execution | Auto-fill-on-publish needs async anyway (a publish request must not block on an LLM call). Using one queue for both entry points avoids two execution engines doing the same job. Action Scheduler ships with WooCommerce, so it is not a new dependency, and WooCommerce → Status → Scheduled Actions gives a free audit log. |
| One scheduled action per product, staggered | Respects free-tier rate limits by construction; no `sleep()` in a request, no PHP timeout risk, per-product retry, and resumable. |
| Prompt lives outside the providers | Prompt quality work happens once, in a pure class, instead of being duplicated per vendor. |
| Model as free text, not a dropdown | Model names churn fast; a hardcoded list rots and blocks users from new models. |

## Requirements

- WordPress with WooCommerce active (products, and Action Scheduler).
- Rank Math SEO active.
- PHP 8.1+.
- Plugin activation fails with an admin notice if WooCommerce or Rank Math is inactive.

## Architecture

Slug `autokeywordsai`, function/class prefix `akai_` / `AKAI_`, text domain
`autokeywordsai`.

```
autokeywordsai.php                          plugin header, constants, dependency guard, bootstrap
includes/
  class-akai-prompt.php                     PURE: product data + language -> provider-neutral spec
  interface-akai-provider.php               generate( array $spec ): array|WP_Error
  class-akai-provider-gemini.php            spec -> Gemini wire format -> keyword array
  class-akai-provider-openai.php            spec -> OpenAI-compatible wire format -> keyword array
  class-akai-provider-factory.php           returns the configured provider
  class-akai-keyword-writer.php             skip-if-filled rule, sanitization, meta write
  class-akai-queue.php                      Action Scheduler enqueue, action handler, publish hook
  class-akai-settings.php                   settings page, options, test-connection handler
  class-akai-logger.php                     wc_get_logger() wrapper + capped recent-errors list
tests/
  run.php                                   test runner (CLI only)
  *.test.php                                per-unit tests
  fixtures/                                  canned provider responses
bin/build-zip.sh                            builds an installable zip
.github/workflows/ci.yml                    tests + PHPCS (WordPress Coding Standards)
.github/workflows/release.yml               tag -> attach zip
```

### Unit boundaries

Each unit answers: what it does, how it is used, what it depends on.

- **`AKAI_Prompt`** — builds a provider-neutral spec: system instruction, user text, and a
  JSON schema (`{ primary: string, secondary: string[] }`). Depends on nothing (no WP, no
  HTTP), so it is directly unit-testable. All prompt-quality iteration happens here.
- **`AKAI_Provider`** (interface) — the single seam. Adding a provider is one new file.
- **`AKAI_Provider_Gemini`** — POSTs to
  `generativelanguage.googleapis.com/v1beta/models/{model}:generateContent` with the key in
  the `x-goog-api-key` header, requesting JSON output via `generationConfig`
  (`responseMimeType` + `responseSchema`).
- **`AKAI_Provider_OpenAI`** — POSTs to `{base_url}/chat/completions` with
  `Authorization: Bearer`, requesting JSON via `response_format` with a JSON schema.
  Because the base URL is a setting, this one class covers OpenAI, Groq, OpenRouter,
  DeepSeek, Together, Ollama and LM Studio.
- **`AKAI_Keyword_Writer`** — owns the only `update_post_meta` call. Its skip and sanitize
  decisions are pure functions, tested independently of WordPress.
- **`AKAI_Queue`** — owns both entry points and all retry/backoff policy.
- **`AKAI_Settings`** — owns options, capability and nonce checks, and the settings UI.

> **Implementation note:** exact request/response shapes for both providers, and the
> Action Scheduler function signatures, are to be verified against current vendor and
> Action Scheduler documentation at implementation time rather than taken from this
> document. The seams above are the design; the wire details are not frozen here.

## Data flow

### Bulk run

1. Owner clicks **Generar keywords faltantes** on the settings screen.
2. Handler verifies the nonce and the `manage_woocommerce` capability.
3. Query published product IDs where `rank_math_focus_keyword` does not exist or is empty.
4. Schedule one action per product, staggered: product *i* at
   `now + i × (60 / requests_per_minute)`. `requests_per_minute` is a setting, default 10.
5. Each action: build the spec from the product (title, categories, short description),
   call the configured provider, hand the result to the writer.

If no API key is configured, nothing is enqueued and an admin notice explains why.

### Auto-fill on publish

`transition_post_status` → if the new status is `publish`, the post type is `product`, and
the keyword is empty, enqueue one async action for that product. Same handler as the bulk
path.

## Failure handling

Per product:

| Failure | Behaviour |
|---|---|
| HTTP 429 / rate limited | Reschedule that product 5 minutes out. Not counted as a failure — expected on free tiers. |
| Network error or 5xx | Retry once with backoff; attempt count travels in the action args. Then record as failed. |
| Malformed or empty JSON | Record as failed. **Never** write a partial or empty keyword — doing so would silently mark the product as "done" and exclude it from future runs. |
| Missing API key | Nothing enqueued; admin notice. |

**Logging.** `wc_get_logger()` with source `autokeywordsai`, giving WooCommerce's built-in
log viewer for free, plus a capped list of the last 50 errors (`{product_id, message,
time}`) rendered on the settings page. Silent failure is the main way a tool like this
rots, so failures must be visible without leaving wp-admin.

**Sanitization before writing.** Trim each keyword, drop empties, cap at 3, strip commas
(the field's own delimiter), and require a non-empty primary. Failing that check is treated
as malformed.

## Settings

Under **Productos → AutoKeywordsAI**:

- **Provider** — radio: Gemini / OpenAI-compatible.
- **API key** — masked, write-only (only written when the submitted value is non-empty, so
  the key never round-trips to the browser). An `AKAI_API_KEY` constant in `wp-config.php`
  takes precedence, keeping the key out of the database and out of any Duplicator package.
- **Base URL** — shown only for OpenAI-compatible; prefilled `https://api.openai.com/v1`.
- **Model** — free text, per-provider placeholder (`gemini-2.5-flash`, `gpt-4o-mini`).
- **Target language** — defaults to the site locale.
- **Requests per minute** — default 10.
- **Test connection** — runs one throwaway product through the full chain and shows the raw
  result.
- **Status** — pending and failed counts read from Action Scheduler, plus the recent-error
  list.

## Testing strategy

Dependency-free CLI tests (WordPress functions stubbed as needed). Run with
`php tests/run.php`.

- `AKAI_Prompt` spec building — pure, no stubs.
- Each provider's request builder and response parser, against canned fixtures. Provider
  bugs live here, and these tests need no network.
- Writer skip and sanitize decisions, including rejection of an empty primary.
- `AKAI_Queue` handler against a fake provider implementing the interface — exercises the
  429 and retry paths with no HTTP.

CI runs the suite on PHP 8.1, 8.2 and 8.3 plus PHPCS against WordPress Coding Standards.

## Distribution

`bin/build-zip.sh` produces an installable zip; a tag-triggered workflow attaches it to the
release. Installation is through the wp-admin plugin uploader, so no host credentials are
needed.

## Known limitations

- Generated keywords are plausible, not volume-validated. Reviewing the primary keyword for
  the top ~20 sellers against Google autocomplete is worthwhile; the plugin does not do it.
- Rank Math only. No Yoast writer.
- Focus keyword only. SEO title and meta description are untouched.
