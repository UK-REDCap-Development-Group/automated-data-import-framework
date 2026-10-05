<?php

namespace UKModules\ADIF;

// Mixed into UKModules\ADIF\ADIF alongside Proxy. Builds an ADIF schema ({"fields": [...]}, see
// Proxy::getApiFields) from what an API says about itself, so it doesn't have to be written by hand.
// Called from the "Detect schema" button in the module configuration dialog (js/schema_detect.js).
//
// Detectors are tried in order, and the first one that recognises the API wins:
//   socrata - Socrata (SODA) datasets, such as data.cdc.gov, list their columns in the
//             X-SODA2-Fields and X-SODA2-Types response headers
//   openapi - an OpenAPI 3 or Swagger 2 document published at a conventional location
//   sample  - otherwise, fields are read off the structure of an actual response
trait SchemaDetection
{
    // Where OpenAPI/Swagger documents are conventionally published, relative to the source URL
    private static $openApiLocations = ['openapi.json', 'swagger.json', 'v3/api-docs', 'api-docs', 'swagger/v1/swagger.json'];

    // Socrata column types that come back as objects rather than a single value
    private static $socrataObjectTypes = [
        'point', 'multipoint', 'line', 'linestring', 'multiline', 'multilinestring', 'polygon',
        'multipolygon', 'location', 'url', 'phone', 'photo', 'document', 'blob',
    ];

    // Guards against very large documents and responses
    private static $maxDetectedFields = 500;
    private static $maxDetectedDepth = 6;

    // $probePath is the endpoint to check, appended to $baseUrl the same way a schema entry's path
    // is, and may contain [field_name] placeholders. Detected fields are given that path verbatim,
    // so the placeholders are filled from the record at import time.
    // Returns ['schema' => [...], 'method' => 'socrata'|'openapi'|'sample', 'message' => ...]
    public function detectApiSchema($baseUrl, $apiKey = '', $probePath = '')
    {
        $baseUrl = trim((string) $baseUrl);
        $apiKey = trim((string) $apiKey);
        $probePath = trim((string) $probePath);

        if (!preg_match('#^https?://#i', $baseUrl)) {
            throw new \Exception('Enter the source\'s URL, starting with http:// or https://, before detecting its schema.');
        }

        $tried = [];
        $label = $probePath !== '' ? $probePath : $baseUrl;

        // The probe endpoint itself: Socrata headers, or failing everything else, a sample of its fields
        $probe = null;
        $requestPath = $this->schemaProbeRequestPath($baseUrl, $probePath);
        if ($requestPath === null) {
            $tried[] = "$probePath was not requested, since a [placeholder] in its path can only be filled from a record";
        } else {
            $probe = $this->sendApiRequest($baseUrl, $apiKey, $requestPath);
            $tried[] = $this->describeAttempt($probe);

            if ($probe['success']) {
                $fields = $this->socrataSchemaFields($probe, $label, $probePath);
                if (!empty($fields)) {
                    return $this->detectedSchema('socrata', $probe['requestUrl'], $fields,
                        'Read ' . count($fields) . ' fields from the dataset\'s Socrata column headers.');
                }
            }
        }

        foreach (self::$openApiLocations as $location) {
            $document = $this->sendApiRequest($baseUrl, $apiKey, $location);
            $tried[] = $this->describeAttempt($document);

            $json = $document['bodyJson'];
            if (!$document['success'] || !is_array($json) || !(isset($json['openapi']) || isset($json['swagger'])) || !isset($json['paths'])) {
                continue;
            }

            $fields = $this->openApiSchemaFields($json, $probePath);
            if (!empty($fields)) {
                $endpoints = count(array_unique(array_column($fields, 'endpoint')));
                return $this->detectedSchema('openapi', $document['requestUrl'], $fields,
                    'Read ' . count($fields) . " fields across $endpoints endpoint(s) from the API's OpenAPI document.");
            }
        }

        if ($probe !== null && $probe['success'] && is_array($probe['bodyJson'])) {
            $fields = [];
            foreach ($this->inferSchemaPaths($probe['bodyJson']) as $path) {
                $fields[] = ['field' => $path, 'endpoint' => $label, 'path' => $probePath];
            }
            if (!empty($fields)) {
                return $this->detectedSchema('sample', $probe['requestUrl'], $fields,
                    'The API doesn\'t publish a schema, so ' . count($fields) . ' fields were read from a sample response.'
                    . ' Fields the sample didn\'t include will be missing.');
            }
        }

        throw new \Exception("No schema could be found. Tried:\n- " . implode("\n- ", $tried));
    }

    private function detectedSchema($method, $from, $fields, $message)
    {
        if (count($fields) > self::$maxDetectedFields) {
            $fields = array_slice($fields, 0, self::$maxDetectedFields);
            $message .= ' Only the first ' . self::$maxDetectedFields . ' were kept.';
        }

        return [
            'schema' => [
                // Extra keys are ignored by getApiFields(); this one records where the fields came from
                'detected' => ['method' => $method, 'from' => $from, 'at' => date('Y-m-d H:i')],
                'fields' => $fields,
            ],
            'method' => $method,
            'message' => $message,
        ];
    }

