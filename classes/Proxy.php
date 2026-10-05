<?php

namespace UKModules\ADIF;

use ExternalModules\AbstractExternalModule;
use ExternalModules\ExternalModules;
use REDCap;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

// Mixed into UKModules\ADIF\ADIF (an AbstractExternalModule), so $this has access to
// getProjectSetting() and the rest of the External Modules framework at runtime.
trait Proxy
{
    // The "api-sources" project setting is a repeatable group (see config.json), so each
    // sub-setting (api, schema, api-key) comes back from getProjectSetting() as an array,
    // one entry per configured source, rather than a single scalar value.

    // Returns every configured API source as ['index' => ..., 'url' => ..., 'schema' => ...].
    // The api-key is intentionally left out since this is meant to be safe to hand to the frontend.
    public function getApiSources()
    {
        $urls = $this->getProjectSetting('api') ?: [];
        $schemas = $this->getProjectSetting('schema') ?: [];

        $sources = [];
        foreach ($urls as $index => $url) {
            if (empty(trim((string) $url))) {
                continue;
            }
            $sources[] = [
                'index' => $index,
                'url' => trim($url),
                'schema' => $schemas[$index] ?? null,
            ];
        }

        return $sources;
    }

    // Every field made available by any configured API source, read from each source's schema.
    // A schema can be a flat list of field entries, or {"fields": [...]} - either is accepted, and an
    // entry can be a bare path string or {"field": ..., "endpoint": ..., "path": ...}.
    //   endpoint - the label fields are grouped under on the Mapping page (defaults to the source URL)
    //   path     - what's appended to the source URL to fetch the field; defaults to the endpoint
    //              when one is set, otherwise the source URL itself is requested. [field_name]
    //              placeholders are filled from the record being imported into.
    // Returns ['source' => ..., 'field' => ..., 'endpoint' => ..., 'path' => ...] per field.
    public function getApiFields()
    {
        $apiFields = [];
        foreach ($this->getApiSources() as $source) {
            $schema = $source['schema'];
            if (is_string($schema)) {
                $schema = json_decode($schema, true);
            }
            if (!is_array($schema)) {
                continue;
            }

            $fieldList = (isset($schema['fields']) && is_array($schema['fields'])) ? $schema['fields'] : $schema;

            foreach ($fieldList as $entry) {
                if (is_string($entry)) {
                    $entry = ['field' => $entry];
                }
                if (!is_array($entry) || empty($entry['field'])) {
                    continue;
                }
                $apiFields[] = [
                    'source' => $source['index'],
                    'field' => $entry['field'],
                    'endpoint' => $entry['endpoint'] ?? $source['url'],
                    'path' => $entry['path'] ?? $entry['endpoint'] ?? '',
                ];
            }
        }

        return $apiFields;
    }

    // Pulls the configured API source at $sourceIndex out of the repeatable "api-sources" group.
    // Returns ['url' => ..., 'apiKey' => ..., 'schema' => ...]
    protected function getApiSource($sourceIndex)
    {
        $urls = $this->getProjectSetting('api') ?: [];
        $apiKeys = $this->getProjectSetting('api-key') ?: [];
        $schemas = $this->getProjectSetting('schema') ?: [];

        if (!array_key_exists($sourceIndex, $urls) || empty(trim((string) $urls[$sourceIndex]))) {
            throw new \Exception("No API source is configured at index \"$sourceIndex\".");
        }

        return [
            'url' => trim($urls[$sourceIndex]),
            'apiKey' => trim((string) ($apiKeys[$sourceIndex] ?? '')),
            'schema' => $schemas[$sourceIndex] ?? null,
        ];
    }

    // Sends a request to a configured API source and returns everything about the exchange
    // (status, headers, timing, raw + decoded body) instead of emitting it, so callers can either
    // echo it straight through (see proxyRequest) or render it (see pages/API_Test.php).
    public function dispatchApiRequest($sourceIndex, $apiPath, $method = 'GET', $payload = [])
    {
        $source = $this->getApiSource($sourceIndex);

        return $this->sendApiRequest($source['url'], $source['apiKey'], $apiPath, $method, $payload);
    }

