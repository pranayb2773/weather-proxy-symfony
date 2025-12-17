<?php

namespace App\Tests\Unit\Service;

use App\Service\WeatherService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

#[AllowMockObjectsWithoutExpectations]
class WeatherServiceTest extends TestCase
{
    private HttpClientInterface $httpClient;
    private CacheInterface $cache;
    private LoggerInterface $logger;
    private WeatherService $weatherService;

    protected function setUp(): void
    {
        $this->httpClient = $this->createMock(HttpClientInterface::class);
        $this->cache = $this->createMock(CacheInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->weatherService = new WeatherService(
            $this->httpClient,
            $this->cache,
            $this->logger
        );
    }

    public function testCacheHitReturnsDataWithoutApiCall(): void
    {
        $cachedData = [
            'latitude' => 52.52,
            'longitude' => 13.41,
            'current' => ['temperature_2m' => 15.5],
            'hourly' => ['temperature_2m' => [14.0, 15.0, 15.5]],
        ];

        $this->cache->expects($this->once())
            ->method('get')
            ->willReturn($cachedData);

        $this->httpClient->expects($this->never())
            ->method('request');

        $this->logger->expects($this->any())
            ->method('debug');

        $result = $this->weatherService->getWeatherData();

        $this->assertSame($cachedData, $result);
    }

    public function testCacheMissCallsApiAndStoresResult(): void
    {
        $apiData = [
            'latitude' => 52.52,
            'longitude' => 13.41,
            'current' => ['temperature_2m' => 16.0],
            'hourly' => ['temperature_2m' => [15.0, 16.0, 17.0]],
        ];

        $mockResponse = $this->createMock(ResponseInterface::class);
        $mockResponse->expects($this->once())
            ->method('getStatusCode')
            ->willReturn(200);
        $mockResponse->expects($this->once())
            ->method('toArray')
            ->willReturn($apiData);

        $mockCacheItem = $this->createMock(ItemInterface::class);
        $mockCacheItem->expects($this->once())
            ->method('expiresAfter')
            ->with(300); // Verify 5-minute TTL

        // Mock cache miss - callback is executed
        $this->cache->expects($this->once())
            ->method('get')
            ->willReturnCallback(function ($key, $callback) use ($mockCacheItem) {
                // Execute the callback (simulating cache miss)
                return $callback($mockCacheItem);
            });

        // HTTP client should be called on cache miss
        $this->httpClient->expects($this->once())
            ->method('request')
            ->with('GET', $this->stringContains('api.open-meteo.com'))
            ->willReturn($mockResponse);

        // Logger should log cache miss and successful API call
        $this->logger->expects($this->atLeast(2))
            ->method('debug');
        $this->logger->expects($this->once())
            ->method('info');

        $result = $this->weatherService->getWeatherData();

        $this->assertSame($apiData, $result);
    }

    public function testApiErrorThrowsException(): void
    {
        $mockCacheItem = $this->createMock(ItemInterface::class);
        $mockCacheItem->expects($this->any())
            ->method('expiresAfter');

        $transportException = new class ('Network error') extends \Exception implements TransportExceptionInterface {
        };

        $this->cache->expects($this->once())
            ->method('get')
            ->willReturnCallback(function ($key, $callback) use ($mockCacheItem) {
                return $callback($mockCacheItem);
            });

        $this->httpClient->expects($this->once())
            ->method('request')
            ->willThrowException($transportException);

        $this->logger->expects($this->once())
            ->method('error')
            ->with(
                $this->stringContains('Failed to fetch weather data'),
                $this->arrayHasKey('error')
            );

        $this->expectException(TransportExceptionInterface::class);
        $this->weatherService->getWeatherData();
    }

    public function testInvalidJsonThrowsException(): void
    {
        $mockResponse = $this->createMock(ResponseInterface::class);
        $mockResponse->expects($this->once())
            ->method('toArray')
            ->willThrowException(new \JsonException('Invalid JSON'));

        $mockCacheItem = $this->createMock(ItemInterface::class);
        $mockCacheItem->expects($this->any())
            ->method('expiresAfter');

        $this->cache->expects($this->once())
            ->method('get')
            ->willReturnCallback(function ($key, $callback) use ($mockCacheItem) {
                return $callback($mockCacheItem);
            });

        $this->httpClient->expects($this->once())
            ->method('request')
            ->willReturn($mockResponse);

        $this->logger->expects($this->once())
            ->method('error');

        $this->expectException(\JsonException::class);
        $this->weatherService->getWeatherData();
    }

    public function testCacheStoresDataWithCorrectTtl(): void
    {
        $apiData = ['latitude' => 52.52, 'longitude' => 13.41];

        $mockResponse = $this->createMock(ResponseInterface::class);
        $mockResponse->expects($this->any())
            ->method('getStatusCode')
            ->willReturn(200);
        $mockResponse->expects($this->any())
            ->method('toArray')
            ->willReturn($apiData);

        $mockCacheItem = $this->createMock(ItemInterface::class);
        $mockCacheItem->expects($this->once())
            ->method('expiresAfter')
            ->with($this->identicalTo(300));

        $this->cache->expects($this->once())
            ->method('get')
            ->willReturnCallback(function ($key, $callback) use ($mockCacheItem) {
                return $callback($mockCacheItem);
            });

        $this->httpClient->expects($this->once())
            ->method('request')
            ->willReturn($mockResponse);

        $this->logger->expects($this->any())
            ->method('debug');
        $this->logger->expects($this->any())
            ->method('info');

        $this->weatherService->getWeatherData();
    }
}
