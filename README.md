# AutoKeywordsAI

Fills empty [Rank Math](https://rankmath.com) focus keywords on WooCommerce products
using an AI provider you configure.

- **Providers:** Google Gemini, or any OpenAI-compatible endpoint (OpenAI, Groq,
  OpenRouter, DeepSeek, Together, Ollama, LM Studio) via a configurable base URL.
- **Never overwrites** a keyword that already has a value, so hand-tuned keywords are safe.
- **Bulk fill** for the existing catalogue, plus automatic fill when a new product is
  published.
- Runs on Action Scheduler (bundled with WooCommerce), one staggered job per product, so
  free-tier rate limits are respected and nothing blocks a page load.

## Requirements

WordPress 6.4+, PHP 8.1+, WooCommerce, Rank Math SEO.

## Install

Download the zip from the releases page and upload it under
**Plugins → Add New → Upload Plugin**. Then configure
**Products → AutoKeywordsAI**.

The API key can be kept out of the database by defining it in `wp-config.php`:

```php
define( 'AKAI_API_KEY', 'your-key-here' );
```

## Known limitation

An LLM generates *plausible* keywords; it has no search-volume data. Reviewing the primary
keyword on your top sellers against Google autocomplete is worthwhile.

## Development

```bash
php tests/run.php        # unit tests, no WordPress or network required
composer install         # dev only
vendor/bin/phpcs         # WordPress Coding Standards
```
