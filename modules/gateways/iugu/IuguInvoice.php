<?php

declare(strict_types=1);

/**
 * IuguInvoice.php - montagem das cobranças.
 *
 * CAMINHO NO WHMCS: /modules/gateways/iugu/IuguInvoice.php
 *
 * Fica entre o WHMCS e o IuguClient: recebe dados já normalizados (payer,
 * items, datas) e devolve SEMPRE a mesma estrutura, independente de ser Pix,
 * Boleto ou Cartão. Quem chama - a área do cliente, os hooks, o cron de
 * conferência - não precisa conhecer o formato da Iugu.
 *
 * Formato de retorno de createPix, createBoleto e chargeCard:
 *   [
 *     'ok'         => bool,
 *     'iugu_id'    => string|null,  ID da fatura na Iugu
 *     'secure_url' => string|null,  página de pagamento hospedada pela Iugu
 *     'expires_at' => string|null,  'Y-m-d H:i:s' calculado aqui
 *     'pix'        => array|null,   ['qrcode' => base64, 'qrcode_text' => copia-e-cola]
 *     'bank_slip'  => array|null,   ['barcode','digitable_line','pdf_url']
 *     'card'       => array|null,   ['status','transaction_id','message']
 *     'error'      => string|null,  mensagem técnica; NÃO mostrar ao cliente
 *   ]
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

require_once __DIR__ . '/IuguClient.php';
require_once __DIR__ . '/IuguHelpers.php';

final class IuguInvoice
{
    public function __construct(
        private readonly IuguClient $client,
    ) {}

    // ══════════════════════════════════════════════════════════════════════
    // Pix
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Cria uma fatura pagável só por Pix e devolve o QR e o copia-e-cola.
     *
     * @param array $args {
     *   whmcs_invoice_id: int,
     *   email: string, payer: array, items: array,
     *   due_date: string 'Y-m-d',
     *   expires_in: string '1d'|'2d'|'3d'|'7d'|'15d'|'30d',
     *   late_fee_pct: float, monthly_interest_pct: float,
     *   notification_url: string, return_url: string, ignore_email: bool,
     *   order_id?: string
     * }
     */
    public function createPix(array $args): array
    {
        $preset  = (string) ($args['expires_in'] ?? '1d');
        $payload = $this->commonInvoicePayload($args, 'pix');

        $payload['payable_with'] = 'pix';

        // expires_in da API /v1/invoices é contado em DIAS (não em horas).
        $payload['expires_in'] = IuguHelpers::expiresInDays($preset);

        $res = $this->client->createInvoice($payload);

        return $this->normalizeInvoiceResponse($res, 'pix', IuguHelpers::expiresAt($preset));
    }

    // ══════════════════════════════════════════════════════════════════════
    // Boleto
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Cria uma fatura pagável só por boleto bancário.
     *
     * Não mandamos expires_in e não guardamos data de validade: quem decide
     * até quando o boleto é aceito é a conta Iugu do lojista, e não há como o
     * módulo saber esse número. Guardar um prazo chutado só serviria para
     * reemitir o boleto cedo demais - entregando um segundo boleto ao cliente
     * enquanto o primeiro ainda é pagável.
     *
     * Sem validade local, o boleto é reaproveitado enquanto a linha estiver
     * pendente. Quando a Iugu o expira ou cancela, o webhook ou a conferência
     * diária mudam o status, e aí sim o módulo emite outro.
     */
    public function createBoleto(array $args): array
    {
        $payload = $this->commonInvoicePayload($args, 'boleto');
        $payload['payable_with'] = 'bank_slip';

        $res = $this->client->createInvoice($payload);

        return $this->normalizeInvoiceResponse($res, 'bank_slip', null);
    }

    // ══════════════════════════════════════════════════════════════════════
    // Cartão
    // ══════════════════════════════════════════════════════════════════════

    /**
     * Cobra um cartão com o token gerado pela iugu.js no navegador do cliente.
     *
     * ATENÇÃO: um retorno com ok=true NÃO significa dinheiro na conta. A Iugu
     * pode devolver success e colocar a fatura em análise antifraude. Quem
     * chama deve consultar o status real com getInvoice antes de dar baixa -
     * é o que create_charge.php faz.
     *
     * @param array $args { token, email, payer, items, months, whmcs_invoice_id, notification_url }
     */
    public function chargeCard(array $args): array
    {
        $payload = IuguHelpers::pruneEmpty([
            'token'    => (string) $args['token'],
            'email'    => (string) $args['email'],
            'months'   => max(1, (int) ($args['months'] ?? 1)),
            'items'    => $args['items'],
            'payer'    => $args['payer'],
            'order_id' => $this->orderId($args, 'card'),
            'notification_url' => (string) ($args['notification_url'] ?? ''),
            'custom_variables' => [
                ['name' => 'whmcs_invoice_id', 'value' => (string) $args['whmcs_invoice_id']],
            ],
        ]);

        return $this->normalizeCardResponse($this->client->chargeWithToken($payload));
    }

    // ══════════════════════════════════════════════════════════════════════
    // Internos
    // ══════════════════════════════════════════════════════════════════════

    /**
     * order_id enviado à Iugu.
     *
     * Precisa ser ÚNICO: a Iugu recusa com "Fatura duplicada" se repetir. Por
     * isso leva um sufixo aleatório. O webhook reconhece a fatura do WHMCS
     * pelo prefixo `whmcs-invoice-<id>`, então o sufixo não atrapalha a baixa.
     */
    private function orderId(array $args, string $method): string
    {
        if (!empty($args['order_id'])) {
            return (string) $args['order_id'];
        }

        return 'whmcs-invoice-' . (int) $args['whmcs_invoice_id']
            . '-' . $method
            . '-' . bin2hex(random_bytes(3));
    }

    /**
     * Campos comuns de /v1/invoices (Pix e Boleto).
     *
     * custom_variables guarda o ID da fatura do WHMCS: é o vínculo que
     * sobrevive mesmo se o order_id for alterado no painel da Iugu.
     */
    private function commonInvoicePayload(array $args, string $method): array
    {
        $lateFee  = (float) ($args['late_fee_pct'] ?? 0);
        $interest = (float) ($args['monthly_interest_pct'] ?? 0);

        return IuguHelpers::pruneEmpty([
            'email'    => (string) $args['email'],
            'due_date' => (string) $args['due_date'],
            'items'    => $args['items'],
            'payer'    => $args['payer'],
            'order_id' => $this->orderId($args, $method),

            'notification_url' => (string) ($args['notification_url'] ?? ''),
            'return_url'       => (string) ($args['return_url'] ?? ''),

            // true = a Iugu não manda e-mail de cobrança; quem avisa é o WHMCS.
            'ignore_due_email' => (bool) ($args['ignore_email'] ?? false),

            // Multa fixa e juros por dia só existem se o admin configurou > 0.
            'fines'             => $lateFee > 0,
            'late_payment_fine' => $lateFee > 0 ? $lateFee : null,
            'per_day_interest'  => $interest > 0,

            'custom_variables' => [
                ['name' => 'whmcs_invoice_id', 'value' => (string) $args['whmcs_invoice_id']],
            ],
        ]);
    }

    /**
     * Traduz a resposta de criação de fatura (Pix ou Boleto).
     *
     * @param string|null $expiresAt Validade calculada AQUI, não lida da Iugu; null
     *   quando não existe prazo local (boleto). A
     *   versão anterior gravava o end_to_end_id do Pix nesse campo - um
     *   identificador de PSP num campo de data, que não servia para nada.
     */
    private function normalizeInvoiceResponse(array $res, string $method, ?string $expiresAt): array
    {
        if (!$res['ok']) {
            return ['ok' => false, 'error' => $res['error'] ?? 'Erro não identificado.'];
        }

        $body = $res['body'] ?? [];

        $out = [
            'ok'         => true,
            'iugu_id'    => isset($body['id']) ? (string) $body['id'] : null,
            'secure_url' => isset($body['secure_url']) ? (string) $body['secure_url'] : null,
            'expires_at' => $expiresAt,
            'pix'        => null,
            'bank_slip'  => null,
            'card'       => null,
            'error'      => null,
        ];

        if ($method === 'pix') {
            if (empty($body['pix']['qrcode_text'])) {
                // Fatura criada mas sem Pix: acontece quando a conta Iugu não
                // tem Pix habilitado. Melhor falhar claro do que entregar uma
                // tela de QR vazia para o cliente.
                return ['ok' => false, 'error' => 'A conta Iugu criou a fatura sem Pix. Habilite Pix no painel da Iugu.'];
            }
            $out['pix'] = [
                'qrcode'      => (string) ($body['pix']['qrcode'] ?? ''),
                'qrcode_text' => (string) $body['pix']['qrcode_text'],
            ];
        }

        if ($method === 'bank_slip') {
            $slip   = $body['bank_slip'] ?? [];
            $secure = (string) ($body['secure_url'] ?? '');

            if (empty($slip['digitable_line']) && empty($slip['barcode'])) {
                return ['ok' => false, 'error' => 'A conta Iugu criou a fatura sem boleto. Habilite boleto no painel da Iugu.'];
            }

            $out['bank_slip'] = [
                'barcode'        => (string) ($slip['barcode'] ?? ''),
                'digitable_line' => ((string) ($slip['digitable_line'] ?? '')) ?: ((string) ($slip['barcode'] ?? '')),
                // O PDF é a própria página segura com .pdf no fim - padrão da Iugu.
                'pdf_url'        => $secure !== '' ? $secure . '.pdf' : '',
                'barcode_url'    => self::barcodeUrl($secure),
            ];
        }

        return $out;
    }

    /**
     * Imagem do código de barras do boleto, para o cliente ler pelo celular.
     *
     * A Iugu não devolve essa URL no corpo da resposta: ela se obtém trocando
     * o host e o final da própria secure_url -
     *   https://boletos.iugu.com/v1/public/invoice/<hash>/bank_slip   (página)
     *   https://api.iugu.com/v1/public/invoice/<hash>/barcode         (imagem)
     *
     * Se a secure_url vier em outro formato, devolve vazio e a tela
     * simplesmente não mostra a imagem - nunca um endereço chutado.
     */
    public static function barcodeUrl(string $secureUrl): string
    {
        if ($secureUrl === '' || !str_contains($secureUrl, '/bank_slip')) {
            return '';
        }

        return str_replace(
            ['//boletos.iugu.com', '/bank_slip'],
            ['//api.iugu.com', '/barcode'],
            $secureUrl
        );
    }

    /** Traduz a resposta de /charge (cartão). */
    private function normalizeCardResponse(array $res): array
    {
        if (!$res['ok']) {
            return ['ok' => false, 'error' => $res['error'] ?? 'Erro ao processar o cartão.'];
        }

        $body    = $res['body'] ?? [];
        $success = (bool) ($body['success'] ?? false);

        return [
            'ok'         => $success,
            'iugu_id'    => isset($body['invoice_id']) ? (string) $body['invoice_id'] : null,
            'secure_url' => isset($body['url']) ? (string) $body['url'] : null,
            'expires_at' => null,
            'pix'        => null,
            'bank_slip'  => null,
            'card'       => [
                // 'paid' aqui é otimista de propósito: quem chama confirma com
                // getInvoice. Ver o aviso em chargeCard().
                'status'         => $success ? 'paid' : (string) ($body['status'] ?? 'failed'),
                'transaction_id' => (string) ($body['identification'] ?? $body['invoice_id'] ?? ''),
                'message'        => (string) ($body['message'] ?? ''),
                // LR = código de recusa da adquirente, útil no log.
                'lr'             => (string) ($body['LR'] ?? ''),
            ],
            'error'      => $success ? null : (string) ($body['message'] ?? 'Cartão recusado.'),
        ];
    }
}
