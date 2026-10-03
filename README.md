# SynapseFabric SEO Abilities

A WordPress plugin that lets an AI assistant (Claude first, any MCP-capable assistant later) **audit and maintain a blog**: find duplicate and cannibalising posts, spot SEO and content-health problems, fix titles and descriptions, merge duplicates with 301 redirects, suggest internal links, and prepare new posts as drafts.

It is built on the core Abilities API (WordPress 6.9+) and the official [WordPress MCP Adapter](https://github.com/WordPress/mcp-adapter). It declares `Requires Plugins: mcp-adapter`.

**Safety model:** read abilities are always on; write abilities are **off by default** and appear to the AI only after an administrator enables each one. The AI can never publish, never changes a URL slug, never deletes anything. Every change is logged, and content edits save a revision. See [PLAYBOOK.md](PLAYBOOK.md) for how to run it.

## Abilities

| Ability | Type | What it does |
|---|---|---|
| `synapsefabric-seo/list-posts` | read | Paginated posts: id, title, slug, date, word count, categories, tags, status. |
| `synapsefabric-seo/get-post` | read | Full content, excerpt, terms and SEO meta (Yoast SEO or Rank Math). |
| `synapsefabric-seo/find-duplicates` | read | Groups posts by title + content similarity (3-word shingles, Jaccard) with per-pair scores. |
| `synapsefabric-seo/audit-site` | read | Scans for thin content, missing/badly sized meta descriptions and titles, images without alt text, orphan posts, unresolved or redirected internal links, missing subheadings, stale posts, no featured image, uncategorised. Prioritised, with a fix hint per problem. |
| `synapsefabric-seo/suggest-internal-links` | read | Related posts (TF-IDF cosine) plus the exact phrase in the text that could carry each link, in both directions. |
| `synapsefabric-seo/update-post` | **write** | Title, content, excerpt, SEO fields. Status and slug never change. Saves a revision. `dry_run` supported. |
| `synapsefabric-seo/merge-posts` | **write** | Retires a duplicate: 301 redirect, moves comments, merges categories/tags, sets the source to draft (nothing deleted). `dry_run` supported. |
| `synapsefabric-seo/create-draft` | **write** | New post as a **draft only**. Refuses exact duplicate titles. |

AI-supplied HTML is always passed through `wp_kses_post` (even for administrators), so a prompt-injected `<script>` cannot be saved through these abilities.

### Redirects
`merge-posts` uses the **Redirection** plugin's API when it is active, otherwise the plugin's own table and a `template_redirect` hook. Redirect loops are refused; existing redirects ending at the old URL are re-pointed so visitors never hit a chain; the old `?p=ID` short link is redirected too. Redirects made in our own table are listed (and can be deleted) at **Settings > SynapseFabric SEO**.

## Install

1. WordPress 6.9+, PHP 7.4+.
2. Install the **MCP Adapter** ([release zip](https://github.com/WordPress/mcp-adapter/releases/latest) `mcp-adapter.zip`) and this plugin; activate both.
3. **Settings > SynapseFabric SEO**: leave write abilities off to audit first, enable them one at a time when you are ready.

## Connect an AI

The adapter serves MCP at `https://YOUR-SITE/wp-json/mcp/mcp-adapter-default-server`. The AI acts as a WordPress user, so **create a dedicated user** with the lowest role that works (Editor can read and edit all posts; use Author to limit it to its own posts).

**Authentication.** The adapter itself accepts WordPress cookie / Application Password authentication. Claude.ai and ChatGPT custom connectors expect **OAuth 2.1**, which the adapter does not provide. Add an OAuth layer such as [akirk/mcp-connect](https://github.com/akirk/mcp-connect) (adds sign-in/consent, dynamic client registration, PKCE; bundles the adapter). It is new (v0.1.0) and **has not been tested with this plugin**; evaluate it on staging before trusting it.

- **Claude (claude.ai):** Settings > Connectors > Add custom connector > the MCP URL above, then complete the WordPress sign-in.
- **Claude Desktop / Claude Code / Cursor:** these can also use the adapter's STDIO transport through WP-CLI (see the adapter docs) or an Application Password over HTTPS.
- **Other assistants:** anything that speaks MCP over HTTP with a bearer/basic credential or OAuth works the same way.

Check an install from your machine (read-only):

```bash
bin/mcp-smoke-test.sh https://staging.example.com wp_username "application password"
```

## Local development (never test on a live site)

Requires Docker and Node.

```bash
npm install
npm run env:start     # http://localhost:8888 (admin / password); installs mcp-adapter and seeds posts with known problems
npm test              # unit tests for the pure-PHP engines (no WordPress needed)
composer install && composer lint   # WordPress Coding Standards
```

`bin/seed-test-content.sh` creates near-duplicates, a related pair, a draft, a broken link, an image without alt text and a thin post. `npm run env:reset` starts over.

## Verification status (what was actually run)

Tested against **WordPress core 7.2-alpha (master) on SQLite**, the **MCP Adapter** latest release zip, and **Redirection 5.10.1**, served by PHP's built-in server, using real HTTP/MCP calls:

- All 8 abilities register and are discoverable and executable over the MCP endpoint with an Application Password; unauthenticated requests get 401; a subscriber's credentials are denied on every ability.
- With a write ability switched off it is not registered at all.
- `update-post`: revision saved, slug/status unchanged, SEO fields written, scripts/iframes/handlers stripped. `create-draft`: always draft, duplicate/blank titles refused. `merge-posts`: live 301 over HTTP (own table **and** via Redirection), comments moved, source drafted, loop and unpublished-target guards.
- Uninstall removes both tables and options; re-activation recreates them.
- 39 unit tests (they fail when the code is deliberately broken) and `phpcs` with the WordPress standard report **0 errors, 0 warnings**.

**Not tested** (be aware before relying on them):
- **wp-env itself** (needs Docker; not available where this was built) and the `.wp-env.json` adapter download URL on a fresh machine.
- **MySQL/MariaDB** (tests ran on SQLite through WordPress's SQLite integration).
- **Real Yoast SEO / Rank Math**: field names follow their documented meta keys; tests used a stub that reports Yoast as active.
- **OAuth connection from claude.ai / ChatGPT / Gemini**, and mcp-connect specifically.
- Large sites: scans are capped (audit 1000 posts, duplicates 2000) and similarity is computed in PHP; try on staging first.
- Redirects made through the Redirection plugin are not yet considered by `audit-site`'s "link to redirected URL" check (only our own table is).

## Uninstall

Deleting the plugin removes its settings, the redirects table and the activity log table.
