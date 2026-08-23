<?php

declare(strict_types=1);

/**
 * callback/iugu.php - webhook (gatilho) da Iugu.
 *
 * CAMINHO NO WHMCS: /modules/gateways/callback/iugu.php
 * ENDEREÇO:         https://SEU-WHMCS/modules/gateways/callback/iugu.php
 *
 * É o endereço que você cadastra no painel da Iugu em Configurações ▸ Gatilhos,
 * para o evento `invoice.status_changed`. O módulo já monta esse endereço
 * sozinho e o envia em notification_url a cada cobrança criada.
 *
 * COMO A BAIXA É PROTEGIDA
 * A Iugu não assina os gatilhos dela (a documentação só informa o IP de saída).
 * Por isso este arquivo NUNCA acredita no que o POST diz: ele pega apenas o ID
 * da fatura e vai PERGUNTAR à API da Iugu qual é o status real. Só dá baixa se
 * a própria Iugu responder "paid". Um POST forjado não consegue quitar fatura.
 *
 * Não há senha nem assinatura a conferir: a Iugu não oferece nenhuma das duas,
 * e nenhum módulo de mercado inventa uma. A reconsulta na API é a proteção, e
 * é suficiente - quem forjar um POST só consegue fazer o módulo perguntar à
 * Iugu e receber "não pago".
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

ini_set('display_errors', '0');

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';
require_once __DIR__ . '/../iugu.php';

use WHMCS\Database\Capsule;

header('Content-Type: text/plain; charset=utf-8');

$gateway = getGatewayVariables('iugu');
$debug   = ($gateway['debug_log'] ?? '') === 'on';

/** Encerra devolvendo um texto curto. A Iugu só olha o código HTTP. */
function iugu_wh_end(int $http, string $texto): never
{
    http_response_code($http);
    exit($texto);
}

if (empty($gateway['type'])) {
    iugu_wh_end(503, 'gateway inativo');
}

// ═══════════════════════════════════════════════════════════════════════════
// 1. Payload
// ═══════════════════════════════════════════════════════════════════════════

// A Iugu envia application/x-www-form-urlencoded, então $_POST resolve. O
// bloco JSON é tolerância para reenvios feitos com outra ferramenta.
$event = (string) ($_POST['event'] ?? '');
$data  = (array) ($_POST['data'] ?? []);

if ($event === '') {
    $json = json_decode((string) file_get_contents('php://input'), true);
    if (is_array($json)) {
        $event = (string) ($json['event'] ?? '');
        $data  = (array) ($json['data'] ?? []);
    }
}

// LGPD: só o nome do evento e o ID vão para o log. Nunca o corpo inteiro,
// que carrega nome, e-mail e documento do pagador.
iugu_log($debug, 'webhook recebido', ['event' => $event, 'id' => (string) ($data['id'] ?? '')]);

if ($event !== 'invoice.status_changed') {
    // Responder 200 é proposital: a Iugu para de reenviar um evento que este
    // módulo não trata. Não registramos em logTransaction para não encher o
    // log do WHMCS com o payload de eventos que ignoramos.
    iugu_wh_end(200, 'ignorado');
}

$iuguInvoiceId = trim((string) ($data['id'] ?? ''));

if ($iuguInvoiceId === '') {
    iugu_wh_end(400, 'sem id');
}

// ═══════════════════════════════════════════════════════════════════════════
// 2. Pergunta à Iugu qual é o status de verdade
// ═══════════════════════════════════════════════════════════════════════════

try {
    $client = iugu_make_client($gateway);
    $res    = $client->getInvoice($iuguInvoiceId);
} catch (\Throwable $e) {
    iugu_log($debug, 'webhook: falha ao consultar a Iugu', $e->getMessage());
    iugu_wh_end(500, 'erro');
}

if (empty($res['ok'])) {
    iugu_log($debug, 'webhook: consulta recusada', (string) $res['error']);
    // 500 faz a Iugu tentar de novo mais tarde, que é o que queremos quando o
    // problema é nosso ou momentâneo.
    iugu_wh_end(500, 'erro');
}

