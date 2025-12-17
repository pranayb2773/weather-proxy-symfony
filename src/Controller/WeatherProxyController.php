<?php

namespace App\Controller;

use App\Service\WeatherService;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

/**
 * Caching proxy for Open-Meteo API to avoid rate limiting for 500+ employee dashboards.
 * Caches responses for 5 minutes and applies IP-based rate limiting (60 req/min).
 */
class WeatherProxyController extends AbstractController
{
    public function __construct(
        private readonly WeatherService $weatherService,
        private readonly LoggerInterface $logger,
        #[Autowire(service: 'limiter.weather_api')]
        private readonly RateLimiterFactory $weatherApiLimiter
    ) {
    }

    #[Route('/api/weather', name: 'api_weather', methods: ['GET'])]
    public function getWeather(Request $request): JsonResponse
    {
        $limiter = $this->weatherApiLimiter->create($request->getClientIp());

        if (false === $limiter->consume(1)->isAccepted()) {
            $this->logger->warning('Rate limit exceeded for IP address', [
                'ip' => $request->getClientIp(),
            ]);

            throw new TooManyRequestsHttpException(
                null,
                'Rate limit exceeded. Please try again later.'
            );
        }

        try {
            $weatherData = $this->weatherService->getWeatherData();

            $this->logger->debug('Weather data served successfully', [
                'ip' => $request->getClientIp(),
            ]);

            return $this->json($weatherData);
        } catch (TransportExceptionInterface $e) {
            $this->logger->error('Timeout or network error when calling Open-Meteo API', [
                'error' => $e->getMessage(),
            ]);

            return $this->json(
                [
                    'error' => 'Gateway timeout',
                    'message' => 'Unable to reach weather service. Please try again later.',
                ],
                Response::HTTP_GATEWAY_TIMEOUT
            );
        } catch (HttpExceptionInterface $e) {
            $this->logger->error('HTTP error from Open-Meteo API', [
                'error' => $e->getMessage(),
            ]);

            return $this->json(
                [
                    'error' => 'Bad gateway',
                    'message' => 'Weather service returned an error. Please try again later.',
                ],
                Response::HTTP_BAD_GATEWAY
            );
        } catch (\Throwable $e) {
            $this->logger->error('Unexpected error in weather endpoint', [
                'error' => $e->getMessage(),
                'exception_class' => get_class($e),
            ]);

            return $this->json(
                [
                    'error' => 'Internal server error',
                    'message' => 'An unexpected error occurred. Please try again later.',
                ],
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }
}
