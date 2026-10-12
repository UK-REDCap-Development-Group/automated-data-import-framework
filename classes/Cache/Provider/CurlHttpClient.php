<?php
namespace UKModules\ADIF\Cache\Provider;

final class CurlHttpClient implements HttpClient
{
    public function __construct(private int $timeoutSeconds = 60) {}

    public function get(string $url, array $query = []): string
    {
        if ($query) {
            $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($query);
        }
        return $this->exec(curl_init($url), []);
    }

    public function postMultipart(string $url, array $multipart): string
    {
        $fields = [];
        foreach ($multipart as $name => $part) {
            $fields[$name] = is_array($part)
                ? new \CURLStringFile($part['contents'], $part['filename'], 'text/csv')
                : $part;
        }
        return $this->exec(curl_init($url), [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $fields]);
    }

    private function exec(\CurlHandle $ch, array $opts): string
    {
        curl_setopt_array($ch, $opts + [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeoutSeconds,
            CURLOPT_FOLLOWLOCATION => true,
        ]);
        $body   = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err    = curl_error($ch);
        curl_close($ch);
        if ($body === false || $status >= 400) {
            throw new ProviderUnavailable($err ?: "HTTP $status");
        }
        return $body;
    }
}
