<?php

namespace UKModules\ADIF;

use ExternalModules\AbstractExternalModule;
use ExternalModules\ExternalModules;
use REDCap;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

class Proxy
{
    // Functional proxy to hit from frontend to communicate with external APIs. Used in proxy.php
    // This version is assuming data is included as a json (not using JSON.stringify).
    // Ensure requests to proxyRequest will have the csrf token included in the json.
    public function proxyRequest($apiPath, $method = 'GET', $payload = [])
    {
        //$client = new Client(); // disabled because it didn't work on our test instance despite SSL being enabled on that server
        $client = new Client(['verify' => false]);
        $tokenUrl = trim($this->getProjectSetting('oncore-token-url') ?: '');
        $baseUrl = trim($this->getProjectSetting('oncore-api-url') ?: '');

        if (empty($tokenUrl)) {
            throw new \Exception("Token URL is not configured.");
        }

        if (empty($baseUrl)) {
            throw new \Exception("API URL is not configured.");
        }

        $apiUrl = rtrim($baseUrl, '/') . '/' . ltrim($apiPath, '/');
        $clientId = $this->getProjectSetting('oncore-client');
        $clientSecret = $this->getProjectSetting('oncore-secret');

        try {
            // Fetch Token
            $token_response = $client->post($tokenUrl, [
                'headers' => [
                    'Content-Type' => 'application/x-www-form-urlencoded',
                ],
                'form_params' => [
                    "client_id" => $clientId,
                    "client_secret" => $clientSecret,
                    "grant_type" => "client_credentials"
                ],
                'verify' => false, // Force bypass on this specific request
                'curl' => [
                    CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_SSL_VERIFYHOST => false
                ]
            ]);

            // Read the stream exactly once
            $tokenResponseBody = (string) $token_response->getBody();
            $token_data = json_decode($tokenResponseBody, true);

            $access_token = $token_data['access_token'] ?? null;

            // Ensure we actually got a token before proceeding
            if (!$access_token) {
                error_log("OnCore Token Error: " . $tokenResponseBody);
                throw new \Exception("Failed to retrieve access token from OnCore.");
            }

            // Make the actual API Request
            $requestOptions = [
                'headers' => [
                    'Authorization' => "Bearer $access_token",
                    'Accept' => 'application/json'
                ]
            ];

            // Add payload if it's a POST/PUT request
            if (!empty($payload) && in_array(strtoupper($method), ['POST', 'PUT', 'PATCH'])) {
                $requestOptions['json'] = $payload; // Guzzle handles JSON encoding and headers
            }

            $response = $client->request(strtoupper($method), $apiUrl, $requestOptions);

            http_response_code($response->getStatusCode());
            echo $response->getBody()->getContents();

        } catch (RequestException $e) {
            http_response_code(500);

            // Safely extract the response body if it exists
            $errorBody = $e->hasResponse() ? (string) $e->getResponse()->getBody() : 'No response from server';

            echo json_encode([
                'error' => 'Request failed',
                'code' => $e->getCode(),
                'message' => $e->getMessage(),
                'oncore_details' => json_decode($errorBody) ?? $errorBody
            ]);
        }
    }
}