# Weather Proxy API

A caching proxy for the Open-Meteo weather API to prevent rate limiting for 500 employee dashboards.

## Problem

Our 500 employee dashboards were hitting Open-Meteo's API directly, causing 429 errors. This proxy caches responses for 5 minutes, reducing API calls by ~98%.

## Quick Start

```bash
composer install
symfony server:start
curl http://localhost:8000/api/weather
```

## API

**Endpoint:** `GET /api/weather`

Returns weather forecast for Berlin (52.52, 13.41) as JSON.

**Caching:** Responses cached for 5 minutes
**Rate Limiting:** 60 requests/minute per IP

### Error Responses

- `429` - Rate limit exceeded
- `502` - Upstream API error
- `504` - Request timeout
- `500` - Internal error

## How It Works

The proxy sits between dashboards and Open-Meteo:

```
Dashboard → Proxy (checks cache) → Open-Meteo API
                ↓
           Returns cached/fresh data
```

**First request:** Fetches from API, caches for 5 min
**Next requests:** Returns cached data (no API call)
**After 5 min:** Cache expires, fetches fresh data

This reduces ~500 requests/refresh to just 12 requests/hour.

## Development

**Run tests:**
```bash
./bin/phpunit
```

**Check logs:**
```bash
tail -f var/logs/dev.log
```

## Configuration

### Cache

Default: Filesystem (`var/cache/`)

For production, switch to Redis in `config/packages/cache.yaml`:

```yaml
framework:
    cache:
        app: cache.adapter.redis
```

### Rate Limiting

Config in `config/packages/rate_limiter.yaml`:

```yaml
weather_api:
    limit: 60
    rate: { interval: '1 minute' }
```

For distributed setup, use Redis-backed storage.

## Project Structure

```
src/
├── Controller/WeatherProxyController.php  # API endpoint
└── Service/WeatherService.php             # Caching logic

tests/
├── Unit/Service/WeatherServiceTest.php    # Unit tests
└── Functional/Controller/...              # Integration tests
```

## Requirements

- PHP 8.4+
- Composer
- Symfony 8.0

## Notes

- No authentication required (internal use)
- Follows Symfony/PSR-12 coding standards
- Static cache key (coordinates don't change)
- Pass-through response (no transformation)