    private function describeAttempt($result)
    {
        $outcome = $result['statusCode'] ? $result['statusCode'] . ' ' . $result['reasonPhrase'] : ($result['error'] ?: 'no response');
        return "GET {$result['requestUrl']} ({$outcome})";
    }

    // The probe path with anything that needs a record removed: query parameters whose value is a
    // [placeholder] are dropped (a Socrata filter like ?locationid=[geoid] becomes a plain sample),
    // and Socrata datasets are limited to one row. Returns null when the path itself has a placeholder.
    private function schemaProbeRequestPath($baseUrl, $probePath)
    {
        $parts = explode('?', $probePath, 2);
        $path = $parts[0];
        $query = $parts[1] ?? '';

        if (preg_match('/\[[a-z][a-z0-9_]*\]/', $path)) {
            return null;
        }

        $kept = [];
        foreach ($query === '' ? [] : explode('&', $query) as $pair) {
            if ($pair !== '' && !preg_match('/\[[a-z][a-z0-9_]*\]/', $pair)) {
                $kept[] = $pair;
            }
        }

        $isSocrata = preg_match('#/resource/[a-z0-9]{4}-[a-z0-9]{4}(\.json)?$#i', rtrim($baseUrl, '/') . '/' . ltrim($path, '/'));
        if ($isSocrata && !preg_grep('/^\$limit=/', $kept)) {
            $kept[] = '$limit=1';
        }

        return $path . (empty($kept) ? '' : '?' . implode('&', $kept));
    }

    private function socrataSchemaFields($response, $label, $probePath)
    {
        $columns = json_decode($this->responseHeader($response, 'X-SODA2-Fields') ?? '', true);
        $types = json_decode($this->responseHeader($response, 'X-SODA2-Types') ?? '', true);
        if (!is_array($columns) || empty($columns)) {
            return [];
        }

        $fields = [];
        foreach ($columns as $position => $column) {
            $type = is_array($types) ? strtolower((string) ($types[$position] ?? '')) : '';
            // Columns starting with ":" are Socrata's own bookkeeping, e.g. :@computed_region_...
            if (!is_string($column) || $column === '' || $column[0] === ':' || in_array($type, self::$socrataObjectTypes)) {
                continue;
            }
            // A Socrata response is a list of rows. [0] reads the first row matching the path's filters.
            $fields[] = ['field' => $this->joinSchemaPath('[0]', $column), 'endpoint' => $label, 'path' => $probePath];
        }

        return $fields;
    }

    private function responseHeader($response, $name)
    {
        foreach ((array) $response['responseHeaders'] as $header => $values) {
            if (strcasecmp($header, $name) === 0) {
                return is_array($values) ? implode(', ', $values) : (string) $values;
            }
        }
        return null;
    }

    // One field per value reachable from each GET operation's successful response. Path templates
    // like /people/{personId} become /people/[personid], to be renamed to the REDCap field that holds
    // the value. When a probe path is given, only operations matching it are used, if any do.
    private function openApiSchemaFields($document, $probePath)
    {
        $probe = '/' . ltrim(explode('?', $probePath, 2)[0], '/');
        $all = [];
        $matching = [];

        foreach ((array) $document['paths'] as $template => $operations) {
            if (!is_array($operations) || !isset($operations['get']) || !is_array($operations['get'])) {
                continue;
            }

            $schema = $this->openApiResponseSchema($document, $operations['get']);
            if ($schema === null) {
                continue;
            }

            $requestPath = preg_replace_callback('/\{([^}]+)\}/', function ($match) {
                return '[' . $this->placeholderName($match[1]) . ']';
            }, $template);

            $fields = [];
            foreach ($this->openApiSchemaPaths($document, $schema) as $path) {
                $fields[] = ['field' => $path, 'endpoint' => "GET $template", 'path' => $requestPath];
            }
            array_push($all, ...$fields);

            $pattern = '#^' . preg_replace('/\\\\\{[^}]+\\\\\}/', '[^/]+', preg_quote('/' . ltrim($template, '/'), '#')) . '/?$#';
            if ($probePath !== '' && preg_match($pattern, $probe)) {
                array_push($matching, ...$fields);
            }
        }

        return empty($matching) ? $all : $matching;
    }

