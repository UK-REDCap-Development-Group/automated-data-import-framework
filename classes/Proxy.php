<?php

namespace UKModules\ADIF;

use ExternalModules\AbstractExternalModule;
use ExternalModules\ExternalModules;
use REDCap;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
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
            array_push($apiFields, ...$this->apiFieldsForSource($source['index'], $source['url'], $source['schema']));
        }

        // Uploaded tables (see DataTables), whose source is "table:<id>" rather than an index
        array_push($apiFields, ...$this->getDataTableFields());

        return $apiFields;
    }

    // The fields one API source offers, from its URL and schema (a JSON string or decoded array) - the
    // per-source half of getApiFields(). Separate so the Data Sources page can work out what a source
    // would offer with settings that haven't been saved yet (see ApiSources::apiSourceBreakage).
    public function apiFieldsForSource($index, $url, $schema)
    {
        if (is_string($schema)) {
            $schema = json_decode($schema, true);
        }
        if (!is_array($schema)) {
            return [];
        }

        $fieldList = (isset($schema['fields']) && is_array($schema['fields'])) ? $schema['fields'] : $schema;

        $fields = [];
        foreach ($fieldList as $entry) {
            if (is_string($entry)) {
                $entry = ['field' => $entry];
            }
            if (!is_array($entry) || empty($entry['field'])) {
                continue;
            }
            $fields[] = [
                'source' => $index,
                'field' => $entry['field'],
                'endpoint' => $entry['endpoint'] ?? trim((string) $url),
                'path' => $entry['path'] ?? $entry['endpoint'] ?? '',
            ];
        }

        return $fields;
    }

    // Pulls the configured API source at $sourceIndex out of the repeatable "api-sources" group.
    // Returns ['url' => ..., 'apiKey' => ..., 'keyPlacement' => (see apiKeyPlacement), 'schema' => ...,
    // 'responseTable' => whether its responses are tables (see apiTableRows)]
    protected function getApiSource($sourceIndex)
    {
        $urls = $this->getProjectSetting('api') ?: [];
        $apiKeys = $this->getProjectSetting('api-key') ?: [];
        $schemas = $this->getProjectSetting('schema') ?: [];
        $keyLocations = $this->getProjectSetting('api-key-location') ?: [];
        $keyNames = $this->getProjectSetting('api-key-name') ?: [];
        $tables = $this->getProjectSetting('response-table') ?: [];

        if (!array_key_exists($sourceIndex, $urls) || empty(trim((string) $urls[$sourceIndex]))) {
            throw new \Exception("No API source is configured at index \"$sourceIndex\".");
        }

        return [
            'url' => trim($urls[$sourceIndex]),
            'apiKey' => trim((string) ($apiKeys[$sourceIndex] ?? '')),
            'keyPlacement' => self::apiKeyPlacement(is_array($keyLocations) ? ($keyLocations[$sourceIndex] ?? null) : null,
                is_array($keyNames) ? ($keyNames[$sourceIndex] ?? null) : null),
            'schema' => $schemas[$sourceIndex] ?? null,
            'responseTable' => is_array($tables) && self::apiSettingIsTrue($tables[$sourceIndex] ?? null),
        ];
    }

    // Where a source's API key goes: ['location' => 'bearer' | 'header' | 'query', 'name' => the header or
    // query parameter's name]. Anything unset or unknown is a Bearer header, as before the setting existed.
    public static function apiKeyPlacement($location, $name = null)
    {
        $location = in_array($location, ['header', 'query'], true) ? $location : 'bearer';
        $name = trim((string) $name);
        if ($location === 'header' && $name === '') {
            $name = 'X-API-Key';
        } elseif ($location === 'query' && $name === '') {
            $name = 'key';
        }
        return ['location' => $location, 'name' => $location === 'bearer' ? '' : $name];
    }

    // A checkbox sub-setting's saved value, however the framework or the Data Sources page stored it
    public static function apiSettingIsTrue($value)
    {
        return $value === true || $value === 1 || $value === '1' || $value === 'true';
    }

    // An API response that's a table - a list of rows whose first row names the columns, as the Census
    // API returns - turned into a list of records keyed by those names, the way an uploaded JSON table is
    // read (see DataTableFormats): [["NAME","B01003_001E"],["Tract 1","4321"]] becomes
    // [{"NAME":"Tract 1","B01003_001E":"4321"}], so a value is mapped as [0].B01003_001E instead of by
    // position. Used for sources whose "response-table" setting is ticked; a response that isn't shaped
    // like a table (an error object, say) is returned unchanged.
    public static function apiTableRows($data)
    {
        if (!self::looksLikeApiTable($data)) {
            return $data;
        }
        $header = array_map(fn($name) => (string) $name, $data[0]);
        $rows = [];
        foreach (array_slice($data, 1) as $values) {
            $row = [];
            foreach ($header as $i => $name) {
                // A blank or repeated column name can't be told apart in a mapping path
                if ($name === '' || array_key_exists($name, $row)) {
                    continue;
                }
                $row[$name] = $values[$i] ?? null;
            }
            $rows[] = $row;
        }
        return $rows;
    }

    // Whether a decoded response has a table's shape: a list whose first entry is a list of column names
    // (text), followed by at least one list of values
    public static function looksLikeApiTable($data)
    {
        if (!is_array($data) || !array_is_list($data) || count($data) < 2
            || !is_array($data[0]) || !array_is_list($data[0]) || empty($data[0])) {
            return false;
        }
        foreach ($data[0] as $name) {
            if (!is_string($name)) {
                return false;
            }
        }
        foreach (array_slice($data, 1) as $row) {
            if (!is_array($row) || !array_is_list($row)) {
                return false;
            }
        }
        return true;
    }

    // Sends a request to a configured API source and returns everything about the exchange
    // (status, headers, timing, raw + decoded body) instead of emitting it, so callers can either
    // echo it straight through (see proxyRequest) or render it (see pages/API_Test.php).
    // A "table:<id>" source is an uploaded table, answered from its file instead (see DataTables).
    public function dispatchApiRequest($sourceIndex, $apiPath, $method = 'GET', $payload = [])
    {
        if ($this->isDataTableSource($sourceIndex)) {
            return $this->queryDataTable($sourceIndex, $apiPath);
        }

        $source = $this->getApiSource($sourceIndex);

        return $this->sendApiRequest($source['url'], $source['apiKey'], $apiPath, $method, $payload, $source['keyPlacement']);
    }

    // Does the actual HTTP work for dispatchApiRequest. Kept separate so schema detection can
    // call a URL and key typed into the configuration dialog before they've been saved.
    // $keyPlacement says where the key goes (see apiKeyPlacement); null is a Bearer header. The key is
    // never in what's returned: requestUrl and requestHeaders show [redacted] in its place, and it's
    // removed from error messages, which quote the URL.
    public function sendApiRequest($baseUrl, $apiKey, $apiPath, $method = 'GET', $payload = [], $keyPlacement = null)
    {
        $apiPath = (string) $apiPath;
        // A path that is only a query string (?a=b) attaches directly, rather than as /?a=b,
        // so a source URL can point at a single resource such as a Socrata dataset.
        $apiUrl = rtrim($baseUrl, '/') . (str_starts_with($apiPath, '?') ? $apiPath : '/' . ltrim($apiPath, '/'));
        $method = strtoupper($method);

        //$client = new Client(); // disabled because it didn't work on our test instance despite SSL being enabled on that server
        // The timeout matters for imports, which run while a survey respondent waits on the completion page
        // HTTPS only, certificates checked, and a redirect can't drop the request to plain http
        $client = new Client(['verify' => true, 'timeout' => 30, 'allow_redirects' => ['protocols' => ['https']]]);

        $headers = [
            'Accept' => 'application/json'
        ];
        // What the result reports as sent: the same, with the key itself replaced
        $shownHeaders = $headers;
        $sendUrl = $apiUrl;
        $shownUrl = $apiUrl;

        // Sources configured without an API key are called unauthenticated. With one, the key goes where
        // the source says the API expects it: a Bearer header by default, a named header, or a named
        // query parameter such as the Census API's key=. It's added here, at request time, so it never
        // has to be written into a schema path, and stays out of cache keys and import logs.
        $apiKey = trim((string) $apiKey);
        if ($apiKey !== '') {
            $placement = self::apiKeyPlacement($keyPlacement['location'] ?? null, $keyPlacement['name'] ?? null);
            if ($placement['location'] === 'query') {
                $separator = str_contains($apiUrl, '?') ? '&' : '?';
                $sendUrl = $apiUrl . $separator . rawurlencode($placement['name']) . '=' . rawurlencode($apiKey);
                $shownUrl = $apiUrl . $separator . rawurlencode($placement['name']) . '=[redacted]';
            } elseif ($placement['location'] === 'header') {
                $headers[$placement['name']] = $apiKey;
                $shownHeaders[$placement['name']] = '[redacted]';
            } else {
                $headers['Authorization'] = "Bearer {$apiKey}";
                $shownHeaders['Authorization'] = 'Bearer [redacted]';
            }
        }
        $redact = fn($text) => $apiKey === '' || $text === null ? $text
            : str_replace([$apiKey, rawurlencode($apiKey)], '[redacted]', (string) $text);

        $requestOptions = [
            'headers' => $headers
        ];

        // Add payload if it's a POST/PUT/PATCH request
        if (!empty($payload) && in_array($method, ['POST', 'PUT', 'PATCH'])) {
            $requestOptions['json'] = $payload; // Guzzle handles JSON encoding and headers
        }

        $result = [
            'requestUrl' => $shownUrl,
            'requestMethod' => $method,
            'requestHeaders' => $shownHeaders,
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

        // Requests can carry record values (an address, an ID), so they're never sent unencrypted. Sources
        // are checked when they're saved (see ApiSources), but one saved before that rule, or through the
        // module configuration dialog, which doesn't check, is refused here.
        if (!preg_match('#^https://#i', $apiUrl)) {
            $result['error'] = 'ADIF only connects to APIs over HTTPS. Change this source\'s URL to its https:// address.';
            return $result;
        }

        $start = microtime(true);

        try {
            $response = $client->request($method, $sendUrl, $requestOptions);
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
            $result['error'] = $redact($e->getMessage());

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
        } catch (GuzzleException $e) {
            // Timeouts and connection failures: in Guzzle 7 ConnectException isn't a RequestException,
            // so without this they'd escape and skip the cache's "use the stored value" fallback.
            $result['elapsedMs'] = round((microtime(true) - $start) * 1000, 1);
            $result['error'] = $redact($e->getMessage());
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
