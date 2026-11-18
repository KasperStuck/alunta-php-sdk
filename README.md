# Alunta PHP SDK

Framework-free PHP client for the Alunta Public API. Provides subscription checkout,
subscription and invoice reads, cancellation, plan changes, portal links, and webhook
signature and envelope primitives. The SDK depends on PSR interfaces and uses any
PSR-18 HTTP client through `Psr18Transport`.

Requires PHP 8.4 or later within PHP 8, `ext-mbstring`, a PSR-18 client, and PSR-17
request and stream factories. Configure the HTTP client with redirects disabled,
a 5-second connection timeout and a 15-second request timeout. This prevents a
redirect from forwarding the bearer token to another host.

## Installation

Once the public repository and release tag are available, add this VCS repository
to the consuming project's root `composer.json`:

```json
{
    "repositories": [
        {"type": "vcs", "url": "https://github.com/KasperStuck/alunta-php-sdk"}
    ]
}
```

```bash
composer require 'kasperstuck/alunta-php-sdk:^0.1'
```

No GitHub authentication is required for public repositories. GitHub limits
unauthenticated API requests. An optional GitHub token supplied through the
`COMPOSER_AUTH` environment variable avoids that limit. Keep tokens out of Git.

The [Alunta Laravel installation guide](https://github.com/KasperStuck/alunta-laravel#installation)
covers the companion Laravel and Filament packages. Composer reads repository
declarations only from the root project, including transitive dependencies.
Packagist may be supported later; this installation uses VCS repositories.

## Development and tests

```bash
composer install --no-interaction
composer test
composer analyse
```

The test suite covers the API client, UUID validation, webhook primitives and
dependency boundaries. PHPStan runs at level 10 on `src/`.
End-to-end emulator coverage lives in the host application.

## Versioning

Releases use annotated SemVer tags. During 0.x, a minor version may contain
breaking changes; patch versions preserve compatibility. This library commits
no Composer lockfile.

License: [MIT](LICENSE).
