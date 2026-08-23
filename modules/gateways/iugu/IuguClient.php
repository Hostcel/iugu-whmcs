<?php

declare(strict_types=1);

/**
 * IuguClient.php - cliente HTTP da API REST da Iugu.
 *
 * CAMINHO NO WHMCS: /modules/gateways/iugu/IuguClient.php
 *
 * Sem SDK e sem Composer: só cURL, que já vem em qualquer hospedagem que
 * roda WHMCS. Um arquivo a menos para dar conflito de dependência.
 *
 * Autenticação: HTTP Basic com o token de API na posição do usuário e a
 * senha vazia (é o que a Iugu documenta em dev.iugu.com/reference/autenticação).
 *
 * TODO método público devolve SEMPRE o mesmo formato de array, mesmo quando
 * dá erro. Quem chama nunca precisa de try/catch para tratar falha de rede
 * ou 4xx - só olha a chave 'ok'. Exceção só acontece em erro de programação.
 *
 * ---------------------------------------------------------------------------
 * Módulo Iugu para WHMCS - Pix, Boleto e Cartão
 * Autor: Edilson Souza - Hostcel - https://www.hostcel.com.br
 *
 * Distribuído sob a LICENÇA DE PROTEÇÃO A CÓDIGO ABERTO NÃO COMERCIALIZÁVEL,
 * redigida por Edilson Souza (Hostcel): o código é aberto para ler, auditar,
 * usar, modificar e redistribuir - SEMPRE DE GRAÇA. A VENDA É PROIBIDA,
 * inclusive de versão modificada. Amparo: Lei 9.610/1998 e Lei 9.609/1998.
 * Texto completo no arquivo LICENSE que acompanha o módulo.
 * ---------------------------------------------------------------------------
 */

if (!defined('WHMCS')) {
    die('Acesso direto nao permitido.');
}

final class IuguClient
{
    /** Base da API. A Iugu não tem host separado de sandbox: o que muda é o token. */
    private const BASE_URL = 'https://api.iugu.com/v1';

    /** Identificação neutra: nada de nome de empresa de quem escreveu o módulo. */
    private const USER_AGENT = 'WHMCS-Iugu-Gateway/2.0';

    /** Teto total da requisição. Acima disso o cliente na tela já desistiu. */
    private const TIMEOUT = 25;

    /** Teto só do handshake. Separado para distinguir "fora do ar" de "lento". */
    private const CONNECT_TIMEOUT = 10;

    public function __construct(
        private readonly string $apiToken,
        private readonly ?string $accountId = null,
        // Guardado só para documentar em que modo o cliente foi construído.
        // A Iugu não tem host de sandbox: o que muda é o token, e quem escolhe
        // o token é iugu_api_token(). Não há nada a fazer com isto aqui.
        private readonly bool $testMode = false,
    ) {
        if ($apiToken === '') {
            throw new InvalidArgumentException('Token de API da Iugu vazio.');
        }
    }

    // ══════════════════════════════════════════════════════════════════════
    // Faturas
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Cria uma fatura. É o que gera Pix e Boleto.
     * https://dev.iugu.com/reference/criar-fatura
     */
    public function createInvoice(array $payload): array
    {
        return $this->request('POST', '/invoices', $payload);
    }

    /**
     * Lê uma fatura pelo ID da Iugu.
     *
     * É a consulta que sustenta a segurança do webhook: nunca damos baixa
     * pelo que o POST diz, e sim pelo que esta consulta responde.
     */
    public function getInvoice(string $invoiceId): array
    {
        return $this->request('GET', '/invoices/' . urlencode($invoiceId));
    }

    /** Cancela uma fatura ainda não paga. */
    public function cancelInvoice(string $invoiceId): array
    {
        return $this->request('PUT', '/invoices/' . urlencode($invoiceId) . '/cancel');
    }

    /**
     * Estorna uma fatura paga.
     *
     * @param int|null $partialCents Estorno parcial em centavos; null = total.
     */
    public function refundInvoice(string $invoiceId, ?int $partialCents = null): array
    {
        $body = $partialCents !== null ? ['partial_value_refund_cents' => $partialCents] : [];
        return $this->request('POST', '/invoices/' . urlencode($invoiceId) . '/refund', $body);
    }

    // ══════════════════════════════════════════════════════════════════════
    // Cobrança direta (cartão)
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Cobra um cartão a partir do token gerado no navegador pela iugu.js.
     *
     * O número do cartão nunca passa pelo servidor de quem instala o módulo:
     * a iugu.js troca os dados por um token de uso único direto com a Iugu.
     * https://dev.iugu.com/reference/cobrancadireta
     */
    public function chargeWithToken(array $payload): array
    {
        return $this->request('POST', '/charge', $payload);
    }

