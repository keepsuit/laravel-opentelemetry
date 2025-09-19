# Laravel OpenTelemetry Package

Laravel OpenTelemetry is a PHP package that provides comprehensive OpenTelemetry integration for Laravel applications, supporting traces, metrics, and logs with various exporters (OTLP, Zipkin, Console).

Always reference these instructions first and fallback to search or bash commands only when you encounter unexpected information that does not match the info here.

## Working Effectively

### Prerequisites and Environment Setup
- Install Docker and Docker Compose for containerized development
- The project uses PHP 8.1+ and requires Redis for testing
- GRPC PHP extension is optional but recommended for full OTLP gRPC functionality

### Bootstrap, Build, and Test the Repository

**Docker-based Development (Recommended):**
- `make build` -- Builds Docker image with proper UID/GID permissions. Takes 3-5 minutes. NEVER CANCEL. Set timeout to 10+ minutes.
- `make start` -- Starts containers in background (app + Redis). Takes 30-60 seconds.
- `make shell` -- Opens interactive shell in app container. Always run `make start` first.
- `make test` -- Runs full test suite via Composer in container. Takes 2-3 minutes. NEVER CANCEL. Set timeout to 10+ minutes.
- `make lint` -- Runs code quality tools (Pint + PHPStan). Takes 1-2 minutes. NEVER CANCEL. Set timeout to 5+ minutes.
- `make stop` -- Stops and removes containers

**Local Development (Alternative):**
- `composer install --ignore-platform-req=ext-grpc` -- Install dependencies, ignoring GRPC if not available. Takes 3-6 minutes. NEVER CANCEL. Set timeout to 15+ minutes.
- `composer test` -- Run tests via Composer script (uses Pest framework)
- `composer run lint` -- Run code quality tools (Pint + PHPStan)
- Direct binaries available after full install: `vendor/bin/pest`, `vendor/bin/pint`, `vendor/bin/phpstan`

### Docker Build Troubleshooting
- If `make build` fails with GRPC installation errors, this is due to network connectivity issues with PECL repositories
- The GRPC extension is optional for basic development and testing
- CI/CD environments have the GRPC extension pre-installed for full functionality testing
- **Alternative**: Use local development with `composer install --ignore-platform-req=ext-grpc`

### Common Installation Issues
- **Network timeouts**: Composer may timeout downloading packages from GitHub. Increase timeout and retry.
- **Missing GRPC**: Use `--ignore-platform-req=ext-grpc` flag with Composer commands
- **Memory limits**: Large dependency tree may require increasing PHP memory limit
- **Permission issues with Docker**: Ensure proper UID/GID mapping in `make build`

## Validation

### Quick Setup Validation
After cloning the repository, verify the environment with these commands:
```bash
# Check basic tools
php --version  # Should be 8.1+
composer --version
docker --version

# Test basic setup
composer install --no-dev --ignore-platform-req=ext-grpc  # ~2 minutes
composer run-script --list  # Should show: test, test-coverage, lint

# Verify package structure
ls -la src/Instrumentation/  # Should show PHP instrumentation classes
ls -la config/opentelemetry.php  # Package configuration
```

### Manual Testing Scenarios
Since this is a Laravel package (not a standalone application), testing involves:

1. **Unit Test Validation**: Always run the full test suite to verify functionality
   - `make test` or `vendor/bin/pest`
   - Test suite covers all instrumentation classes, SDK components, and integration points

2. **Static Analysis Validation**: Ensure code quality standards
   - `make lint` or `vendor/bin/phpstan`
   - PHPStan runs at level 8 with strict checking

3. **Code Style Validation**: Maintain consistent formatting
   - `vendor/bin/pint` for automatic code formatting
   - Laravel Pint configuration is included in the repository

### Integration Testing
- The package integrates with Laravel's HTTP server, database, Redis, queue, cache, and logging systems
- Tests use Orchestra Testbench to simulate Laravel environment
- Livewire integration is tested when available
- HTTP client tracing is validated with Guzzle test server

## Build Times and Timeouts

**CRITICAL TIMING EXPECTATIONS:**
- Docker build: 5-10 minutes (network dependent, GRPC compilation). NEVER CANCEL. Use 15+ minute timeout.
- Composer install (full with dev deps): 3-6 minutes (network dependent). NEVER CANCEL. Use 15+ minute timeout.
- Composer install (no dev deps): ~2 minutes. NEVER CANCEL. Use 10+ minute timeout.
- Test suite: 2-3 minutes. NEVER CANCEL. Use 10+ minute timeout.
- PHPStan analysis: 1-2 minutes. NEVER CANCEL. Use 5+ minute timeout.
- Code formatting: 30-60 seconds.

