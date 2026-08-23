<?php

declare(strict_types=1);

/**
 * check_status.php - "essa fatura já foi paga?"
 *
 * CAMINHO NO WHMCS: /modules/gateways/iugu/check_status.php
 * ENDEREÇO:         https://SEU-WHMCS/modules/gateways/iugu/check_status.php?invoice_id=123
 *
 * A tela do QR Pix consulta este endereço a cada poucos segundos. Quando
 * responde paid=true, o navegador recarrega a página e o cliente vê a fatura
 * quitada sem precisar fazer nada.
 *
 * NÃO chama a Iugu. Lê só o status da fatura no WHMCS - que foi atualizado
 * pelo webhook (callback/iugu.php) ou pela conferência diária (reconcile.php).
 * Se este endpoint consultasse a Iugu, cada cliente com a tela aberta geraria
 * uma chamada de API a cada 6 segundos.
 *
 * Exige sessão e só responde sobre fatura do próprio cliente.
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

use WHMCS\Database\Capsule;
use WHMCS\Session;

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
// Resposta muda a cada segundo: nada de cache em proxy nem no navegador.
header('Cache-Control: no-store');

// Cliente logado, ou admin vendo pela tela do cliente. Os dois são válidos -
// ver a explicação em create_charge.php.
$uidSessao = (int) ($_SESSION['uid'] ?? (Session::get('uid') ?? 0));
$adminId   = (int) ($_SESSION['adminid'] ?? (Session::get('adminid') ?? 0));

$invoiceId = (int) ($_GET['invoice_id'] ?? 0);

// Sem sessão ou sem fatura: responde "não pago" e encerra. Não é erro - é o
// laço do navegador perguntando em hora ruim (sessão acabou de expirar).
if (($uidSessao < 1 && $adminId < 1) || $invoiceId < 1) {
    echo json_encode(['paid' => false]);
    exit;
}

try {
    $consulta = Capsule::table('tblinvoices')->where('id', $invoiceId);

    // Cliente só enxerga a própria fatura; admin enxerga qualquer uma.
    if ($uidSessao > 0) {
        $consulta->where('userid', $uidSessao);
    }

    $status = $consulta->value('status');
} catch (\Throwable $e) {
    echo json_encode(['paid' => false]);
    exit;
}

echo json_encode([
    'paid'   => $status === 'Paid',
    'status' => (string) ($status ?? ''),
]);
