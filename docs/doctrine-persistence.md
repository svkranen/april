# Doctrine Persistence

Doctrine is used as the persistent adapter for the Intelligence event store,
process instances, and context snapshots.

For a fresh checkout where Doctrine is not installed yet, install the required
packages with:

```bash
composer require symfony/orm-pack
composer require --dev symfony/maker-bundle doctrine/doctrine-migrations-bundle
```

This repository currently uses the Doctrine packages directly in `composer.json`
and maps the Intelligence infrastructure entities via
`config/packages/doctrine.yaml`.
