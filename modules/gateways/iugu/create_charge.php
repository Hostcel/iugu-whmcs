<?php

declare(strict_types=1);

/**
 * create_charge.php - endpoint chamado pela tela da fatura do cliente.
 *
 * CAMINHO NO WHMCS: /modules/gateways/iugu/create_charge.php
 * ENDEREÇO:         https://SEU-WHMCS/modules/gateways/iugu/create_charge.php
 *
 * Recebe POST com action=pix|boleto|card e devolve JSON. É o ÚNICO lugar que
 * emite cobrança a pedido do cliente.
 *
 * Três travas, nesta ordem:
 *   1. Sessão de cliente logado (não existe emissão anônima).
 *   2. Token assinado, conferido com hash_equals - impede que outro site
 *      force o navegador do cliente logado a emitir cobranças (CSRF).
 *   3. A fatura tem que ser DO cliente da sessão e estar em aberto.
 *
 * O erro devolvido ao navegador é sempre genérico. O motivo real vai para o
 * log de diagnóstico do servidor: mensagem de exceção na tela do cliente
 * expõe caminho de arquivo e estrutura do banco.
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

// Nada de erro de PHP impresso no corpo: a resposta é JSON e quem lê é o
// navegador do cliente.
ini_set('display_errors', '0');

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';
require_once __DIR__ . '/../iugu.php';

use WHMCS\Database\Capsule;
use WHMCS\Session;

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

/** Estado do log; preenchido assim que a configuração é lida. */
$IUGU_DEBUG = false;

/** Encerra com JSON. A mensagem já tem que estar pronta para o cliente ler. */
function iugu_out(int $http, array $payload): never
{
    http_response_code($http);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Encerra com erro.
 *
 * @param string $publico  O que o cliente vê.
 * @param string $tecnico  O que vai para o log; nunca é enviado ao navegador.
 */
function iugu_erro(int $http, string $publico, string $tecnico = ''): never
{
    global $IUGU_DEBUG;
    iugu_log($IUGU_DEBUG, 'create_charge erro ' . $http, $tecnico !== '' ? $tecnico : $publico);
    iugu_out($http, ['ok' => false, 'error' => $publico]);
}

// Qualquer coisa não prevista vira erro genérico, com o detalhe só no log.
set_exception_handler(static function (\Throwable $e): void {
    global $IUGU_DEBUG;
    iugu_log($IUGU_DEBUG, 'create_charge EXCECAO', [
        'msg'  => $e->getMessage(),
        'file' => basename($e->getFile()),
        'line' => $e->getLine(),
    ]);
    if (!headers_sent()) {
        http_response_code(500);
    }
    echo json_encode(['ok' => false, 'error' => 'Não foi possível concluir agora. Tente novamente em instantes.']);
});

// ═══════════════════════════════════════════════════════════════════════════
// 1. Método e configuração
// ═══════════════════════════════════════════════════════════════════════════

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    iugu_erro(405, 'Requisição inválida.');
}

$gateway    = getGatewayVariables('iugu');
$IUGU_DEBUG = ($gateway['debug_log'] ?? '') === 'on';

if (empty($gateway['type'])) {
    iugu_erro(503, 'Meio de pagamento indisponível no momento.', 'gateway iugu inativo');
}

// ═══════════════════════════════════════════════════════════════════════════
// 2. Sessão, token e fatura
// ═══════════════════════════════════════════════════════════════════════════

/**
 * Quem está do outro lado pode ser de dois tipos, e os dois são legítimos:
 *
 *   - o CLIENTE logado na área do cliente  → $_SESSION['uid']
 *   - um ADMIN vendo a fatura pela tela do cliente ("view as client",
 *     do tema Lagom) → $_SESSION['adminid'], e SEM uid nenhum
 *
 * É o mesmo par de chaves que o WHMCS usa e que os outros gateways
 * consultam. Olhar só o uid deixaria o admin de fora.
 */
