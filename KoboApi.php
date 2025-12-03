<?php

namespace Kobo;

class KoboApi
{
    protected $api_url;
    protected $api_token;

    public function __construct(string $api_url, string $api_token)
    {
        $this->api_url = rtrim($api_url, '/');
        $this->api_token = $api_token;
    }

    protected function request(string $endpoint, string $method = 'GET', array $data = []): ?array
    {
        $url = $this->api_url . '/' . ltrim($endpoint, '/');
        
        $headers = [
            'Authorization: Token ' . $this->api_token,
            'Content-Type: application/json',
        ];

        $ch = curl_init();
        
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        if (!empty($data) && in_array($method, ['POST', 'PUT', 'PATCH'])) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        
        curl_close($ch);

        if ($error) {
            throw new \Exception("Erro na requisição para API do Kobo: {$error}");
        }

        if ($http_code >= 400) {
            $error_message = "Erro HTTP {$http_code} na API do Kobo";
            if ($response) {
                $error_data = json_decode($response, true);
                if (isset($error_data['detail'])) {
                    $error_message .= ": {$error_data['detail']}";
                }
            }
            throw new \Exception($error_message);
        }

        return $response ? json_decode($response, true) : null;
    }

    public function getSubmissions(string $assetUid, array $params = []): array
    {
        $queryString = '';
        if (!empty($params)) {
            $queryString = '?' . http_build_query($params);
        }
        
        $response = $this->request("assets/{$assetUid}/data/{$queryString}");
        return $response['results'] ?? [];
    }

    public function getUserFromKobo(string $username): ?array
    {
        return $this->request("users/{$username}/");
    }
}

