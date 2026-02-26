=== Botkibble ===
Contributors: gregrandall
Tags: markdown, ai, agents, crawlers, api
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 8.2
Stable tag: 1.3.0
License: GPL-2.0-only
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Serves every published post and page as Markdown for AI agents and crawlers. No configuration, no API keys. Activate and it works.

== Description ==

AI agents, LLMs, and crawlers have to wade through navigation bars, sidebars, ads, and comment forms to reach the content they want, and every element costs tokens. [Cloudflare measured](https://blog.cloudflare.com/markdown-for-agents/) an 80% reduction in token usage when converting a blog post from HTML to Markdown (16,180 tokens down to 3,150).

Botkibble adds a Markdown endpoint to every published post and page.

Cloudflare offers [Markdown for Agents](https://developers.cloudflare.com/fundamentals/reference/markdown-for-agents/) at the CDN edge on Pro, Business, and Enterprise plans. Botkibble does the same thing (for free) at the origin, so it works on any host.

[GitHub Repository](https://github.com/greg-randall/botkibble)

**Three ways to request Markdown:**

* **`.md` suffix**: append `.md` to any post or page URL (e.g. `example.com/my-post.md`)
* **Query parameter**: add `?format=markdown` to any post or page URL
* **Content negotiation**: send `Accept: text/markdown` in the request header

**What's in every response:**

* Structured metadata header with title, date, categories, tags, word count, character count, and estimated token count (in YAML frontmatter format, readable by any AI agent)
* Clean Markdown converted from fully-rendered post HTML (shortcodes run, filters applied)
* `Content-Type: text/markdown` and `Vary: Accept` response headers
* `Content-Signal` header for AI signal declaration — defaults to `ai-train=no, search=yes, ai-input=yes` — see [contentsignals.org](https://contentsignals.org/)
* `X-Markdown-Tokens` header with estimated token count
* Discovery via `<link rel="alternate">` in the HTML head and `Link` HTTP header
* Automatic cache invalidation when a post is updated or deleted

**Performance:**

Botkibble writes Markdown to disk on the first request, then serves it as a static file. A built-in Fast-Path serves cached files during WordPress's `init` hook, before the main database query runs. No extra configuration needed.

Add a web server rewrite rule and Botkibble bypasses PHP entirely, serving `.md` files the same way a server would serve an image or CSS file:

| Method | Avg. response time |
|---|---|
| Standard HTML | 0.97s |
| Markdown (cold, first request) | 0.95s |
| Markdown (cached, PHP Fast-Path) | 0.87s |
| Markdown (Nginx/Apache direct) | 0.11s |

Serving directly from disk is **88% faster** than a full WordPress page load. See the Performance section below for Nginx and Apache configuration.

**Security:**

* Drafts, private posts, and password-protected content return `403 Forbidden`
* Rate limits cache-miss regenerations (20/min by default) to mitigate DoS abuse
* `X-Robots-Tag: noindex` keeps Markdown versions out of search results
* `Link: rel="canonical"` points search engines back to the HTML version

**Cache variants (optional):**

You can persist alternate cached representations by adding `?botkibble_variant=slim` (or any other variant name).
Variant caches are stored under:

    /wp-content/uploads/botkibble/_v/<variant>/<slug>.md

**What it does NOT do:**

* Expose drafts, private posts, or password-protected content
* Serve non-post/page content types by default
* Require any configuration. Activate and it works.

== Why Markdown? ==

HTML is expensive for AI systems to process. [Cloudflare measured](https://blog.cloudflare.com/markdown-for-agents/) an 80% reduction in token usage when converting a blog post from HTML to Markdown (16,180 tokens down to 3,150).

Cloudflare now offers [Markdown for Agents](https://developers.cloudflare.com/fundamentals/reference/markdown-for-agents/) at the CDN edge via the `Accept: text/markdown` header, available on Pro, Business, and Enterprise plans.

This plugin does the same thing at the origin, so it works on any host. It also adds `.md` suffix URLs, `?format=markdown` query parameters, YAML frontmatter, static file caching, and server-level offloading.

If you use Cloudflare, both share the same `Accept: text/markdown` header, `Content-Signal` headers, and `X-Markdown-Tokens` response headers.

Cloudflare currently defaults to `Content-Signal: ai-train=yes, search=yes, ai-input=yes` with no way to change it. Botkibble defaults to `ai-train=no` and lets you override the full signal per site via the `botkibble_content_signal` filter.

== Performance & Static Offloading ==

This plugin supports static file offloading by writing Markdown content to `/wp-content/uploads/botkibble/`. 

=== Nginx Configuration ===
To bypass PHP entirely and have Nginx serve the files (including variants) directly:

`
# Variants
location ~* ^/(_v/[^/]+/.+)\.md$ {
    default_type text/markdown;
    try_files /wp-content/uploads/botkibble/$1.md /index.php?$args;
}

# Default
location ~* ^/(.+)\.md$ {
    default_type text/markdown;
    try_files /wp-content/uploads/botkibble/$1.md /index.php?$args;
}
`

=== Apache (.htaccess) ===
Add this to your `.htaccess` before the WordPress rules:

`
RewriteEngine On
# Variants
RewriteCond %{DOCUMENT_ROOT}/wp-content/uploads/botkibble/_v/$1/$2.md -f
RewriteRule ^_v/([^/]+)/(.+)\.md$ /wp-content/uploads/botkibble/_v/$1/$2.md [L,T=text/markdown]

# Default
RewriteCond %{DOCUMENT_ROOT}/wp-content/uploads/botkibble/$1.md -f
RewriteRule ^(.*)\.md$ /wp-content/uploads/botkibble/$1.md [L,T=text/markdown]
`

Even without these rules, the plugin uses a "Fast-Path" that serves cached files from PHP before the main database query is executed.

== Installation ==

1. Upload the `botkibble` directory to `wp-content/plugins/`.
2. Run `composer install` inside the plugin directory to install dependencies.
3. Activate the plugin through the **Plugins** menu in WordPress.
4. That's it. No settings page needed.

**Test it:**

    curl https://gregr.org/great-hvac-meltdown/?format=markdown
    curl https://gregr.org/great-hvac-meltdown.md
    curl -H "Accept: text/markdown" https://gregr.org/great-hvac-meltdown/

== Frequently Asked Questions ==

= How do I add support for custom post types? =

The plugin only serves posts and pages by default. To add a custom post type, use the `botkibble_served_post_types` filter in your theme or a custom plugin:

    add_filter( 'botkibble_served_post_types', function ( $types ) {
        $types[] = 'docs';
        return $types;
    } );

Be careful. Only add post types that contain public content. Do not expose post types that may contain private or sensitive data (e.g. WooCommerce orders).

= What does the YAML frontmatter include? =

Every response starts with a YAML block containing:

* `title` — the post title
* `date` — publish date in ISO 8601 format
* `type` — post type (e.g. `post`, `page`)
* `word_count` — word count of the Markdown body
* `char_count` — character count of the Markdown body
* `tokens` — estimated token count (word_count × 1.3)
* `categories` — array of category names (posts only)
* `tags` — array of tag names (posts only, omitted if none)

Example:

    ---
    title: My Post Title
    date: '2025-06-01T12:00:00+00:00'
    type: post
    word_count: 842
    char_count: 4981
    tokens: 1095
    categories:
      - Technology
    tags:
      - wordpress
      - markdown
    ---

= How do I add custom fields to the frontmatter? =

Use the `botkibble_frontmatter` filter:

    add_filter( 'botkibble_frontmatter', function ( $data, $post ) {
        $data['excerpt'] = get_the_excerpt( $post );
        return $data;
    }, 10, 2 );

= How do I change the Content-Signal header? =

Use the `botkibble_content_signal` filter:

    add_filter( 'botkibble_content_signal', function ( $signal, $post ) {
        return 'ai-train=no, search=yes, ai-input=yes';
    }, 10, 2 );

Return an empty string to omit the header entirely.

= Can I change the token count estimation? =

Yes, use the `botkibble_token_multiplier` filter. The default multiplier of `1.3` (word count × 1.3) comes from [Cloudflare's implementation](https://developers.cloudflare.com/fundamentals/reference/markdown-for-agents/):

    add_filter( 'botkibble_token_multiplier', function () {
        return 1.5; // Adjusted for a different model's tokenizer
    } );

= How do I adjust the rate limit? =

Cache misses (when a post needs to be converted) are limited to 20 per minute globally. You can change this with the `botkibble_regen_rate_limit` filter:

    add_filter( 'botkibble_regen_rate_limit', function () {
        return 50; 
    } );

= Can I add custom HTML cleanup rules? =

Yes, use the `botkibble_clean_html` filter. This runs after the default cleanup and before conversion:

    add_filter( 'botkibble_clean_html', function ( $html ) {
        // Remove a plugin's wrapper divs
        $html = preg_replace( '/<div class="my-plugin-wrapper">(.*?)<\/div>/s', '$1', $html );
        return $html;
    } );

= Can I strip script nodes during conversion? =

Yes. Botkibble keeps converter node removal disabled by default (for backward compatibility), but you can opt in with `botkibble_converter_remove_nodes`:

    add_filter( 'botkibble_converter_remove_nodes', function ( $nodes ) {
        $nodes = is_array( $nodes ) ? $nodes : [];
        $nodes[] = 'script';
        return $nodes;
    } );

If you also need `application/ld+json`, extract it in `botkibble_clean_html` first, then let converter-level script removal clean up any remaining script tags.
= How do I modify the body before metrics are calculated? =

Use the `botkibble_body` filter. This is the best place to add content like ld+json that you want included in the word count and token estimation:

    add_filter( 'botkibble_body', function ( $body, $post ) {
        $json_ld = '<script type="application/ld+json">...</script>';
        return $body . "\n\n" . $json_ld;
    }, 10, 2 );

= How do I modify the final Markdown output? =

Use the `botkibble_output` filter to append or modify the text after conversion:

    add_filter( 'botkibble_output', function ( $markdown, $post ) {
        return $markdown . "\n\n---\nServed by Botkibble";
    }, 10, 2 );

= Can I cache multiple Markdown variants (e.g. a slim version)? =

Yes. Add `?botkibble_variant=slim` when requesting Markdown to generate and serve a separate cached file. To ensure your variants are invalidated on post updates, return them from the `botkibble_cache_variants` filter:

    add_filter( 'botkibble_cache_variants', function ( $variants, $post ) {
        $variants[] = 'slim';
        return $variants;
    }, 10, 2 );

= Can I disable the Accept header detection? =

Yes, if you only want to serve Markdown via explicit URLs (.md or ?format=markdown), use the `botkibble_enable_accept_header` filter:

    add_filter( 'botkibble_enable_accept_header', '__return_false' );

= Does the .md suffix work with all permalink structures? =

It works with the most common structures (post name, page hierarchy). Complex date-based permalink structures may require the query parameter or Accept header method instead.

= What about password-protected posts? =

They return a `403 Forbidden` response. There's no point serving a password form to a bot.

= What are the response headers? =

* `Content-Type: text/markdown; charset=utf-8`
* `Vary: Accept` — tells caches that responses vary by Accept header
* `X-Markdown-Tokens: <count>` — estimated token count (word_count × 1.3)
* `X-Robots-Tag: noindex` — prevents search engines from indexing the Markdown version
* `Link: <url>; rel="canonical"` — points search engines to the original HTML post
* `Link: <url>; rel="alternate"` — advertises the Markdown version for discovery
* `Content-Signal: ai-train=no, search=yes, ai-input=yes` — see [contentsignals.org](https://contentsignals.org/)

== Credits ==

We thank Cristi Constantin (https://github.com/cristi-constantin) for contributing cache variants, URL and SEO improvements, and fixing important bugs.

== Changelog ==

= 1.3.0 =
* Changed default Content-Signal from ai-train=yes to ai-train=no (opt-out of AI training by default).
* Added botkibble_converter_remove_nodes filter for opt-in HTML node stripping during conversion.

= 1.2.1 =
* Changed cache directory from /uploads/botkibble-cache/ to /uploads/botkibble/ per plugin guidelines.

= 1.2.0 =
* Rebranded to Botkibble to avoid naming ambiguity.
* Prefixed all functions, filters, and constants with botkibble_ for better compatibility.
* Updated symfony/yaml to 7.4.1 (Requires PHP 8.2).
* Corrected all internal references and documentation.

= 1.1.2 =
* Fixed routing issues for posts by implementing a custom botkibble_path resolver.
* Disabled canonical redirects for .md URLs to prevent 301 trailing slash loops.
* Added automatic version-based rewrite rule flushing.

= 1.1.0 =
* Replaced manual YAML encoder with symfony/yaml for security.
* Replaced regex-based shortcode removal with native strip_shortcodes().
* Added token estimation based on 1.3 word count heuristic.
* Replaced transients with static file offloading in /uploads/.
* Added SEO protection with noindex and canonical headers.
* Added "Fast-Path" serving to bypass main DB queries for cached content.
* Added support for direct server offloading (Nginx/Apache).

= 1.0.0 =
* Initial release.
* HTML-to-Markdown conversion via league/html-to-markdown.
* .md suffix, query parameter, and Accept header support.
* YAML frontmatter with title, date, categories, tags.
* Static file caching with automatic invalidation.
* Content-Signal and X-Markdown-Tokens response headers.
* Discovery via alternate link tag.