$uidSessao = (int) ($_SESSION['uid'] ?? (Session::get('uid') ?? 0));
$adminId   = (int) ($_SESSION['adminid'] ?? (Session::get('adminid') ?? 0));

$invoiceId = (int) ($_POST['invoice_id'] ?? 0);
$action    = (string) ($_POST['action'] ?? '');
$given     = (string) ($_POST['token'] ?? '');

if ($uidSessao < 1 && $adminId < 1) {
    iugu_erro(401, 'Sua sessão expirou. Entre novamente e recarregue a página.');
}
if ($invoiceId < 1 || !in_array($action, ['pix', 'boleto', 'card'], true)) {
    iugu_erro(400, 'Requisição inválida.');
}

$inv = Capsule::table('tblinvoices')->where('id', $invoiceId)->first();

if (!$inv) {
    iugu_erro(404, 'Fatura não encontrada.');
}

// O dono da fatura é quem manda: é para ele que a cobrança é emitida, e é com
// o id dele que a tela assinou o token.
$clientId = (int) $inv->userid;

// Cliente logado só mexe na própria fatura. Admin pode em qualquer uma.
if ($uidSessao > 0 && $uidSessao !== $clientId) {
    iugu_erro(404, 'Fatura não encontrada.',
        'fatura ' . $invoiceId . ' nao pertence ao cliente ' . $uidSessao);
}

$apiToken = iugu_api_token($gateway);

if (!iugu_check_form_token($given, $invoiceId, $clientId, $apiToken)) {
    iugu_erro(403, 'Página desatualizada. Recarregue e tente de novo.', 'token invalido');
}

if (!in_array($action, iugu_enabled_methods($gateway), true)) {
    iugu_erro(400, 'Esta forma de pagamento não está disponível.');
}

if ($inv->status === 'Paid') {
    iugu_out(200, ['ok' => true, 'paid' => true]);
}
if (in_array($inv->status, ['Cancelled', 'Refunded', 'Draft'], true)) {
    iugu_erro(400, 'Esta fatura não está em aberto.');
}

// ═══════════════════════════════════════════════════════════════════════════
// 3. Reaproveita a cobrança que ainda vale
// ═══════════════════════════════════════════════════════════════════════════

// Isso é mais do que economia de chamada de API: reemitir trocaria o
// copia-e-cola que o cliente já pode ter recebido por e-mail ou WhatsApp.
//
// ⚠ Mas só vale reaproveitar se o VALOR ainda bate. O hook UpdateInvoiceTotal
// reemite quando o admin altera a fatura - só que ele depende da API da Iugu
// responder naquele instante. Se ela estiver fora, a cobrança antiga fica no
// banco com o valor velho, e sem esta conferência o cliente pagaria o valor
// errado achando que quitou a fatura.
$totalCents = IuguHelpers::toCents((float) $inv->total);

/** A cobrança guardada ainda corresponde ao valor de hoje? */
$valorConfere = static function ($linha) use ($totalCents): bool {
    return $linha && (int) $linha->amount_cents === $totalCents;
};

if ($action === 'pix') {
    $c = IuguCharges::openOf($invoiceId, 'pix');
    if ($valorConfere($c) && !empty($c->qrcode_text)) {
        iugu_out(200, ['ok' => true, 'cached' => true, 'pix' => [
            'qrcode'      => (string) $c->qrcode_base64,
            'qrcode_text' => (string) $c->qrcode_text,
            'expires_at'  => (string) $c->expires_at,
        ]]);
    }
}

if ($action === 'boleto') {
    $c = IuguCharges::openOf($invoiceId, 'boleto');
    if ($valorConfere($c) && !empty($c->bank_slip_barcode)) {
        iugu_out(200, ['ok' => true, 'cached' => true, 'boleto' => [
            'digitable_line' => (string) $c->bank_slip_barcode,
            'pdf_url'        => (string) $c->bank_slip_pdf,
            // Derivada da secure_url guardada; vazia se o formato mudar.
            'barcode_url'    => IuguInvoice::barcodeUrl((string) $c->secure_url),
        ]]);
    }
}

