# XVE Laravel Kit — Plesk Extension

Zero-downtime Laravel deployments for Plesk, with rollback, artisan console, .env editor, and log viewer.

## Features

### Deployments

- **Atomic zero-downtime deploys** — Git clone → build → symlink switch, no downtime
- **Rollback** — Instant rollback to any previous successful release
- **Searchable branch selector** — Pick any remote branch from a dropdown with search
- **Real-time deploy progress** — Step-by-step progress tracking via Plesk LongTask framework
- **Deploy-in-progress banner** — Visible to all logged-in Plesk users during a deploy
- **Auto-refresh** — Releases page reloads automatically when deployment finishes
- **Failed release inspection** — Failed releases parked to `_last_failed_release` for debugging (latest failure only, no pile-up)
- **Automatic rollback on failure** — Reverts to previous release if any deploy step fails
- **Commit tracking** — Hash, message, author, and branch logged with each deploy, linked to GitHub
- **Deploy history** — Full history of all deploys with status and commit info
- **Configurable keep-releases** — Set how many old releases to keep on disk

### Deploy Steps

Each step can be individually enabled or disabled:

- **Composer install** — Runs `composer install --no-dev --optimize-autoloader`
- **Node.js build** — Supports npm, pnpm, and yarn (auto-detects from lock file)
- **Laravel migrations** — Runs `php artisan migrate --force`
- **Optimize** — Runs `php artisan optimize` (config, route, view caching)
- **Queue restart** — Runs `php artisan queue:restart`
- **Custom pre-deploy script** — Run any shell commands before the symlink switch
- **Custom post-deploy script** — Run any shell commands after the symlink switch

### Health Check

- **Post-deploy URL check** — Verify your app responds after deploy
- **Configurable timeout** — Set how long to wait for a response
- **Auto-rollback** — Rolls back if health check fails

### Environment & Config

- **.env editor** — Edit environment variables directly in the Plesk panel
- **.env validation** — Syntax checking, duplicate key detection, required Laravel key warnings
- **Force-save with confirmation** — Warnings allow override, errors block save
- **.env backup on save** — Previous version backed up automatically
- **Smart .env initialization** — Auto-creates `.env` from `.env.example` on first setup
- **APP_KEY management** — Auto-generates APP_KEY if missing

### Tools

- **Artisan runner** — Execute any artisan command from the Plesk panel
- **Laravel log viewer** — View and clear Laravel logs without SSH
- **Maintenance mode toggle** — Enable/disable `php artisan down/up` from the dashboard
- **Deploy readiness checklist** — Pre-deploy checks to verify your setup is correct

### Infrastructure

- **SSH deploy key management** — Per-domain deploy keys, generated and managed in the UI
- **Webhook endpoint** — POST endpoint to trigger deploys from CI/CD (GitHub Actions, etc.)
- **Automatic ownership fix** — Handles nginx `disable_symlinks if_not_owner` directive
- **Node.js Toolkit version selector** — Choose Node.js version with auto-setup for new domains
- **Deploy mode** — Verbose or quiet output

### Setup & Management

- **Quick-setup form** — Create Laravel deployment sites in one step
- **Per-domain settings** — Each domain has its own git repo, branch, and deploy configuration
- **Full teardown** — Danger zone UI to completely remove deployment setup, keys, and settings
- **Guide page** — In-panel documentation for getting started

## Install

Download the latest release zip and install via CLI:

```bash
plesk bin extension --install xve-laravel-kit-1.4.1.zip
```

Or install directly from a release URL:

```bash
plesk bin extension --install-url https://github.com/XVE-BV/plesk-module-xve-laravel-kit/releases/download/v1.4.1/xve-laravel-kit-1.4.1.zip
```

To update an existing installation, run the same command with the new version.

## Release

Push a version tag to build and publish a release automatically:

```bash
git tag v1.4.1
git push origin v1.4.1
```

The GitHub Action syncs `meta.xml` with the tag version, builds the extension zip, and attaches it to the release.

## Local Development

This repo includes a Docker-based dev environment for working on the extension locally.

### Prerequisites

- Docker Desktop
- A domain pointed at `127.0.0.1` (e.g. `laravel.plesk` in your hosts file)

### Setup

```bash
docker compose up -d

# Wait for Plesk to boot (~30s), then run the init script:
docker exec plesk bash /opt/init-sbin.sh

# Enable the systemd service so it runs automatically on restart:
docker exec plesk systemctl enable init-sbin.service
```

The extension source is volume-mounted — edit files in `xve-laravel-kit/src/` and changes are reflected immediately in Plesk.

### Plesk Panel

- **Panel**: https://localhost:8443 (admin / changeme1Q**)
- **Site**: https://laravel.plesk (after adding to hosts and deploying)

## Architecture

```
xve-laravel-kit/
├── src/
│   ├── meta.xml                    # Extension metadata
│   ├── htdocs/                     # Web assets (logo, entry point)
│   ├── sbin/xve-exec.sh           # Root exec wrapper (via callSbin)
│   └── plib/
│       ├── controllers/            # Zend MVC controllers
│       │   ├── IndexController     # Domain list
│       │   ├── DomainController    # Deploy, settings, artisan, env, logs
│       │   └── WebhookController   # CI/CD webhook endpoint
│       ├── hooks/                  # Plesk UI integration
│       │   ├── Navigation          # Sidebar link
│       │   └── CustomButtons       # Domain Dev Tools button
│       ├── library/
│       │   ├── Deployer            # Core deploy engine
│       │   ├── DeploySettings      # Per-domain settings (pm_Settings)
│       │   ├── SshKey              # SSH key generation
│       │   ├── Form/Settings       # Settings form
│       │   ├── Task/Deploy         # LongTask-based async deploy
│       │   └── Url                 # URL helper
│       └── views/scripts/          # .phtml view templates
└── build.sh                        # Packages into installable zip
```

## License

Proprietary — XVE BV