    // ══════════════════════════════════════════════════════════════════════
    // Conta
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Pergunta à Iugu se a credencial ainda vale.
     *
     * Usado pelo link "testar" nas informações do módulo, na tela de
     * configuração do gateway. Existe porque credencial revogada é a falha
     * mais comum e a mais difícil de perceber: a fatura simplesmente para de
     * gerar Pix, sem nada explicar. Com este teste o admin lê "401
     * Unauthorized" na hora, em vez de caçar.
     *
     * Sem Account ID não dá para consultar a conta; aí uma listagem mínima de
     * faturas serve para ver se a credencial é aceita - é a chamada mais
     * barata com esse efeito.
     */
    public function healthCheck(): array
    {
        if ($this->accountId !== null && $this->accountId !== '') {
            return $this->request('GET', '/accounts/' . urlencode($this->accountId));
        }

        return $this->request('GET', '/invoices?limit=1');
    }

    // ══════════════════════════════════════════════════════════════════════
    // HTTP
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Executa a requisição e normaliza a resposta.
     *
     * @return array{ok:bool,status:int,body:array|null,error:string|null,raw:string}
     *   ok     - true só quando é 2xx E não veio bloco de erro no corpo.
     *   status - código HTTP; 0 quando nem chegou a falar com a Iugu.
     *   body   - JSON decodificado.
     *   error  - mensagem já legível, pronta para o log (não para o cliente).
     *   raw    - corpo cru; só para diagnóstico.
     */
    private function request(string $method, string $path, array $body = []): array
    {
        $url     = self::BASE_URL . $path;
        $payload = $body !== [] ? json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            // Verificação de certificado LIGADA. Nunca desligue para "resolver"
            // erro de SSL: isso abre a porta para interceptar a credencial.
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT      => self::USER_AGENT,
            CURLOPT_USERPWD        => $this->apiToken . ':',
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Accept: application/json',
            ],
        ]);

        if ($payload !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        }

        $raw    = curl_exec($ch);
        $errno  = curl_errno($ch);
        $errMsg = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // Falha de transporte: DNS, timeout, TLS. Não chegou na Iugu.
        if ($errno !== 0) {
            return [
                'ok'     => false,
                'status' => 0,
                'body'   => null,
                'error'  => 'Falha de conexão com a Iugu: ' . $errMsg,
                'raw'    => '',
            ];
        }

        $decoded = json_decode((string) $raw, true);

        if (!is_array($decoded) && $status >= 200 && $status < 300) {
            return [
                'ok'     => false,
                'status' => $status,
                'body'   => null,
                'error'  => 'A Iugu respondeu 2xx com um corpo que não é JSON.',
                'raw'    => (string) $raw,
            ];
        }

        // A Iugu às vezes devolve HTTP 200 com um bloco 'errors' no corpo.
        // Tratar só o código HTTP deixaria passar erro como se fosse sucesso.
        $apiError = self::extractApiError($decoded);

        if ($apiError !== null || $status >= 400) {
            return [
                'ok'     => false,
                'status' => $status,
                'body'   => is_array($decoded) ? $decoded : null,
                'error'  => $apiError ?? ('A Iugu respondeu HTTP ' . $status . '.'),
                'raw'    => (string) $raw,
            ];
        }

        return [
            'ok'     => true,
            'status' => $status,
            'body'   => $decoded,
            'error'  => null,
            'raw'    => (string) $raw,
        ];
    }

    /**
     * Extrai a mensagem de erro do corpo da Iugu.
     *
     * O formato varia conforme o endpoint:
     *   {"errors":"Fatura duplicada"}
     *   {"errors":{"payer.cpf_cnpj":["não é válido"]}}
     *   {"errors":[{"code":"...","message":"..."}]}
     * Este método aceita os três e devolve uma linha só.
     */
    private static function extractApiError(mixed $body): ?string
    {
        if (!is_array($body)) {
            return null;
        }
        if (empty($body['errors']) && empty($body['error'])) {
            return null;
        }

        $errors = $body['errors'] ?? $body['error'];

        if (is_string($errors)) {
            return $errors;
        }

        if (is_array($errors)) {
            $parts = [];
            foreach ($errors as $k => $v) {
                if (is_array($v)) {
                    $msg = isset($v['message'])
                        ? (string) $v['message']
                        : implode(', ', array_map('strval', $v));
                    $parts[] = is_string($k) ? ($k . ': ' . $msg) : $msg;
                } else {
                    $parts[] = is_string($k) ? ($k . ': ' . $v) : (string) $v;
                }
            }
            return implode(' | ', $parts);
        }

        return 'Erro não identificado devolvido pela Iugu.';
    }
}