// ═══════════════════════════════════════════════════════════════════════════
// 4. Dados da cobrança
// ═══════════════════════════════════════════════════════════════════════════

$built = iugu_build_charge_args($gateway, $invoiceId);

if (!$built['ok']) {
    iugu_erro(400, (string) $built['error']);
}

// Boleto e cartão exigem documento válido; a Iugu recusa sem ele.
if (in_array($action, ['boleto', 'card'], true) && !$built['cpf_ok']) {
    iugu_erro(400, 'Seu CPF ou CNPJ não está preenchido corretamente no cadastro. Atualize e tente de novo.');
}

$client  = iugu_make_client($gateway);
$service = new IuguInvoice($client);
$args    = $built['args'];
$link    = iugu_pay_link($gateway, $invoiceId);

// Chegou aqui e existe cobrança aberta deste método? Então ela é de outro
// valor (o cache acima já teria devolvido, se batesse). Cancelar na Iugu antes
// de emitir a nova - senão ficam duas cobranças válidas para a mesma fatura e
// o cliente pode pagar a errada.
if (in_array($action, ['pix', 'boleto'], true)) {
    $antiga = IuguCharges::openOf($invoiceId, $action);

    if ($antiga && !empty($antiga->iugu_invoice_id)) {
        try {
            $client->cancelInvoice((string) $antiga->iugu_invoice_id);
        } catch (\Throwable $e) {
            iugu_log($IUGU_DEBUG, 'falha ao cancelar cobranca de valor antigo', $e->getMessage());
        }
        IuguCharges::setStatusById((int) $antiga->id, 'canceled');
        iugu_log($IUGU_DEBUG, 'cobranca de valor antigo cancelada', [
            'fatura' => $invoiceId, 'metodo' => $action,
            'era'    => (int) $antiga->amount_cents, 'agora' => $totalCents,
        ]);
    }
}

// ═══════════════════════════════════════════════════════════════════════════
// 5. Emissão
// ═══════════════════════════════════════════════════════════════════════════

if ($action === 'pix') {
    $res = $service->createPix($args + ['expires_in' => (string) ($gateway['pix_expires_in'] ?? '3d')]);

    if (!$res['ok']) {
        iugu_erro(502, 'Não foi possível gerar o Pix agora. Tente novamente em instantes.', (string) $res['error']);
    }

    IuguCharges::save($invoiceId, $clientId, 'pix', [
        'iugu_id'       => $res['iugu_id'],
        'status'        => 'pending',
        'qrcode_base64' => $res['pix']['qrcode'],
        'qrcode_text'   => $res['pix']['qrcode_text'],
        'secure_url'    => $link !== '' ? $link : $res['secure_url'],
        'expires_at'    => $res['expires_at'],
        'amount_cents'  => $built['amount_cents'],
    ]);

    logTransaction('iugu', ['acao' => 'pix_gerado', 'fatura' => $invoiceId, 'iugu_id' => $res['iugu_id']], 'Successful');

    iugu_out(200, ['ok' => true, 'pix' => [
        'qrcode'      => $res['pix']['qrcode'],
        'qrcode_text' => $res['pix']['qrcode_text'],
        'expires_at'  => $res['expires_at'],
    ]]);
}

if ($action === 'boleto') {
    $res = $service->createBoleto($args);

    if (!$res['ok']) {
        iugu_erro(502, 'Não foi possível gerar o boleto agora. Tente novamente em instantes.', (string) $res['error']);
    }

    IuguCharges::save($invoiceId, $clientId, 'boleto', [
        'iugu_id'           => $res['iugu_id'],
        'status'            => 'pending',
        'bank_slip_barcode' => $res['bank_slip']['digitable_line'],
        'bank_slip_pdf'     => $res['bank_slip']['pdf_url'],
        'secure_url'        => $res['secure_url'],
        'expires_at'        => $res['expires_at'],
        'amount_cents'      => $built['amount_cents'],
    ]);

    logTransaction('iugu', ['acao' => 'boleto_gerado', 'fatura' => $invoiceId, 'iugu_id' => $res['iugu_id']], 'Successful');

    iugu_out(200, ['ok' => true, 'boleto' => [
        'digitable_line' => $res['bank_slip']['digitable_line'],
        'pdf_url'        => $res['bank_slip']['pdf_url'],
        'barcode_url'    => $res['bank_slip']['barcode_url'],
    ]]);
}

