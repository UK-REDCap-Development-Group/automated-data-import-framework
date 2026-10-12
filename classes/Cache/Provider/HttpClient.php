<?php
namespace UKModules\ADIF\Cache\Provider;

/**
 * Minimal HTTP seam so providers are testable without the network.
 * A REDCap implementation can wrap http_get()/http_post() or curl.
 */
interface HttpClient
{
    /** @return string response body; throws ProviderUnavailable on failure */
    public function get(string $url, array $query = []): string;

    /** @param array $multipart name => string|['filename'=>..,'contents'=>..] */
    public function postMultipart(string $url, array $multipart): string;
}