$body   = (array) ($res['body'] ?? []);
$status = IuguHelpers::normalizeStatus((string) ($body['status'] ?? ''));

if ($status !== 'paid') {
    // Cancelou, expirou, entrou em análise: atualiza a linha e encerra.
    IuguCharges::setStatusByIuguId($iuguInvoiceId, $status === 'unknown' ? 'pending' : $status);
    iugu_log($debug, 'webhook: nao pago', ['status' => $status]);
    iugu_wh_end(200, 'ok');
}

// ═══════════════════════════════════════════════════════════════════════════
// 3. Descobre de qual fatura do WHMCS se trata
// ═══════════════════════════════════════════════════════════════════════════

$whmcsInvoiceId = 0;

// a) order_id no formato whmcs-invoice-<id>-<metodo>-<aleatorio>
if (preg_match('/^whmcs-invoice-(\d+)/', (string) ($body['order_id'] ?? ''), $m)) {
    $whmcsInvoiceId = (int) $m[1];
}

// b) custom_variables - sobrevive mesmo se alguém editar o order_id na Iugu
if ($whmcsInvoiceId < 1 && !empty($body['custom_variables'])) {
    foreach ((array) $body['custom_variables'] as $cv) {
        if (($cv['name'] ?? '') === 'whmcs_invoice_id' && !empty($cv['value'])) {
            $whmcsInvoiceId = (int) $cv['value'];
            break;
        }
    }
}

// c) a nossa própria tabela
if ($whmcsInvoiceId < 1) {
    try {
        $whmcsInvoiceId = (int) (Capsule::table(IUGU_TABLE)
            ->where('iugu_invoice_id', $iuguInvoiceId)
            ->value('whmcs_invoice_id') ?? 0);
    } catch (\Throwable $e) {
        $whmcsInvoiceId = 0;
    }
}

if ($whmcsInvoiceId < 1) {
    // Cobrança criada fora do WHMCS (direto no painel da Iugu). Responder 200
    // evita reenvio infinito de algo que nunca vai casar.
    iugu_log($debug, 'webhook: cobranca sem fatura no WHMCS', $iuguInvoiceId);
    iugu_wh_end(200, 'sem vinculo');
}

// ═══════════════════════════════════════════════════════════════════════════
// 4. Baixa
// ═══════════════════════════════════════════════════════════════════════════

// checkCbInvoiceID confirma que a fatura existe e está em aberto;
// checkCbTransID barra a segunda baixa do mesmo pagamento (a Iugu reenvia o
// gatilho quando não recebe 200 na primeira tentativa).
$whmcsInvoiceId = checkCbInvoiceID($whmcsInvoiceId, $gateway['name']);
checkCbTransID($iuguInvoiceId);

$pagoCents  = (int) ($body['total_paid_cents'] ?? $body['total_cents'] ?? 0);
$taxasCents = (int) ($body['taxes_paid_cents'] ?? 0);

addInvoicePayment(
    $whmcsInvoiceId,
    $iuguInvoiceId,
    number_format($pagoCents / 100, 2, '.', ''),
    number_format($taxasCents / 100, 2, '.', ''),
    'iugu'
);

IuguCharges::setStatusByIuguId($iuguInvoiceId, 'paid', date('Y-m-d H:i:s'));

// Pagou por um meio: os outros da mesma fatura param de valer. Sem isso o
// cliente que já pagou o Pix ainda consegue pagar o boleto que está com ele.
try {
    IuguCharges::cancelOpen($client, $whmcsInvoiceId, $iuguInvoiceId);
} catch (\Throwable $e) {
    iugu_log($debug, 'webhook: falha ao cancelar cobrancas irmas', $e->getMessage());
}

logTransaction($gateway['name'], [
    'iugu_id' => $iuguInvoiceId,
    'fatura'  => $whmcsInvoiceId,
    'valor'   => number_format($pagoCents / 100, 2, '.', ''),
], 'Successful');

iugu_log($debug, 'webhook: baixa concluida', ['fatura' => $whmcsInvoiceId]);

iugu_wh_end(200, 'ok');
