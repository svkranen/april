# Contributing to APRIL

APRIL welcomes contributions to the Community Core.

## Before Your First Contribution

- Fork the repository.
- Create a feature branch for your change.
- Prefer small, focused pull requests.
- Discuss larger changes in an issue before opening a pull request.

## Coding

- Respect the existing architecture and naming conventions.
- Keep the Community Core connector-independent.
- Do not introduce Enterprise, private connector, or vendor-specific runtime
  dependencies into the Community Core.
- Reuse existing services and patterns where possible.
- Avoid weakening existing tests or replacing focused coverage with broader but
  less precise checks.

## Before Opening a Pull Request

Run the checks that are relevant for your change:

```bash
composer validate
composer install
php bin/console lint:container
./vendor/bin/simple-phpunit
git diff --check
```

For focused changes, running the affected PHPUnit tests is usually enough. If a
test command exits because of known deprecation policy while assertions are OK,
mention that clearly in the pull request.

## Discussion

Please discuss larger architectural changes, new connector boundaries, data
model changes, or public API changes in an issue first.
