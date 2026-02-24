# Botkibble

[![WordPress Version](https://img.shields.io/badge/WordPress-6.0%2B-blue.svg)](https://wordpress.org/download/)
[![PHP Version](https://img.shields.io/badge/PHP-8.2%2B-8892bf.svg)](https://www.php.net/downloads)
[![License](https://img.shields.io/badge/License-GPL--2.0-green.svg)](https://www.gnu.org/licenses/gpl-2.0.html)

**Botkibble** is a hardened WordPress plugin that serves your published posts and pages as clean Markdown with YAML frontmatter. It is optimized for AI agents, LLMs, and high-performance crawlers.

## Why Markdown?

HTML is rich but "noisy" for AI systems. Converting a blog post from HTML to Markdown can result in an **80% reduction in token usage** ([Cloudflare data](https://blog.cloudflare.com/markdown-for-agents/)), making it faster and significantly cheaper for AI agents to process your content.

This plugin implements origin-level Markdown serving, similar to Cloudflare's edge implementation, but with added benefits like physical file caching, YAML frontmatter, and custom filters.

## Key Features

- **Triple-Method Access:**
  - **.md suffix** (e.g., `example.com/blog-post.md`)
  - **Query parameter** (e.g., `example.com/blog-post/?format=markdown`)
  - **Content Negotiation** (e.g., `Accept: text/markdown` header)
- **Cache Variants (Optional):**
  - Request alternate cached representations by adding `?botkibble_variant=slim` (or any other variant name).
  - Variant caches are stored separately under `wp-content/uploads/botkibble-cache/_v/<variant>/...` to avoid collisions.
- **Rich YAML Frontmatter:** Includes title, date, categories, tags, `word_count`, `char_count`, and an estimated `tokens` count.
- **High-Performance Caching:** 
  - **Fast-Path Serving:** Bypasses the main WordPress query and template redirect for cached content.
  - **Static Offloading:** Caches Markdown as physical files in `wp-content/uploads/botkibble-cache/`.
- **SEO & Security:**
  - Sends `X-Robots-Tag: noindex` to prevent Markdown versions from appearing in search results.
  - Sends `Link: <url>; rel="canonical"` to point search engines back to the HTML version.
  - Rate limits cache-miss regenerations to mitigate DOS attacks.
  - Blocks access to drafts, private posts, and password-protected content.

## Installation

1. Upload the `botkibble` directory to your `wp-content/plugins/` directory.
2. Run `composer install` inside the plugin directory to install dependencies (`league/html-to-markdown` and `symfony/yaml`).
3. Activate the plugin through the **Plugins** menu in WordPress.
4. (Optional) Configure Nginx or Apache to serve the static cache files directly (see Performance section).

## Performance & Static Serving

Without any extra configuration, the plugin includes a "Fast-Path" that serves cached Markdown during PHP's `init` hook, before the main WordPress query runs. This alone cuts response time by about 10% compared to a full HTML page load.

For maximum performance, you can add a web server rule to serve the cached `.md` files directly from disk, bypassing PHP entirely. This drops response times from ~0.97s to ~0.11s — an **88% reduction** — because the server handles it the same way it would serve an image or CSS file.

### Nginx Configuration
```nginx
location ~* ^/(.+)\.md$ {
    default_type text/markdown;
    try_files /wp-content/uploads/botkibble-cache/$1.md /index.php?$args;
}
```

### Apache (.htaccess)
Add this before the WordPress rewrite rules:
```apache
RewriteEngine On
RewriteCond %{DOCUMENT_ROOT}/wp-content/uploads/botkibble-cache/$1.md -f
RewriteRule ^(.*)\.md$ /wp-content/uploads/botkibble-cache/$1.md [L,T=text/markdown]
```

The first request for any post still goes through PHP to generate and cache the Markdown. After that, all subsequent requests are served as static files.

## Developer Hooks (Customization)

The plugin is highly extensible via WordPress filters:

| Filter | Purpose |
| :--- | :--- |
| `botkibble_served_post_types` | Add custom post types (e.g., `docs`, `product`). |
| `botkibble_frontmatter` | Add or remove fields in the YAML block. |
| `botkibble_clean_html` | Clean up HTML (remove specific divs/styles) before conversion. |
| `botkibble_output` | Modify the final Markdown string before it's cached/served. |
| `botkibble_cache_variant` | Override the cache variant for the current request (used with `?botkibble_variant=...`). |
| `botkibble_cache_variants` | Return a list of cache variants to invalidate on post updates (e.g., `['slim']`). |
| `botkibble_token_multiplier` | Adjust the word-to-token estimation (default `1.3`). |
| `botkibble_regen_rate_limit` | Change the global regeneration rate limit (default `20/min`). |
| `botkibble_content_signal` | Customize the `Content-Signal` header. |
| `botkibble_enable_accept_header` | Toggle `Accept: text/markdown` detection. |

### Cache Variants

Botkibble can persist multiple cached Markdown representations for the same post without filename collisions.

- **Default cache**: `wp-content/uploads/botkibble-cache/<slug>.md`
- **Variant cache**: `wp-content/uploads/botkibble-cache/_v/<variant>/<slug>.md`

Request a variant by adding the query param:

- `?botkibble_variant=slim`

To ensure variants are invalidated when posts change, return the list of variants you use:

```php
add_filter( 'botkibble_cache_variants', function ( $variants, $post ) {
    $variants[] = 'slim';
    return $variants;
}, 10, 2 );
```

### Example: Adding Custom Post Types
```php
add_filter( 'botkibble_served_post_types', function ( $types ) {
    $types[] = 'knowledge_base';
    return $types;
} );
```

## Requirements

- **PHP:** 8.2+
- **WordPress:** 6.0+
- **Dependencies:** Managed via Composer (`league/html-to-markdown`, `symfony/yaml`).

## Benchmarks

Measured across 10 posts on a shared hosting environment (TTFB, 5 requests per cell averaged):

| # | Post | HTML | MD (cold) | MD (cached) | Apache direct |
|---|------|------|-----------|-------------|---------------|
| 1 | initial-commit | 1.060s | 0.981s | 0.955s | 0.119s |
| 2 | human-miles-per-gallon | 0.966s | 0.987s | 0.844s | 0.114s |
| 3 | burning-man-2018 | 0.939s | 0.952s | 0.830s | 0.109s |
| 4 | milky-way-over-penland | 0.958s | 0.917s | 0.873s | 0.107s |
| 5 | scratch-made-pizza | 0.948s | 0.940s | 0.904s | 0.112s |
| 6 | running-cable | 0.974s | 0.952s | 0.929s | 0.107s |
| 7 | nishika-n8000 | 0.924s | 0.963s | 0.849s | 0.112s |
| 8 | home-grown-corn | 0.941s | 0.980s | 0.823s | 0.107s |
| 9 | title-case | 1.128s | 0.944s | 0.897s | 0.109s |
| 10 | yet-another-bed | 0.873s | 0.895s | 0.812s | 0.115s |
| | **Average** | **0.97s** | **0.95s** | **0.87s** | **0.11s** |

- **HTML** — Standard WordPress page load
- **MD (cold)** — First Markdown request, no cache (runs `the_content` filters + HTML-to-Markdown conversion)
- **MD (cached)** — Subsequent requests served by the PHP Fast-Path from disk cache
- **Apache direct** — Static `.md` file served by Apache rewrite rule, bypassing PHP entirely

## License

This project is licensed under the GPL-2.0 License.