**Network-related delays are common** - builds may take significantly longer due to:
- GRPC extension compilation from source
- Composer package downloads from GitHub/Packagist
- Docker base image downloads

## Common Development Tasks

### Quick Development Workflow
1. **Setup**: `composer install --ignore-platform-req=ext-grpc`
2. **Make changes**: Edit code in `src/` directory
3. **Test**: `composer test` (if dev dependencies installed) or `make test` (Docker)
4. **Lint**: `composer run lint` or `make lint`
5. **Format**: `vendor/bin/pint` (auto-fix code style)

### Adding New Instrumentation
- Create new class in `src/Instrumentation/` implementing `Instrumentation` interface
- Register in `config/opentelemetry.php` instrumentation array
- Add corresponding tests in `tests/Instrumentation/`
- Update README.md documentation

### Modifying Exporters
- Exporter configurations are in `config/opentelemetry.php`
- Supported drivers: "otlp", "zipkin", "console", "null"
- Each exporter can have custom endpoints and protocols

### Testing Changes
- Always run `make test` or `composer test` after making code changes
- Use `make lint` or `composer run lint` to check code style and static analysis
- Test with multiple PHP versions (8.1, 8.2, 8.3, 8.4) and Laravel versions (10.14, 11.0, 12.0) as done in CI
- **CI/CD Validation**: GitHub Actions run tests across matrix of PHP/Laravel versions with Redis service

### CI/CD Pipeline Details
- **Tests**: Run on Ubuntu with PHP 8.1-8.4, Laravel 10.14-12.0, with Redis service
- **Code Style**: Auto-formatting with Laravel Pint action
- **Static Analysis**: PHPStan level 8 with strict checking
- **Platform Requirements**: CI has full GRPC extension support

## Important File Locations

### Core Package Files
- `src/LaravelOpenTelemetryServiceProvider.php` -- Main service provider
- `src/InstrumentationServiceProvider.php` -- Instrumentation registration
- `src/Instrumentation/` -- All tracing instrumentation classes:
  - `HttpServerInstrumentation.php` -- HTTP request tracing
  - `HttpClientInstrumentation.php` -- HTTP client tracing  
  - `QueryInstrumentation.php` -- Database query tracing
  - `RedisInstrumentation.php` -- Redis command tracing
  - `QueueInstrumentation.php` -- Job queue tracing
  - `CacheInstrumentation.php` -- Cache operation tracing
  - `EventInstrumentation.php` -- Laravel event tracing
  - `ViewInstrumentation.php` -- View rendering tracing
  - `LivewireInstrumentation.php` -- Livewire component tracing
  - `ConsoleInstrumentation.php` -- Console command tracing
- `src/Facades/` -- Laravel facades: `Tracer.php`, `Meter.php`, `Logger.php`
- `src/Support/` -- Helper classes and builders
- `config/opentelemetry.php` -- Package configuration

### Testing Infrastructure
- `tests/TestCase.php` -- Base test case with Laravel setup
- `tests/Instrumentation/` -- Tests for all instrumentation
- `tests/Sdk/` -- Tests for OpenTelemetry SDK integration
- `tests/Support/` -- Tests for helper classes
- `phpunit.xml.dist` -- PHPUnit configuration
- `tests/Pest.php` -- Pest testing framework configuration

### Development Configuration
- `Dockerfile` -- PHP 8.2 CLI with GRPC extension
- `docker-compose.yml` -- App + Redis containers
- `makefile` -- Development commands
- `phpstan.neon` -- Static analysis configuration
- `.github/workflows/` -- CI/CD pipelines

## Package Architecture

### Instrumentation System
The package provides automatic tracing for:
- HTTP server requests (middleware-based)
- HTTP client requests (Guzzle integration)
- Database queries (Laravel query events)
- Redis commands (Redis events)
- Queue jobs (producer/consumer pattern)
- Cache operations (hit/miss/set/forget)
- View rendering
- Livewire components
- Console commands

### Configuration System
- Environment variable driven (OTEL_* variables)
- Multiple exporter support (OTLP, Zipkin, Console, Null)
- Per-instrumentation enable/disable
- Header filtering and sensitive data handling

### Quick Code Navigation Commands
```bash
# View all instrumentation classes
ls src/Instrumentation/*.php

# Check main service provider
cat src/LaravelOpenTelemetryServiceProvider.php

# View configuration structure  
cat config/opentelemetry.php

# Check available facades
ls src/Facades/

# Browse test structure
find tests/ -name "*.php" | head -10
```

### Testing Strategy
- Orchestra Testbench for Laravel simulation
- Pest for expressive testing
- Mock exporters for validation
- Integration tests with real Redis
- HTTP client testing with Guzzle test server

Always ensure changes maintain backward compatibility and follow the existing architectural patterns.