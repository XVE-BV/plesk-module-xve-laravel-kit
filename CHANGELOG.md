# Changelog

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