// ─────────────────────────────── Cartão ───────────────────────────────────

$cardToken = trim((string) ($_POST['card_token'] ?? ''));
$months    = max(1, min((int) ($gateway['card_max_installments'] ?? 1), (int) ($_POST['months'] ?? 1)));

if ($cardToken === '') {
    iugu_erro(400, 'Confira os dados do cartão e tente novamente.', 'token de cartao ausente');
}

$res = $service->chargeCard([
    'token'            => $cardToken,
    'email'            => $args['email'],
    'payer'            => $args['payer'],
    'items'            => $args['items'],
    'months'           => $months,
    'whmcs_invoice_id' => $invoiceId,
    'notification_url' => $args['notification_url'],
]);

if (!$res['ok']) {
    // Aqui a mensagem da Iugu É útil para o cliente ("cartão sem saldo",
    // "dados inválidos") e não expõe nada da instalação.
    iugu_erro(402, (string) ($res['error'] ?? 'Cartão recusado.'), 'recusa no /charge');
}

// "success" no /charge não quer dizer pago: o antifraude pode estar analisando.
// O status que vale é o da fatura na Iugu.
$iuguId     = (string) ($res['iugu_id'] ?? '');
$realStatus = 'pending';

if ($iuguId !== '') {
    $check = $client->getInvoice($iuguId);
    if (!empty($check['ok'])) {
        $realStatus = IuguHelpers::normalizeStatus((string) ($check['body']['status'] ?? 'pending'));
    }
}

IuguCharges::save($invoiceId, $clientId, 'card', [
    'iugu_id'        => $iuguId,
    'iugu_charge_id' => $res['card']['transaction_id'] ?? null,
    'status'         => in_array($realStatus, ['paid', 'in_analysis', 'pending'], true) ? $realStatus : 'pending',
    'amount_cents'   => $built['amount_cents'],
    'paid_at'        => $realStatus === 'paid' ? date('Y-m-d H:i:s') : null,
]);

if ($realStatus !== 'paid') {
    iugu_log($IUGU_DEBUG, 'cartao em analise', ['fatura' => $invoiceId, 'status' => $realStatus]);

    iugu_out(200, [
        'ok'      => true,
        'paid'    => false,
        'message' => 'Pagamento em análise pela operadora. Assim que for aprovado, a fatura é baixada '
                   . 'automaticamente e esta tela atualiza sozinha.',
    ]);
}

// Pago de verdade: dá a baixa agora. O transid é o ID da fatura na Iugu, o
// MESMO que o webhook usaria - assim o WHMCS reconhece a duplicidade e não
// lança o pagamento duas vezes se o webhook chegar depois.
try {
    addInvoicePayment(
        $invoiceId,
        $iuguId !== '' ? $iuguId : ('iugu-card-' . $invoiceId),
        number_format((float) $inv->total, 2, '.', ''),
        '0.00',
        'iugu'
    );
} catch (\Throwable $e) {
    iugu_log($IUGU_DEBUG, 'falha na baixa do cartao', $e->getMessage());
}

// Pagou no cartão: o Pix e o boleto da mesma fatura param de valer.
IuguCharges::cancelOpen($client, $invoiceId, $iuguId);

logTransaction('iugu', ['acao' => 'cartao_aprovado', 'fatura' => $invoiceId, 'iugu_id' => $iuguId], 'Successful');

iugu_out(200, ['ok' => true, 'paid' => true]);