    // Does the actual HTTP work for dispatchApiRequest. Kept separate so schema detection can
    // call a URL and key typed into the configuration dialog before they've been saved.
    public function sendApiRequest($baseUrl, $apiKey, $apiPath, $method = 'GET', $payload = [])
    {
        $apiPath = (string) $apiPath;
        // A path that is only a query string (?a=b) attaches directly, rather than as /?a=b,
        // so a source URL can point at a single resource such as a Socrata dataset.
        $apiUrl = rtrim($baseUrl, '/') . (str_starts_with($apiPath, '?') ? $apiPath : '/' . ltrim($apiPath, '/'));
        $method = strtoupper($method);

        //$client = new Client(); // disabled because it didn't work on our test instance despite SSL being enabled on that server
        // The timeout matters for imports, which run while a survey respondent waits on the completion page
        $client = new Client(['verify' => false, 'timeout' => 30]);

        $headers = [
            'Accept' => 'application/json'
        ];

        // Sources configured without an API key are called unauthenticated
        if (!empty($apiKey)) {
            $headers['Authorization'] = "Bearer {$apiKey}";
        }

        $requestOptions = [
            'headers' => $headers
        ];

        // Add payload if it's a POST/PUT/PATCH request
        if (!empty($payload) && in_array($method, ['POST', 'PUT', 'PATCH'])) {
            $requestOptions['json'] = $payload; // Guzzle handles JSON encoding and headers
        }

        $result = [
            'requestUrl' => $apiUrl,
            'requestMethod' => $method,
            'requestHeaders' => $headers,
            'success' => false,
            'statusCode' => null,
            'reasonPhrase' => null,
            'responseHeaders' => [],
            'body' => null,
            'bodyJson' => null,
            'elapsedMs' => null,
            'byteSize' => null,
            'error' => null,
        ];

        $start = microtime(true);

        try {
            $response = $client->request($method, $apiUrl, $requestOptions);
            $result['elapsedMs'] = round((microtime(true) - $start) * 1000, 1);

            $body = $response->getBody()->getContents();
            $decoded = json_decode($body, true);

            $result['success'] = true;
            $result['statusCode'] = $response->getStatusCode();
            $result['reasonPhrase'] = $response->getReasonPhrase();
            $result['responseHeaders'] = $response->getHeaders();
            $result['body'] = $body;
            $result['bodyJson'] = (json_last_error() === JSON_ERROR_NONE) ? $decoded : null;
            $result['byteSize'] = strlen($body);
        } catch (RequestException $e) {
            $result['elapsedMs'] = round((microtime(true) - $start) * 1000, 1);
            $result['error'] = $e->getMessage();

            if ($e->hasResponse()) {
                $response = $e->getResponse();
                $body = (string) $response->getBody();
                $decoded = json_decode($body, true);

                $result['statusCode'] = $response->getStatusCode();
                $result['reasonPhrase'] = $response->getReasonPhrase();
                $result['responseHeaders'] = $response->getHeaders();
                $result['body'] = $body;
                $result['bodyJson'] = (json_last_error() === JSON_ERROR_NONE) ? $decoded : null;
                $result['byteSize'] = strlen($body);
            }
        }

        return $result;
    }

    // Functional proxy to hit from frontend to communicate with external APIs. Used in proxy.php
    // This version is assuming data is included as a json (not using JSON.stringify).
    // Ensure requests to proxyRequest will have the csrf token included in the json.
    // $sourceIndex selects which configured "api-sources" instance (0-based) to send the request to.
    public function proxyRequest($sourceIndex, $apiPath, $method = 'GET', $payload = [])
    {
        $result = $this->dispatchApiRequest($sourceIndex, $apiPath, $method, $payload);

        if ($result['success']) {
            http_response_code($result['statusCode']);
            echo $result['body'];
            return;
        }

        http_response_code($result['statusCode'] ?: 500);
        echo json_encode([
            'error' => 'Request failed',
            'message' => $result['error'],
            'details' => $result['bodyJson'] ?? $result['body']
        ]);
    }
}
