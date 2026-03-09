# Changelog

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
