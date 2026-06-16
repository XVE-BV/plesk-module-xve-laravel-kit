# Changelog

## v2.2.1

- Fix `TypeError: array callback must have exactly two members` (Zend Callback.php) when opening/saving a domain's deploy settings. The repo-URL allowlist used a `Zend_Validate_Callback` whose array-options form mis-binds the callback on Plesk's Zend build; the check now runs in the controller instead. Allowlist behaviour is unchanged (SSH + admin-configured GitHub org, enforced again at deploy time)

## v2.2.0

- Teams deploy notifications are now **Adaptive Cards** (icon + title, fact set, and Open site / View commit actions) sent as the Power Automate Workflows envelope, matching the nassau deployer. **Action required:** the Teams webhook must be a Teams *Workflow* URL ("Post to a channel when a webhook request is received") — legacy Office 365 connector URLs no longer render these cards. Reconfigure the webhook under Extension Settings after upgrading

## v2.1.1

- Fix Extension Settings clobbering: the page had two separate forms posting to the same action, so saving Teams notifications wiped the repo allowlist (and vice versa). Both sections are now a single form, so all fields save together. An emptied allowlist fails closed and blocks deploys, so re-check both fields after upgrading

## v2.1.0

Security hardening (OPS-25 review). **Breaking:** the webhook no longer accepts the `?secret=` query parameter; callers must send `Authorization: Bearer <secret>`.

- Webhook auth is header-only. Removed the `?secret=` query-string path (it leaked tokens in access/proxy logs and `Referer`); the secret is now read only from `Authorization: Bearer`. The Settings tab shows the token separately for storing in CI secrets
- Webhook rate limiting: 20 requests per 60s per client IP (`REMOTE_ADDR`), returning HTTP 429 with `Retry-After`; this also throttles secret-guessing
- Per-domain force gate: a webhook `{"force": true}` (cancel an in-progress deploy) is ignored unless **Allow webhook force-deploy** is enabled for that domain
- Repository URL allowlist: only SSH URLs to a configured GitHub org (`git@github.com:<your-org>/...`) are accepted, enforced both in the settings form and at deploy time. The allowed orgs are an admin-level extension setting (not hard-coded in this public source); HTTPS and non-listed hosts/owners are rejected
- SSH host-key pinning: git operations pin GitHub's host keys via a per-domain `known_hosts` with `StrictHostKeyChecking=yes`, replacing the previous `accept-new`
- Removed the root `eval` wrapper: `sbin/xve-exec.sh` is now an allowlisted argv subcommand dispatcher with no `eval` and no shell interpretation of caller-supplied data; every privileged call site passes its arguments as argv

## v2.0.4

- Fix deploy crash `Call to undefined method pm_Log::warning()`: Plesk's `pm_Log` exposes `warn()`, not `warning()`. Corrects the v2.0.3 PHP-detection log lines and three pre-existing typos (`TeamsNotifier`, `Task/Deploy`, and a Deployer error path)

## v2.0.3

- Fix deploy running under the wrong PHP version: `_getPhpBinDir()` no longer silently falls back to the newest installed PHP (e.g. 8.5) when the domain's PHP handler can't be parsed
- Add a per-domain **PHP Version** deploy setting (auto-detect by default; pin a version to override)
- Harden PHP handler-id parsing and log the resolved/fallback PHP version for diagnosability

## v1.5.0

- Fix webhook endpoint: move to `htdocs/public/` for unauthenticated access
- Support optional `branch` parameter in webhook JSON body
- Remove broken `WebhookController` (Plesk has no anonymous access level)

## v1.4.1

- Fix release zip versioning: sync meta.xml with git tag

## v1.4.0

- Auto-refresh releases page when deployment finishes

## v1.3.0

- Park failed releases to `_last_failed_release` for inspection instead of deleting
- Only one failed release kept on disk; each new failure replaces the previous one
- Falls back to deletion if parking fails

## v1.2.0

- Clean up failed release directories to prevent pile-up
- Fix 403 after deploy: chown symlink for nginx `disable_symlinks if_not_owner`
- Fix `hasCurrentRelease` to check for artisan file, not just directory
- Enable `composer_install` by default, skip `config:cache` when no release exists
- Add smart .env initialization, deploy readiness checklist, and guide pages
- Add quick-setup form to create Laravel sites in one step
- Set www-root to `current/public`, simplify release switching
- Add full teardown with danger zone UI on settings page
- Add Node.js Toolkit version selector and auto-setup for new domains

## v1.1.0

- Add .env validation before save (syntax, duplicate keys, required Laravel keys)
- Errors block save; warnings allow force-save with confirmation
- Remove unused safe-deploy module

## v1.0.0

- Initial release
- Atomic zero-downtime deploys with rollback support
- Real-time deploy progress via Plesk LongTask framework
- Deploy-in-progress banner visible to all Plesk users
- Deploy mode setting: verbose / quiet
- Searchable branch selector for deploys
- Commit info (hash, message, author, branch) logged with each deploy
- Commit hashes linked to GitHub commit page
- SSH deploy key management per domain
- Webhook endpoint for CI/CD triggered deploys
- Artisan runner from Plesk panel
- .env editor with backup on save
- Laravel log viewer with clear option
- Health check with configurable URL and timeout
- Deploy steps: Composer install, Node.js build (npm/pnpm/yarn), migrations, optimize, queue restart
