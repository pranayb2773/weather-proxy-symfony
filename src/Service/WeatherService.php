<?php

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Fetches weather data from Open-Meteo API with 5-minute caching to reduce API calls.
 * Uses static cache key since coordinates (Berlin: 52.52, 13.41) are fixed.
 * Applies IP-based rate limiting (60 req/min).
 */
class WeatherService
{
    private const string CACHE_KEY = 'weather_forecast_berlin';
    private const int CACHE_TTL = 300; // 5 minutes
    private const string API_URL = 'https://api.open-meteo.com/v1/forecast?latitude=52.52&longitude=13.41&current=temperature_2m&hourly=temperature_2m&forecast_days=1';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly CacheInterface $cache,
        private readonly LoggerInterface $logger,
        #[Autowire(service: 'limiter.weather_api')]
        private readonly RateLimiterFactory $weatherApiLimiter
    ) {
    }

    /**
     * Get weather data with rate limiting applied
     *
     * @param string $clientIp The client's IP address for rate limiting
     * @throws TooManyRequestsHttpException When rate limit is exceeded
     */
    public function getWeatherData(string $clientIp): array
    {
        $this->checkRateLimit($clientIp);

        return $this->cache->get(self::CACHE_KEY, function (ItemInterface $item) {
            $this->logger->debug('Weather cache miss, fetching from Open-Meteo API');

            try {
                $response = $this->httpClient->request('GET', self::API_URL);

                $this->logger->info('Open-Meteo API request successful', [
                    'status_code' => $response->getStatusCode(),
                ]);

                $data = $response->toArray();
                $item->expiresAfter(self::CACHE_TTL);

                $this->logger->debug('Weather data cached successfully');

                return $data;
            } catch (\Throwable $e) {
                $this->logger->error('Failed to fetch weather data from Open-Meteo API', [
                    'error' => $e->getMessage(),
                    'exception_class' => get_class($e),
                ]);

                throw $e;
            }
        });
    }

    /**
     * Check rate limit for the given IP address
     *
     * @throws TooManyRequestsHttpException When rate limit is exceeded
     */
    private function checkRateLimit(string $clientIp): void
    {
        $limiter = $this->weatherApiLimiter->create($clientIp);

        // Consume 1 token and check if it was accepted
        $limit = $limiter->consume(1);

        if (!$limit->isAccepted()) {
            $retryAfter = $limit->getRetryAfter();

            $this->logger->warning('Rate limit exceeded for IP address', [
                'ip' => $clientIp,
                'retry_after' => $retryAfter->format('Y-m-d H:i:s'),
            ]);

            throw new TooManyRequestsHttpException(
                $retryAfter->getTimestamp() - time(),
                'Rate limit exceeded. Please try again later.'
            );
        }

        $this->logger->debug('Rate limit check passed', [
            'ip' => $clientIp,
            'remaining_tokens' => $limit->getRemainingTokens(),
        ]);
    }
}
