<?php

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Fetches weather data from Open-Meteo API with 5-minute caching to reduce API calls.
 * Uses static cache key since coordinates (Berlin: 52.52, 13.41) are fixed.
 */
class WeatherService
{
    private const string CACHE_KEY = 'weather_forecast_berlin';
    private const int CACHE_TTL = 300; // 5 minutes
    private const string API_URL = 'https://api.open-meteo.com/v1/forecast?latitude=52.52&longitude=13.41&current=temperature_2m&hourly=temperature_2m&forecast_days=1';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly CacheInterface $cache,
        private readonly LoggerInterface $logger
    ) {
    }

    public function getWeatherData(): array
    {
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
}
