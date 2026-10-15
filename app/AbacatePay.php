<?php
declare(strict_types=1);

namespace App;

use JsonException;
use RuntimeException;

final class AbacatePay
{
    private string $apiKey;
    private string $baseUrl = 'https://api.abacatepay.com/v2';

    public function __construct()
    {
        $this->apiKey = trim((string) ($_ENV['ABACATEPAY_API_KEY'] ?? ''));

        if ($this->apiKey === '') {
            throw new RuntimeException(
                'Configure ABACATEPAY_API_KEY no arquivo .env antes de criar cobranças.',
            );
        }
    }

    /** Cria uma cobrança Pix transparente e retorna os dados fornecidos pela API. */
    public function createPix(
        int $amountCents,
        string $description,
        string $externalId,
        int $expiresIn = 900,
        array $metadata = [],
    ): array {
        if ($amountCents < 100) {
            throw new RuntimeException('O valor da cobrança deve ser de pelo menos R$ 1,00.');
        }

        $payload = [
            'method' => 'PIX',
            'data' => [
                'amount' => $amountCents,
                'expiresIn' => max(300, min(3600, $expiresIn)),
                'description' => mb_substr(trim($description), 0, 100),
                'externalId' => $externalId,
                'metadata' => $metadata,
            ],
        ];

        return $this->request('POST', '/transparents/create', $payload);
    }

    public function getTransparentStatus(string $id): array
    {
        if (trim($id) === '') {
            throw new RuntimeException('Identificador da cobrança ausente.');
        }

        return $this->request('GET', '/transparents/check?id=' . rawurlencode($id));
    }

    private function request(string $method, string $path, ?array $payload = null): array
    {
        $handle = curl_init($this->baseUrl . $path);

        if ($handle === false) {
            throw new RuntimeException('Não foi possível inicializar a conexão com a AbacatePay.');
        }

        $headers = [
            'Authorization: Bearer ' . $this->apiKey,
            'Content-Type: application/json',
            'Accept: application/json',
        ];

        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_FOLLOWLOCATION => false,
        ];

        if ($payload !== null) {
            try {
                $options[CURLOPT_POSTFIELDS] = json_encode(
                    $payload,
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
                );
            } catch (JsonException $exception) {
                curl_close($handle);
                throw new RuntimeException('Não foi possível preparar a cobrança para envio.', 0, $exception);
            }
        }

        curl_setopt_array($handle, $options);
        $body = curl_exec($handle);
        $curlError = curl_error($handle);
        $httpStatus = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        curl_close($handle);

        if ($body === false) {
            throw new RuntimeException('Falha de conexão com a AbacatePay: ' . $curlError);
        }

        $decoded = json_decode($body, true);

        if (!is_array($decoded)) {
            throw new RuntimeException('A AbacatePay retornou uma resposta inválida.');
        }

        $success = $decoded['success'] ?? true;

        if ($httpStatus < 200 || $httpStatus >= 300 || $success === false) {
            $error = $decoded['error'] ?? 'Não foi possível processar a cobrança.';

            if (is_array($error)) {
                $error = $error['message'] ?? $error['code'] ?? 'Erro desconhecido da API.';
            }

            throw new RuntimeException(
                'AbacatePay (' . $httpStatus . '): ' . mb_substr((string) $error, 0, 300),
            );
        }

        if (!isset($decoded['data']) || !is_array($decoded['data'])) {
            throw new RuntimeException('Resposta da AbacatePay sem o campo data esperado.');
        }

        return $decoded['data'];
    }
}
