=== WP MCP Control ===
Contributors: you
Tags: mcp, ai, claude, chatgpt, automation, woocommerce, polylang
Requires at least: 5.8
Tested up to: 6.6
Requires PHP: 7.0
Stable tag: 0.8.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Free, open Model Context Protocol (MCP) server for WordPress. Let Claude, ChatGPT and other MCP clients control your whole site.

== Description ==

WP MCP Control exposes a single Streamable-HTTP MCP endpoint that lets AI clients manage:

* Posts, pages and any custom post type — create, read, update, delete, meta.
* Taxonomies & terms — categories, tags, custom taxonomies, term assignment.
* Media — upload from URL or base64, edit metadata, featured images.
* Users — list, create, update, delete, roles.
* Comments — moderate, reply, delete.
* Site options, settings, navigation menus, site info.
* Plugins — list, install (from wordpress.org), activate, deactivate, update, delete.
* Themes — list, install, activate, update, delete.
* WooCommerce — products and orders (auto-enabled when WooCommerce is active).
* Polylang — languages, translations, linking (auto-enabled when Polylang is active).

Every action runs as a WordPress user you choose, so capability checks are always enforced.

= Requirements =

WordPress 5.8 or newer and PHP 7.0 or newer. Everything works the same on old and new installs; the only difference is that Full Site Editing content (templates, template parts, global styles, navigation) only exists on sites that have it — WordPress 5.9+ with a block theme. On an older or classic-theme site those post types simply are not registered and the post tools report them as unknown.

== Installation ==

1. Copy the `wp-mcp-control` folder to `wp-content/plugins/` and activate it.
2. Make sure Settings → Permalinks is set to any option other than "Plain".
3. Go to Settings → MCP Control and enable the server.

Then connect a client one of two ways:

