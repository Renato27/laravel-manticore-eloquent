# Contributing

## Running the tests

```bash
composer install
vendor/bin/pest --testsuite=Unit,Feature   # offline tests (no server needed)
# Integration tests require a running Manticore on the configured MySQL port
# (defaults to 9306; override with MANTICORE_HOST / MANTICORE_PORT):
MANTICORE_HOST=127.0.0.1 MANTICORE_PORT=9306 vendor/bin/pest --testsuite=Integration
```

A quick local Manticore for testing:

```bash
docker run --name manticore -p 9306:9306 -p 9308:9308 manticoresearch/manticore
```

Coverage (requires PCOV or Xdebug):

```bash
composer test:coverage        # full report
composer test:coverage-min    # fails under 95%
```

## The tests badge

On every push to `master`, CI runs all the suites against a live Manticore on one PHP and Laravel
version, and `.github/scripts/tests-badge.php` turns the report into `tests.json` on the `badges`
branch, which is what the badge reads. The CI badge next to it covers the whole version matrix.

See [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md) for the design and the SQL differences handled.
