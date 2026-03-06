# XVE Laravel Kit — Plesk Extension

Zero-downtime Laravel deployments for Plesk, with rollback, artisan console, .env editor, and log viewer.

## Features

- **Atomic deploys** — Git clone → build → symlink switch, no downtime
- **Automatic ownership fix** — Handles `disable_symlinks if_not_owner` nginx directive
- **Rollback** — Instant rollback to any previous successful release
- **Deploy steps** — Composer install, Node.js build (npm/pnpm/yarn), Laravel migrations, optimize, queue restart
- **Package manager** — Choose npm, pnpm, yarn, or auto-detect from lock file
- **SSH key management** — Per-domain deploy keys, generated and managed in the UI
- **Webhook** — POST endpoint to trigger deploys from CI/CD (GitHub Actions, etc.)
- **Artisan runner** — Execute artisan commands from the Plesk panel
- **.env editor** — Edit environment variables directly in the UI
- **Log viewer** — View Laravel logs without SSH
- **Health checks** — Optional post-deploy URL check with configurable timeout

## Install

Download the latest release zip and install via CLI:

```bash
plesk bin extension --install xve-laravel-kit-1.0.0.zip
```

Or install directly from a release URL:

```bash
plesk bin extension --install-url https://github.com/XVE-BV/plesk-module-xve-laravel-kit/releases/download/v1.0.0/xve-laravel-kit-1.0.0.zip
```

## Release

Push a version tag to build and publish a release automatically:

```bash
# Update version in xve-laravel-kit/src/meta.xml, then:
git tag v1.0.0
git push origin v1.0.0
```

The GitHub Action builds the extension zip and attaches it to the release.

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
│       │   └── Url                 # URL helper
│       └── views/scripts/          # .phtml view templates
└── build.sh                        # Packages into installable zip
```

## License

Proprietary — XVE BV / Skylence