Sign in with WordPress (claude.ai, recommended):
* In Claude, add a custom connector and paste just your site address (e.g. https://example.com/).
* Claude opens your WordPress login; approve the request once. Done — no token to handle. The connection acts as the user who signs in.

Token (Claude Desktop, Claude Code, scripts):
* On the settings page choose "Act as user", then "Generate / rotate token".
* Copy the ready-made connection URL or the Claude Desktop config shown at the top of the page.

== Frequently Asked Questions ==

= The client signs in, then says the server refused its request =

Almost always a filter in front of WordPress that blocks AI user agents, not this plugin.

The sign-in happens in your browser, so it passes. The MCP request that follows is made by the AI provider's own servers, carrying their own user agent — and that is what gets blocked. Hence the confusing "you signed in, but then it was refused".

Every provider uses separate agents for crawling and for requests a person actually asked for, and only the second kind has to be allowed:

* Claude — allow `Claude-User`; `ClaudeBot` and `Claude-SearchBot` may stay blocked.
* ChatGPT — allow `ChatGPT-User`; `GPTBot` and `OAI-SearchBot` may stay blocked.
* Perplexity — allow `Perplexity-User`; `PerplexityBot` may stay blocked.

Allowing the user-initiated agent does not open your site to AI training crawlers. Those arrive under the other names, and you can keep blocking every one of them.

To check your site from any machine with curl:

`curl -A "Claude-User/1.0 (+Claude-User@anthropic.com)" -o /dev/null -w "%{http_code}\n" https://example.com/`

200 means nothing is blocking. A 403, 405, 406 or 418 — especially as a plain HTML error page rather than WordPress output — means the request never reached WordPress. Run the same command without `-A` to confirm the user agent is the only difference.

Such a filter normally lives in one of three places, worth checking in this order: your hosting control panel (an option like "Block AI bots" or "AI crawler protection" — on shared hosting the rule is in the server's own nginx or Apache config, so you may have to ask support, and no plugin or .htaccess of yours can override it); Cloudflare or a similar CDN (Security → Bots → "Block AI Scrapers and Crawlers", or a custom WAF rule); or a security or "AI blocker" plugin that filters by user agent.

Ask for the user-initiated agent to be removed from the block list by name. Blocking it is rarely deliberate — these lists are usually copied wholesale from a published crawler list, without noticing that one entry on it is how your own integrations reach the site.

= Does the plugin update itself? =

Yes, from 0.6.0 on. New versions appear under Dashboard → Updates and on the Plugins screen with the ordinary "Update now" button, the same as a plugin from the directory. Releases are served from GitHub using the "Update URI" header WordPress has supported since 5.8; nothing extra has to be installed. Version 0.6.0 itself still has to be installed by hand once, because the update code has to be on the site before WordPress can be told where to look.

== Security ==

* The token is stored hashed (never in plaintext) and compared in constant time.
* Every tool enforces WordPress capabilities against the specific object it touches — not just a generic capability — so a non-admin configured user cannot edit content, users or settings it does not own.
* Option writes allow plugin and theme settings but block a fixed set of core options that control site access or infrastructure (users_can_register, default_role, siteurl, active_plugins, uninstall_plugins, the roles table, session salts, …). Option reads refuse anything that looks like a credential (API keys, SMTP passwords, salts).
* User roles assignable through MCP are limited to non-administrator roles; the tools refuse to modify a super-admin or another administrator account.
* Media uploads validate file content against the extension and block fetches to internal/cloud-metadata addresses.
* Disable individual tools from the settings page if you want to reduce surface area.

Note: the configured user acts with its full WordPress capabilities. Use the least-privileged role that meets your needs, and treat the token as a site credential.

== Roadmap ==

* WPML integration.

== Changelog ==

= 0.8.1 =
* Security: updates now come only from a release that carries `wp-mcp-control.zip`. The same GitHub repository also publishes the WordPress.org edition under a different file name, and the updater used to take whichever release was newest and fall back to any ZIP in it. One release of that edition would have been installed over this plugin on every site, leaving WordPress pointing at a main file that no longer existed, so the plugin would have deactivated itself everywhere at once with no way to update back. A package that does not contain `wp-mcp-control.php` is now refused before anything is replaced.
* Security: `update_option` and `get_option` trim the option name before checking it against the protected list. WordPress trims it too, so `"default_role "` with a trailing space used to get past the guard and change `default_role`.
* Security: `update_user` no longer changes the role of the account the connection acts as. For a site with one administrator, demoting it locks everyone out of wp-admin.
* Security: `deactivate_plugin` resolves the path the way WordPress does before refusing to deactivate this plugin, so a leading space, a doubled slash or an absolute path no longer gets around it.
* Security: `pll_create_translation` checks publish rights before creating a published or scheduled translation, like `create_post` does. A contributor could otherwise skip review.
* Fixed: backslashes vanished from post content and meta written through `create_post`, `update_post`, `update_post_meta` and `pll_create_translation`. WordPress strips one level of slashes from these inputs, so block attributes such as `<`, JSON and code samples lost theirs.
* Fixed: a client that redeemed its authorization code without sending `client_id` got tokens bound to no client. That connection was missing from Active connections and could only be cut off with "Revoke all".
* Fixed: the `status` of `create_comment` was ignored, because WordPress decides approval itself. It is applied after the comment is created. The missing `comment_author_url` warning is also gone.
* Fixed: a tool call whose `name` was not a string caused a fatal error on PHP 8 instead of a JSON-RPC error.
* Changed: the plugin points at the repository's current name, `yuo2007/simonov-ai-site-manager`, instead of relying on GitHub's redirect from the old one.

= 0.8.0 =
* Added: **read-only mode**. One checkbox and every tool that changes anything — creating, editing, deleting, installing, configuring — disappears from the client's tool list and is refused if called anyway. Worth switching on whenever a client is connected for research or reporting rather than editing. Withholding a tool works better than refusing it: a model that can see `delete_post` keeps reaching for it and reporting failures at you, while one that cannot see it never raises the subject.
* Added: **an audit log** of tool calls, on the settings page. The last 100 calls with the account, the result and the identifiers involved, including the ones that were refused. Only identifiers are recorded — never post content, option values or passwords — so the log does not become a new place for secrets to sit.
* Why both: every call this server makes is chosen by a model whose context is filled with text from this very site — post bodies, comments, profile fields, product descriptions. That text is untrusted input reaching the thing that decides what to do next, and no capability check can tell a real instruction from one written into a comment. Capabilities bound what a connection *may* do; these two decide how much of that is reachable at all, and leave a record of what actually happened.
* Note for anyone extending the plugin: a tool is treated as a write unless it is named in `Tool_Registry::$read_only_tools`, so a tool added through `wpmcp_tool_groups` is restricted by default rather than silently escaping read-only mode. Override with the `wpmcp_tool_is_write` filter.

= 0.7.0 =
* Added: **Active connections** on the settings page. Every client that signed in with WordPress is listed with the account it acts as and when its access expires, and each can be revoked on the spot — which drops its tokens and removes its registration, so it has to sign in again from scratch. There was previously no way to see what had been authorized and no way to withdraw it: an access token lasts 30 days and a refresh token 180, so short of changing the WordPress password a connection could not be cut off.
* Security: refreshing an access token now re-checks that the account is still permitted to connect. A refresh token is good for 180 days, so an account that was demoted or stripped of its permissions kept minting working access tokens until it expired.
* Security: client registration is rate-limited to 10 per hour per address, the request body is capped, and the client store no longer evicts a connection that still holds a token. Registration has to stay open for a client to introduce itself, but it is an anonymous write to an option — and the old "drop the oldest when full" rule meant a burst of junk registrations could push out the entry a working connector depended on, breaking it with nothing to explain why.
* Security: tool error messages are stripped of server internals before they leave the site. Failures are still reported so the model can act on them, but an exception raised deep in WordPress carries absolute paths and a database error carries the query — neither helps the caller, and both travel on into an external model's context.

= 0.6.3 =
* Fixed: new versions took up to twelve hours to appear. The update check cached its answer for that long and only the "Check again" button went past it, so opening Dashboard → Updates could report "up to date" against a half-day-old answer — which reads as a broken feature, not as caching. How stale an answer may be now depends on why it is being asked for: no older than 60 seconds on the Updates screen, matching what WordPress uses for its own data there, 15 minutes for the background checks that fire on other admin pages, and always refetched when "Check again" is pressed. A cached failure is still retried after 30 minutes, so an outage cannot pin a site to an old version.
* Changed: the release lookup is resolved once per request instead of once per caller, so a page that renders both the update row and the details modal no longer makes two API calls. The cache still exists to protect the unauthenticated GitHub rate limit of 60 requests an hour, which on shared hosting is shared with every other site on the same address.

= 0.6.2 =
* Security: `update_option` no longer writes transients. `_site_transient_update_plugins` and `_site_transient_update_themes` hold the package URL that WordPress downloads and unpacks on the next update, so writing one and then calling `update_plugin` installed arbitrary PHP from an arbitrary host. That is the same boundary the existing blocks on `uninstall_plugins` and `auto_update_plugins` exist to hold; the deny-list simply did not reach transients.
* Security: `upload_media_from_url` now validates every redirect hop itself and pins the resolved address for the connection. Previously the URL was checked once and then handed to `download_url()`, which resolves the hostname again — so DNS with a near-zero TTL could answer the check with a public address and the fetch with 169.254.169.254. That range is exactly why the plugin carries its own check, because WordPress blocks RFC1918 but not cloud metadata, and the fetched body is stored as an attachment and returned as a public URL.
* Security: `get_option` redacts credential-looking keys *inside* option values. The deny-list could only judge the option name, and plugins almost never give a secret its own option — they store one settings array under an innocent name, so `woocommerce_stripe_settings` handed `secret_key` straight to the model.
* Security: `update_user` refuses to change the email or password of the account the connection itself acts as. Those two fields were exempt from the "not another administrator" guard, which turned a leaked token into permanent takeover with the owner locked out — and WordPress's confirm-your-new-address flow does not apply outside wp-admin.
* Security: the WooCommerce tools now check capabilities against the specific product or order, like every other tool group. They relied on the blanket capability alone, so a role with `edit_products` but not `edit_others_products` — a common WooCommerce combination — could write to everyone's products.
* Security: the authorization screen now shows the hostname the access will be sent to. Client registration is open by design, and the client name is chosen by whoever registers, so anyone could register a client called "Claude" pointing at their own server and mail the link to an administrator. The destination is the one fact that tells the real client from an impostor.
* Security: JSON-RPC batches are capped at 50 requests; a single POST could previously queue thousands of tool calls.
* Security: update packages are only accepted from GitHub's own download hosts, so an unexpected API response cannot turn an update check into an install from an arbitrary server.

= 0.6.1 =
* Added: an explanation of AI user-agent blocking, on the settings page and in the FAQ. A host or CDN that blocks AI bots makes the connection fail in a way that looks like a plugin fault: the WordPress sign-in succeeds, because it happens in your browser, and then the MCP request is refused, because that one comes from the AI provider's servers under their own user agent. The new section names the agent to allow for Claude, ChatGPT and Perplexity, makes the point that it is not the crawler agent and that those can stay blocked, gives a one-line curl command to confirm the block, and lists where such a rule usually lives.
* Changed: dropped "native OAuth 2.0" from the roadmap. It shipped in 0.4.0.

= 0.6.0 =
* Added: one-click updates. The plugin now reports new versions to WordPress itself, so Dashboard → Updates and the Plugins screen offer "Update now" exactly as they do for a plugin from the directory — no more uploading a ZIP by hand, and no helper plugin to install. Uses the "Update URI" header that WordPress has supported since 5.8; releases are served from GitHub. Note that this release still has to be installed manually once, because the update code has to be on the site before WordPress can offer anything.
* Fixed: the distributed ZIP now wraps everything in a single `wp-mcp-control` folder. Earlier archives had the files at the root, which made WordPress name the plugin folder after the ZIP file, so the directory differed from site to site. Updates install into whatever folder a site already uses, so an existing install is upgraded in place rather than duplicated into a new one.
* Changed: minimum requirements lowered from WordPress 6.0 / PHP 7.4 to **WordPress 5.8 / PHP 7.0**. Every WordPress function the plugin calls was checked against the 5.8 core API — no shims were needed, so the plugin behaves identically on old and new installs. The only PHP 7.4-only construct (an arrow function in the filesystem bootstrap) was rewritten as a plain closure.
* Added: a requirements check at load time. On an unsupported stack the plugin stays inert and shows an admin notice instead of causing a fatal error — this matters because the "Requires at least" header only blocks activation, not a plugin that is already active when the site is downgraded.
* Note: Full Site Editing tools (templates, global styles, navigation) depend on post types that WordPress registers only from 5.9 with a block theme. On 5.8 or a classic theme they are reported as unknown post types; every other tool is unaffected.

= 0.5.2 =
* Added: Yoast SEO fields are now writable through the post tools. When Yoast is active, the whole `_yoast_wpseo_` meta namespace (SEO title, meta description, focus keyphrase, canonical, robots directives, Open Graph / Twitter cards, schema, cornerstone flag, primary category, and any future Yoast field) is allowed via the `meta` parameter of `create_post`/`update_post` and via `update_post_meta`. All other protected `_`-prefixed keys stay blocked. Extend or restrict the allowlist with the `wpmcp_meta_writable` filter.

= 0.5.1 =
* Changed: block-theme / Full Site Editing content is now editable through the post tools — global styles (colours & fonts), templates and template parts, the Additional CSS, and navigation. These remain gated by the theme's own edit_theme_options capability, so only a connection acting as an admin-level user can change them. Purely internal types (revisions, GDPR requests, Customizer changesets) stay blocked.

= 0.5.0 =
* Changed: `update_option` can now configure third-party plugin and theme settings. Writes switched from a fixed allow-list (which blocked all plugin options) to a deny-list of the specific core options that are escalation or takeover primitives — users_can_register, default_role, siteurl/home, active_plugins, uninstall_plugins, template/stylesheet, cron, the wp_user_roles table and session salts remain blocked. Reads still refuse credential-looking option names. Extend the block set with the `wpmcp_write_blocked_options` filter.
* Includes the OAuth connection fixes from 0.4.1.

= 0.4.1 =
* Fixed: the WWW-Authenticate challenge was being sent on successful (200) responses too, which made some MCP clients believe authentication was still required and fail to connect right after signing in. It is now sent only on 401.
* Fixed: OAuth now works on sites that have no static token configured — the endpoint no longer requires a configured "act as user" when connecting via sign-in.
* Fixed: the token endpoint accepts public PKCE clients that omit client_id / redirect_uri in the exchange (the code and PKCE verifier already bind the grant).

= 0.4.0 =
* Added: "Sign in with WordPress" — a built-in OAuth 2.1 authorization server (dynamic client registration, PKCE S256, refresh tokens). In claude.ai you now paste only your site address, click Connect, log in and approve — no token to copy or place in a URL. The connection acts as the WordPress user who signs in.
* Added: the 401 response now carries a WWW-Authenticate challenge and the site serves OAuth discovery documents, so MCP clients can find the sign-in flow automatically.
* Improved: after generating a token the settings page shows a ready-to-paste connection URL and a Claude Desktop config block, each with a one-click Copy button.
* Note: OAuth and the token URL both require "pretty" permalinks (Settings → Permalinks). The settings page warns if plain permalinks are active.

= 0.3.0 =
* Security: hardened every tool with object-level capability checks. Previously a tool checked only a generic capability (e.g. "can edit posts") and then acted on a specific object without verifying ownership or type — so a lower-privileged configured user could edit or delete content, users, media and settings beyond their rights.
* Security: `update_option` now uses an allow-list. Blocked escalation via users_can_register + default_role, uninstall_plugins (arbitrary callable), active_plugins and siteurl.
* Security: `get_option` refuses option names that look like credentials.
* Security: user tools restrict assignable roles to non-administrators and refuse to modify super-admin / other administrator accounts; changing another user's password/email now requires the proper per-user capability.
* Security: `create_comment` routes through wp_new_comment with unconditional kses sanitization and defaults to "hold" — closes a stored-XSS path.
* Security: `delete_plugin` / `delete_theme` validate the path (no traversal) and refuse to remove this plugin itself; install/delete no longer overrides a site's FTP/SSH filesystem method.
* Security: media tools verify object type and capability, validate base64 content, cap upload sizes, and block SSRF to 169.254/16 and 100.64/10.
* Security: token is now stored as a fast keyed HMAC compared with hash_equals (was bcrypt, an unauthenticated CPU-amplification vector); token length is capped and auth errors are uniform. Existing tokens are upgraded transparently on first use — no need to regenerate.
* Security: the MCP endpoint requires an application/json content type and sends Cache-Control: no-store; the settings page validates the selected user server-side.
* Fixed: media delete honoured "force": false incorrectly and always deleted permanently.

= 0.2.0 =
* Fixed: the server now echoes the client's requested protocol version instead of always answering `2025-06-18`. Answering with a different version made clients disconnect right after `initialize`.
* Fixed: `resources/list`, `resources/templates/list` and `prompts/list` return empty lists instead of `-32601 Method not found`. Clients probe these immediately after `initialize`.
* Fixed: dropped `structuredContent` from tool results — the spec ties it to a declared `outputSchema`, which these tools do not declare, so strict clients rejected every tool call.
* Changed: tool checkboxes are no longer inverted. Checked now means enabled, matching the "Enabled tools" heading.
* Added: "Select all" / "Deselect all" buttons for the tool list.

= 0.1.0 =
* Initial release: bearer-token MCP server with full content, media, user, taxonomy, comment, option, plugin, theme, WooCommerce and Polylang tool coverage.
