<?php
/**
 * MyInvois (LHDN Malaysia) API Client
 *
 * Reference: https://sdk.myinvois.hasil.gov.my/api/
 *
 * Note on digital signatures:
 * Sandbox environment does NOT require document signing.
 * Production requires signing with a certificate issued by LHDN-approved CA.
 * To enable production signing, implement the 'UBLExtensions' + 'Signature' element
 * in the document using openssl_sign() with your private key.
 */
class MyInvoisAPI
{
    private string $baseUrl;
    private string $clientId;
    private string $clientSecret;
    private ?string $accessToken = null;
    private int $tokenExpiry = 0;
    private string $lastError = '';

    public function __construct(string $env, string $clientId, string $clientSecret)
    {
        $this->baseUrl       = $env === 'production' ? MYINVOIS_PROD_BASE : MYINVOIS_SANDBOX_BASE;
        $this->clientId      = $clientId;
        $this->clientSecret  = $clientSecret;
    }

    /**
     * Authenticate via OAuth2 client_credentials grant.
     * Token is cached in PHP session for up to MYINVOIS_TOKEN_TTL seconds.
     */
    public function authenticate(): bool
    {
        // Return cached token if still valid
        if (isset($_SESSION['myinvois_token'])
            && isset($_SESSION['myinvois_token_expiry'])
            && time() < $_SESSION['myinvois_token_expiry']) {
            $this->accessToken = $_SESSION['myinvois_token'];
            return true;
        }

        $result = $this->makeRequest('POST', '/connect/token', http_build_query([
            'grant_type'    => 'client_credentials',
            'client_id'     => $this->clientId,
            'client_secret' => $this->clientSecret,
            'scope'         => 'InvoicingAPI',
        ]), ['Content-Type: application/x-www-form-urlencoded']);

        if (!empty($result['access_token'])) {
            $this->accessToken = $result['access_token'];
            $ttl = (int)($result['expires_in'] ?? MYINVOIS_TOKEN_TTL);
            $_SESSION['myinvois_token']        = $this->accessToken;
            $_SESSION['myinvois_token_expiry'] = time() + min($ttl - 60, MYINVOIS_TOKEN_TTL);
            log_einvoice("Authenticated. Token expires in {$ttl}s.");
            return true;
        }

        $this->lastError = 'Authentication failed: ' . ($result['error_description'] ?? json_encode($result));
        log_einvoice("Auth error: " . $this->lastError);
        return false;
    }

    /**
     * Submit one or more documents to MyInvois.
     *
     * @param array $documents  Array of ['format'=>'JSON','document'=>base64,'documentHash'=>sha256,'codeNumber'=>string]
     * @return array  API response with acceptedDocuments / rejectedDocuments
     */
    public function submitDocuments(array $documents): array
    {
        if (!$this->accessToken && !$this->authenticate()) {
            return ['error' => $this->lastError];
        }

        $payload = ['documents' => $documents];
        $result  = $this->makeRequest(
            'POST',
            '/api/v1.0/documentsubmissions',
            json_encode($payload),
            [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->accessToken,
            ]
        );

        log_einvoice("submitDocuments response: " . json_encode($result));
        return $result;
    }

    /**
     * Check the status of a submission batch.
     */
    public function getSubmissionStatus(string $submissionUid): array
    {
        if (!$this->accessToken && !$this->authenticate()) {
            return ['error' => $this->lastError];
        }

        $result = $this->makeRequest(
            'GET',
            '/api/v1.0/documents/submissions/' . urlencode($submissionUid),
            null,
            ['Authorization: Bearer ' . $this->accessToken]
        );

        log_einvoice("getSubmissionStatus ({$submissionUid}): " . json_encode($result));
        return $result;
    }

    /**
     * Get full details of a validated document by UUID.
     */
    public function getDocumentDetails(string $uuid): array
    {
        if (!$this->accessToken && !$this->authenticate()) {
            return ['error' => $this->lastError];
        }

        $result = $this->makeRequest(
            'GET',
            '/api/v1.0/documents/' . urlencode($uuid) . '/details',
            null,
            ['Authorization: Bearer ' . $this->accessToken]
        );

        log_einvoice("getDocumentDetails ({$uuid}): " . json_encode($result));
        return $result;
    }

    /**
     * Cancel a document that was previously submitted.
     * Only allowed within 72 hours of validation.
     */
    public function cancelDocument(string $uuid, string $reason): array
    {
        if (!$this->accessToken && !$this->authenticate()) {
            return ['error' => $this->lastError];
        }

        $result = $this->makeRequest(
            'PUT',
            '/api/v1.0/documents/state/' . urlencode($uuid) . '/state',
            json_encode(['status' => 'cancelled', 'reason' => $reason]),
            [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->accessToken,
            ]
        );

        log_einvoice("cancelDocument ({$uuid}): " . json_encode($result));
        return $result;
    }

    /**
     * Make an HTTP request using cURL.
     */
    private function makeRequest(string $method, string $endpoint, $body = null, array $headers = []): array
    {
        $url = $this->baseUrl . $endpoint;
        $ch  = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_CUSTOMREQUEST  => strtoupper($method),
            CURLOPT_HTTPHEADER     => $headers,
        ]);

        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $response  = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_err  = curl_error($ch);
        curl_close($ch);

        if ($curl_err) {
            $this->lastError = 'cURL error: ' . $curl_err;
            log_einvoice("cURL error on {$endpoint}: {$curl_err}");
            return ['error' => $this->lastError];
        }

        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            $this->lastError = "Invalid JSON response (HTTP $http_code): " . substr($response, 0, 200);
            log_einvoice("Invalid response from {$endpoint}: " . substr($response, 0, 200));
            return ['error' => $this->lastError, 'http_code' => $http_code];
        }

        if ($http_code >= 400) {
            $msg = $decoded['error'] ?? ($decoded['message'] ?? json_encode($decoded));
            $this->lastError = "HTTP $http_code: $msg";
            log_einvoice("API error from {$endpoint}: " . $this->lastError);
        }

        return $decoded;
    }

    public function getLastError(): string
    {
        return $this->lastError;
    }

    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }
}
