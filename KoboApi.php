<?php

namespace Kobo;

use MapasCulturais\i;

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
            CURLOPT_TIMEOUT => 60,
        ]);

        if (!empty($data) && in_array($method, ['POST', 'PUT', 'PATCH'])) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        
        curl_close($ch);

        if ($error) {
            throw new \Exception(sprintf(i::__('Erro na requisição para API do Kobo: %s'), $error));
        }

        if ($http_code >= 400) {   
            $error_message = sprintf(i::__('Erro HTTP %s na API do Kobo'), $http_code);
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
        $response = $this->request("users/");
        $users = $response['results'] ?? [];
        
        foreach ($users as $user) {
            if ($user['username'] == $username) {
                return $user;
            }
        }
        
        return null;
    }
    
    public function getUserEmail(string $username): ?string
    {
        $user = $this->getUserFromKobo($username);
        return $user['email'] ?? null;
    }
    
    public function downloadFile(string $download_url): ?string
    {
        $ch = curl_init($download_url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Token ' . $this->api_token,
            ],
            CURLOPT_TIMEOUT => 300,
        ]);
        
        $file_content = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            throw new \Exception(sprintf(i::__('Erro ao baixar arquivo do Kobo: %s (URL: %s)'), $error, $download_url));
        }
        
        if ($http_code >= 400) {
            $error_msg = sprintf(i::__('Erro HTTP %s ao baixar arquivo do Kobo (URL: %s)'), $http_code, $download_url);
            throw new \Exception($error_msg);
        }
        
        return $file_content;
    }
}