    // The JSON schema of a GET operation's 200 (or other 2xx/default) response, for OpenAPI 3 or Swagger 2
    private function openApiResponseSchema($document, $operation)
    {
        $responses = (array) ($operation['responses'] ?? []);
        $response = null;
        foreach (array_merge(['200', '201', '2XX'], array_keys($responses), ['default']) as $code) {
            if (isset($responses[$code]) && ($code === 'default' || preg_match('/^2/', (string) $code))) {
                $response = $this->resolveSchemaRef($document, $responses[$code]);
                break;
            }
        }
        if (!is_array($response)) {
            return null;
        }

        if (isset($response['schema'])) {
            return $response['schema'];
        }
        foreach ((array) ($response['content'] ?? []) as $mediaType => $content) {
            if (stripos($mediaType, 'json') !== false && isset($content['schema'])) {
                return $content['schema'];
            }
        }
        return null;
    }

    // Only local references (#/components/schemas/Person, #/definitions/Person) can be followed
    private function resolveSchemaRef($document, $node, &$seen = [])
    {
        while (is_array($node) && isset($node['$ref']) && is_string($node['$ref'])) {
            $ref = $node['$ref'];
            if (!str_starts_with($ref, '#/') || isset($seen[$ref])) {
                return null;
            }
            $seen[$ref] = true;

            $node = $document;
            foreach (explode('/', substr($ref, 2)) as $key) {
                $key = str_replace(['~1', '~0'], ['/', '~'], $key);
                if (!is_array($node) || !array_key_exists($key, $node)) {
                    return null;
                }
                $node = $node[$key];
            }
        }
        return $node;
    }

    private function openApiSchemaPaths($document, $schema, $prefix = '', $depth = 0, $seen = [])
    {
        $schema = $this->resolveSchemaRef($document, $schema, $seen);
        if (!is_array($schema)) {
            return [];
        }
        if ($depth > self::$maxDetectedDepth) {
            return $prefix === '' ? [] : [$prefix];
        }

        // allOf combines its parts; for oneOf/anyOf the first option stands in for the rest
        if (isset($schema['allOf']) && is_array($schema['allOf'])) {
            $paths = [];
            foreach ($schema['allOf'] as $part) {
                array_push($paths, ...$this->openApiSchemaPaths($document, $part, $prefix, $depth, $seen));
            }
            return array_values(array_unique($paths));
        }
        foreach (['oneOf', 'anyOf'] as $keyword) {
            if (!empty($schema[$keyword]) && is_array($schema[$keyword])) {
                return $this->openApiSchemaPaths($document, reset($schema[$keyword]), $prefix, $depth, $seen);
            }
        }

        $type = $schema['type'] ?? null;
        if (is_array($type)) {
            $type = current(array_diff($type, ['null'])) ?: null;
        }

        if ($type === 'array' || isset($schema['items'])) {
            return $this->openApiSchemaPaths($document, $schema['items'] ?? [], $prefix . '[0]', $depth + 1, $seen);
        }

        if ($type === 'object' || isset($schema['properties'])) {
            $paths = [];
            foreach ((array) ($schema['properties'] ?? []) as $property => $propertySchema) {
                $path = $this->joinSchemaPath($prefix, (string) $property);
                if ($path !== null) {
                    array_push($paths, ...$this->openApiSchemaPaths($document, $propertySchema, $path, $depth + 1, $seen));
                }
            }
            return $paths;
        }

        return $prefix === '' ? [] : [$prefix];
    }

    // Every value path in a decoded response. Lists are read through their first entry ([0]), which is
    // also the entry a [0] path reads at import time, so a key only later entries have isn't offered.
    private function inferSchemaPaths($data, $prefix = '', $depth = 0)
    {
        if (!is_array($data)) {
            return $prefix === '' ? [] : [$prefix];
        }
        if (empty($data) || $depth > self::$maxDetectedDepth) {
            return [];
        }

        $paths = [];
        if (array_is_list($data)) {
            $paths = $this->inferSchemaPaths($data[0], $prefix . '[0]', $depth + 1);
        } else {
            foreach ($data as $key => $value) {
                $path = $this->joinSchemaPath($prefix, (string) $key);
                if ($path !== null) {
                    array_push($paths, ...$this->inferSchemaPaths($value, $path, $depth + 1));
                }
            }
        }

        return array_values(array_unique($paths));
    }

    // Appends a key to a mapping path in the form resolveApiPath() reads. A key containing ".", "[" or
    // "]" is written in brackets with "]" and "\" escaped. A key containing "=" can't be expressed,
    // since a bracket with "=" in it is a filter, so null is returned and the key is left out.
    private function joinSchemaPath($prefix, $key)
    {
        if ($key === '' || strpos($key, '=') !== false) {
            return null;
        }

        if (strpbrk($key, '.[]') !== false) {
            return $prefix . '[' . addcslashes($key, '\\]') . ']';
        }
        return $prefix === '' ? $key : $prefix . '.' . $key;
    }

    // OpenAPI parameter names become REDCap-style placeholders, which must be lowercase variable names
    private function placeholderName($name)
    {
        $name = trim(preg_replace('/[^a-z0-9]+/', '_', strtolower($name)), '_');
        return preg_match('/^[a-z]/', $name) ? $name : 'field_' . $name;
    }
}
