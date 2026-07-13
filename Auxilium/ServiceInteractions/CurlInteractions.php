<?php

declare(strict_types=1);

namespace Auxilium\ServiceInteractions;

use Auxilium\DataClasses\ProbeResult;
use Auxilium\Utilities\ConfigurationUtilities;
use CurlHandle;
use RuntimeException;

final class CurlInteractions
{
    private CurlHandle $CurlHandler;

    private function __construct(int $timeoutSeconds)
    {
        $handle = curl_init();

        if ($handle === false) {
            throw new RuntimeException('Failed to initialise cURL');
        }

        $this->CurlHandler = $handle;

        curl_setopt($this->CurlHandler, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($this->CurlHandler, CURLOPT_TIMEOUT, $timeoutSeconds);
        curl_setopt($this->CurlHandler, CURLOPT_CONNECTTIMEOUT, $timeoutSeconds);
        curl_setopt($this->CurlHandler, CURLOPT_NOSIGNAL, true);
        curl_setopt($this->CurlHandler, CURLOPT_HEADER, false);

        $config = ConfigurationUtilities::GetUserConfiguration();
        if (($config['Development']['PHPAcceptSelfSignedCertificatesForAPI'] ?? false) === true) {
            curl_setopt($this->CurlHandler, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($this->CurlHandler, CURLOPT_SSL_VERIFYHOST, false);
        }
    }

    public static function Probe(string $url, int $timeoutSeconds = 10): ProbeResult
    {
        $prober = new self($timeoutSeconds);
        curl_setopt($prober->CurlHandler, CURLOPT_URL, $url);

        try {
            $body = curl_exec($prober->CurlHandler);
            $responseMs = ((float)curl_getinfo($prober->CurlHandler, CURLINFO_TOTAL_TIME)) * 1000.0;

            if ($body === false) {
                return new ProbeResult(
                    reachable: false,
                    statusCode: 0,
                    responseMs: $responseMs,
                    error: curl_error($prober->CurlHandler) ?: 'Unknown cURL error',
                    errorCode: curl_errno($prober->CurlHandler),
                );
            }

            return new ProbeResult(
                reachable: true,
                statusCode: (int)curl_getinfo($prober->CurlHandler, CURLINFO_HTTP_CODE),
                responseMs: $responseMs,
                body: $body,
            );
        } finally {
            curl_close($prober->CurlHandler);
        }
    }
}
