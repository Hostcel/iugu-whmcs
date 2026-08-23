<?php

declare(strict_types=1);

/**
 * reconcile.php - conferência das cobranças pendentes.
 *
 * CAMINHO NO WHMCS: /modules/gateways/iugu/reconcile.php
 *
 * Rede falha. O webhook pode não chegar: o servidor estava fora do ar naquele
 * minuto, um firewall barrou, a Iugu desistiu depois das tentativas dela. O
 * resultado é o pior possível - cliente pagou e a fatura continua em aberto,
 * com risco de suspensão do serviço.
 *
 * Esta rotina fecha esse buraco: pega as cobranças que o módulo ainda tem como
 * pendentes, pergunta à Iugu o status de cada uma e baixa as que foram pagas.
 * É a mesma verificação do webhook, só que puxada por nós.
 *
 * QUEM CHAMA: o hook DailyCronJob (hooks.php), uma vez por dia, quando a
 * "Conferência diária" está ligada na configuração do gateway. Também pode ser
 * rodado à mão pela linha de comando:
 *
 *     php -f /caminho/do/whmcs/modules/gateways/iugu/reconcile.php
 *
 * NÃO é acessível pelo navegador: sem sessão de linha de comando o arquivo
 * encerra sem fazer nada. Uma rotina que dá baixa em fatura não pode ficar
 * disponível por URL.
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

/**
 * Este pagamento já foi lançado no WHMCS?
 *
 * Substitui checkCbTransID() na conferência diária. Faz a mesma pergunta -
 * "existe transação com este número?" - só que devolvendo true/false em vez
 * de encerrar o processo.
 *
 * tblaccounts é a tabela de transações do WHMCS, e `transid` é o número que
 * gravamos na baixa (o ID da fatura na Iugu).
 */
function iugu_pagamento_ja_lancado(string $transId): bool
{
    if ($transId === '') {
        return false;
    }

    try {
        return \WHMCS\Database\Capsule::table('tblaccounts')
            ->where('transid', $transId)
            ->exists();
    } catch (\Throwable $e) {
        // Na dúvida, diz que já foi lançado: deixar de baixar uma fatura é
        // recuperável na rodada seguinte; baixar duas vezes, não.
        return true;
    }
}

/**
 * Confere as cobranças pendentes e baixa as pagas.
 *
 * @param int $limite Teto de cobranças por execução, para não estourar o
 *                    tempo do cron em base grande. As restantes ficam para a
 *                    próxima rodada.
 * @return array{checadas:int,baixadas:int} Resumo, útil no log.
 */
function iugu_reconcile(int $limite = 200): array
{
    if (!function_exists('getGatewayVariables')) {
        require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
    }
    if (!function_exists('addInvoicePayment')) {
        require_once __DIR__ . '/../../../includes/invoicefunctions.php';
    }
    require_once __DIR__ . '/../iugu.php';

    $gateway = getGatewayVariables('iugu');

    if (empty($gateway['type'])) {
        return ['checadas' => 0, 'baixadas' => 0];
    }

    $debug = ($gateway['debug_log'] ?? '') === 'on';

    try {
        $client = iugu_make_client($gateway);
    } catch (\Throwable $e) {
        // Sem credencial não há o que conferir; não é motivo para alarme.
        iugu_log($debug, 'reconcile: gateway sem credencial', $e->getMessage());
        return ['checadas' => 0, 'baixadas' => 0];
    }

    $pendentes = IuguCharges::pendingForReconcile($limite);
    $baixadas  = 0;

    foreach ($pendentes as $linha) {
        $iuguId    = (string) $linha->iugu_invoice_id;
        $invoiceId = (int) $linha->whmcs_invoice_id;

        try {
            $res = $client->getInvoice($iuguId);
        } catch (\Throwable $e) {
            continue; // problema momentâneo: tenta de novo amanhã
        }

        if (empty($res['ok'])) {
            continue;
        }

        $status = IuguHelpers::normalizeStatus((string) ($res['body']['status'] ?? ''));

        if ($status !== 'paid') {
            // Atualiza o que mudou (expirou, cancelou) para o módulo parar de
            // oferecer um código que não vale mais.
            if (in_array($status, ['canceled', 'refunded', 'in_analysis'], true)) {
                IuguCharges::setStatusByIuguId($iuguId, $status);
            }
            continue;
        }

        // Daqui para baixo é a MESMA baixa do webhook.
        $body       = (array) $res['body'];
        $pagoCents  = (int) ($body['total_paid_cents'] ?? $body['total_cents'] ?? 0);
        $taxasCents = (int) ($body['taxes_paid_cents'] ?? 0);

        // ⚠ NÃO usar checkCbTransID() aqui. A documentação do WHMCS diz que
        // ela "performs a die upon encountering a duplicate" - e isto roda
        // dentro de um laço, chamado pelo cron diário. Uma única duplicata
        // mataria o cron inteiro do WHMCS a partir deste ponto, em silêncio.
        // No webhook o die é aceitável, porque encerra só aquela requisição.
        if (iugu_pagamento_ja_lancado($iuguId)) {
            IuguCharges::setStatusByIuguId($iuguId, 'paid', date('Y-m-d H:i:s'));
            continue;
        }

        try {
            addInvoicePayment(
                $invoiceId,
                $iuguId,
                number_format($pagoCents / 100, 2, '.', ''),
                number_format($taxasCents / 100, 2, '.', ''),
                'iugu'
            );

            IuguCharges::setStatusByIuguId($iuguId, 'paid', date('Y-m-d H:i:s'));
            IuguCharges::cancelOpen($client, $invoiceId, $iuguId);

            logActivity('Iugu: fatura #' . $invoiceId . ' baixada pela conferencia diaria (webhook nao chegou).');
            $baixadas++;
        } catch (\Throwable $e) {
            iugu_log($debug, 'reconcile: falha na baixa', ['fatura' => $invoiceId, 'msg' => $e->getMessage()]);
        }
    }

    iugu_log($debug, 'reconcile concluido', ['checadas' => count($pendentes), 'baixadas' => $baixadas]);

    return ['checadas' => count($pendentes), 'baixadas' => $baixadas];
}

// ═══════════════════════════════════════════════════════════════════════════
// Execução direta pela linha de comando
// ═══════════════════════════════════════════════════════════════════════════

// PHP_SAPI diferente de 'cli' significa que alguém abriu o arquivo pelo
// navegador. Nesse caso o arquivo apenas define a função acima e encerra.
if (PHP_SAPI === 'cli' && realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === realpath(__FILE__)) {
    require_once __DIR__ . '/../../../init.php';

    $r = iugu_reconcile();
    echo 'Iugu: ' . $r['checadas'] . ' cobrancas conferidas, ' . $r['baixadas'] . " baixadas.\n";
}
