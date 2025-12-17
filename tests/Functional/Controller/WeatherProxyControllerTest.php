<?php

namespace App\Tests\Functional\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class WeatherProxyControllerTest extends WebTestCase
{
    public function testWeatherEndpointReturnsSuccessResponse(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/weather');

        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('Content-Type', 'application/json');

        $responseData = json_decode($client->getResponse()->getContent(), true);

        $this->assertIsArray($responseData);
        $this->assertArrayHasKey('latitude', $responseData);
        $this->assertArrayHasKey('longitude', $responseData);
        $this->assertArrayHasKey('current', $responseData);
        $this->assertArrayHasKey('hourly', $responseData);
        $this->assertEqualsWithDelta(52.52, $responseData['latitude'], 0.01);
        $this->assertEqualsWithDelta(13.41, $responseData['longitude'], 0.01);
    }

    public function testWeatherEndpointReturnsCachedData(): void
    {
        $client = static::createClient();

        $client->request('GET', '/api/weather');
        $this->assertResponseIsSuccessful();
        $firstResponse = json_decode($client->getResponse()->getContent(), true);

        $client->request('GET', '/api/weather');
        $this->assertResponseIsSuccessful();
        $secondResponse = json_decode($client->getResponse()->getContent(), true);

        $this->assertSame($firstResponse, $secondResponse);
    }

    public function testWeatherEndpointOnlyAcceptsGetMethod(): void
    {
        $client = static::createClient();
        $client->request('POST', '/api/weather');

        $this->assertResponseStatusCodeSame(405);
    }

    public function testWeatherEndpointReturnsJsonResponse(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/weather');

        $this->assertResponseIsSuccessful();

        $content = $client->getResponse()->getContent();
        $this->assertJson($content);

        $decodedData = json_decode($content, true);
        $this->assertIsArray($decodedData);
        $this->assertNotEmpty($decodedData);
    }

    public function testWeatherEndpointContainsRequiredFields(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/weather');

        $this->assertResponseIsSuccessful();

        $data = json_decode($client->getResponse()->getContent(), true);

        $this->assertArrayHasKey('latitude', $data);
        $this->assertArrayHasKey('longitude', $data);
        $this->assertArrayHasKey('current', $data);
        $this->assertArrayHasKey('hourly', $data);
        $this->assertArrayHasKey('temperature_2m', $data['current']);
        $this->assertArrayHasKey('temperature_2m', $data['hourly']);
        $this->assertIsArray($data['hourly']['temperature_2m']);
    }

    public function testWeatherEndpointIsPubliclyAccessible(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/weather');

        $this->assertResponseIsSuccessful();

        $statusCode = $client->getResponse()->getStatusCode();
        $this->assertNotSame(401, $statusCode);
        $this->assertNotSame(403, $statusCode);
    }
}
