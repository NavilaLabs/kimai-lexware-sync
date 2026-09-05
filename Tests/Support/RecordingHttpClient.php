<?php

declare(strict_types=1);

namespace KimaiPlugin\KimaiLexwareSyncBundle\Tests\Support;

use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

abstract class RecordingHttpClient implements HttpClientInterface
{
    /**
     * @var list<array{method: string, path: string, response: MockResponse, used: bool}>
     */
    private array $stubs = [];

    /**
     * @var list<RecordedRequest>
     */
    private array $recordedRequests = [];

    private readonly MockHttpClient $client;

    public function __construct()
    {
        $this->client = new MockHttpClient($this->respond(...));
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function willRespondWith(string $method, string $path, array $payload, int $status = 200): void
    {
        $this->willRespondWithBody($method, $path, json_encode($payload, JSON_THROW_ON_ERROR), $status, ['content-type' => 'application/json']);
    }

    /**
     * @param array<string, string> $headers
     */
    public function willRespondWithBody(string $method, string $path, string $body, int $status = 200, array $headers = []): void
    {
        $this->stubs[] = [
            'method' => strtoupper($method),
            'path' => $path,
            'response' => new MockResponse($body, ['http_code' => $status, 'response_headers' => $headers]),
            'used' => false,
        ];
    }

    /**
     * @return list<RecordedRequest>
     */
    public function recordedRequests(): array
    {
        return $this->recordedRequests;
    }

    public function requestCount(): int
    {
        return \count($this->recordedRequests);
    }

    public function hasUnusedStubs(): bool
    {
        foreach ($this->stubs as $stub) {
            if (!$stub['used']) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param mixed[] $options
     */
    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        return $this->client->request($method, $url, $options);
    }

    public function stream(ResponseInterface|iterable $responses, ?float $timeout = null): ResponseStreamInterface
    {
        return $this->client->stream($responses, $timeout);
    }

    /**
     * @param mixed[] $options
     */
    public function withOptions(array $options): static
    {
        return $this;
    }

    /**
     * @param mixed[] $options
     */
    private function respond(string $method, string $url, array $options): MockResponse
    {
        $path = parse_url($url, PHP_URL_PATH);
        $this->recordedRequests[] = new RecordedRequest($method, \is_string($path) ? $path : $url, $url, $options['body'] ?? null);

        foreach ($this->stubs as $index => $stub) {
            if ($stub['used'] || $stub['method'] !== strtoupper($method) || $stub['path'] !== $path) {
                continue;
            }

            $this->stubs[$index]['used'] = true;

            return $stub['response'];
        }

        throw new UnexpectedHttpRequest(\sprintf(
            'The test made an unexpected request: %s %s. Declare it with willRespondWith() if it is intended.',
            $method,
            $url
        ));
    }
}
