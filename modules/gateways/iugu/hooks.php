<?php

declare(strict_types=1);

/**
 * hooks.php - tudo que o módulo faz sozinho, sem o cliente abrir a fatura.
 *
 * CAMINHO NO WHMCS: /modules/gateways/iugu/hooks.php
 *
 * ⚠️ ESTE ARQUIVO NÃO É CARREGADO SOZINHO. Quem o carrega é
 * /includes/hooks/iugu.php, que precisa ser instalado junto. O WHMCS detecta
 * o hooks.php de dentro do módulo apenas para módulos de provisionamento,
 * registrar e addon - gateway não entra nessa lista. Se aquele carregador
 * faltar, nada deste arquivo roda.
 *
 * A lógica mora aqui, e não lá, para que o módulo continue sendo uma pasta só:
 * atualizar é trocar /modules/gateways/iugu/ e pronto.
 *
 * Um arquivo só, e cada regra escrita uma vez: os hooks de fatura chamam a
 * mesma função de emissão, em vez de repetirem a lógica.
 *
 * O QUE ELE FAZ
 *
 *   InvoiceCreated             fatura nasceu  → já emite o Pix (e o boleto)
 *   InvoicePaymentReminder     antes do aviso → garante que a cobrança existe
 *   UpdateInvoiceTotal         valor mudou    → cancela e reemite com o novo
 *   InvoiceCancelled           fatura anulada → cancela na Iugu
 *   AdminInvoicesControlsOutput               → link da fatura na Iugu
 *   DailyCronJob               1x por dia     → conferência das pendentes
 *   AdminAreaFooterOutput                     → quadro de suporte e versão
 *
 * Emitir na criação é o que faz diferença na cobrança por WhatsApp: quando
 * chega a hora de avisar o cliente, o copia-e-cola e a linha digitável já
 * estão prontos, sem esperar a API.
 *
 * TUDO aqui é protegido por try/catch. Um problema na Iugu não pode impedir
 * o WHMCS de criar fatura, salvar alteração ou rodar o cron.
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

use WHMCS\Database\Capsule;

// Guarda contra dupla inclusão: sem isto, "Cannot redeclare" e hook duplicado.
if (defined('IUGU_HOOKS_LOADED')) {
    return;
}
define('IUGU_HOOKS_LOADED', 1);

/**
 * Carrega o gateway sob demanda e devolve a configuração dele.
 *
 * Não fazemos require no topo do arquivo de propósito: hooks.php é lido em
 * TODA página do WHMCS, inclusive nas que não têm nada a ver com pagamento.
 * Carregar o módulo inteiro só para descobrir que não é o caso é desperdício.
 *
 * @return array|null Configuração do gateway, ou null se não estiver ativo.
 */
function iugu_hook_gateway(): ?array
{
    static $cache = false; // false = ainda não tentou; null = tentou e não deu

    if ($cache !== false) {
        return $cache;
    }

    try {
        if (!function_exists('getGatewayVariables')) {
            require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
        }
        require_once __DIR__ . '/../iugu.php';

        $gw = getGatewayVariables('iugu');
        $cache = empty($gw['type']) ? null : $gw;
    } catch (\Throwable $e) {
        $cache = null;
    }

    return $cache;
}

/**
 * Diz se esta fatura interessa ao módulo.
 *
 * Interessa quando está em aberto, tem valor e o meio de pagamento é o iugu
 * (ou está vazio, o que acontece em fatura criada por API sem informar o
 * gateway). Fatura de outro gateway é ignorada em silêncio.
 *
 * @return object|null A linha de tblinvoices, ou null.
 */
function iugu_hook_invoice(int $invoiceId): ?object
{
    if ($invoiceId < 1) {
        return null;
    }

    try {
        $inv = Capsule::table('tblinvoices')->where('id', $invoiceId)->first();
    } catch (\Throwable $e) {
        return null;
    }

    if (!$inv || $inv->status !== 'Unpaid') {
        return null;
    }

    $pm = strtolower((string) $inv->paymentmethod);
    if ($pm !== '' && $pm !== 'iugu') {
        return null;
    }

    // Fatura zerada (ou de centavos) não vira cobrança na Iugu.
    if ((int) round(((float) $inv->total) * 100) < 100) {
        return null;
    }

    return $inv;
}

/**
 * Garante que a fatura tenha Pix - e boleto, quando o cadastro permite.
 *
 * É a ÚNICA função que emite cobrança automática. Os três hooks que precisam
 * disso chamam esta aqui; era esse trecho que estava copiado em três arquivos
 * na versão anterior, já com diferenças entre eles.
 *
 * Idempotente: método que já tem cobrança aberta ou paga não é reemitido.
 *
 * @param bool $force true = cancela o que existe e emite de novo (usado quando
 *                    o valor da fatura muda no admin).
 */
function iugu_hook_ensure_charges(int $invoiceId, bool $force = false): void
{
    $gateway = iugu_hook_gateway();

    if ($gateway === null) {
        return;
    }

    $inv = iugu_hook_invoice($invoiceId);
    if ($inv === null) {
        return;
    }

    $debug   = ($gateway['debug_log'] ?? '') === 'on';
    $metodos = iugu_enabled_methods($gateway);

    try {
        $client = iugu_make_client($gateway);
    } catch (\Throwable $e) {
        iugu_log($debug, 'hooks: gateway sem credencial', $e->getMessage());
        return;
    }

    // Valor mudou: a cobrança antiga está errada e a Iugu não deixa criar
    // outra com o mesmo order_id. Cancela antes de reemitir.
    if ($force) {
        IuguCharges::cancelOpen($client, $invoiceId);
    }

    $emUso = $force ? [] : IuguCharges::methodsInUse($invoiceId);

    $precisaPix    = in_array('pix', $metodos, true)    && !in_array('pix', $emUso, true);
    $precisaBoleto = in_array('boleto', $metodos, true) && !in_array('boleto', $emUso, true);

    if (!$precisaPix && !$precisaBoleto) {
        return;
    }

    $built = iugu_build_charge_args($gateway, $invoiceId);
    if (!$built['ok']) {
        iugu_log($debug, 'hooks: fatura #' . $invoiceId . ' sem dados para cobranca', (string) $built['error']);
        return;
    }

    $service   = new IuguInvoice($client);
    $clientId  = (int) $built['client_id'];
    $cents     = (int) $built['amount_cents'];
    $args      = $built['args'];
    $link      = iugu_pay_link($gateway, $invoiceId);

    if ($precisaPix) {
        try {
            $res = $service->createPix($args + ['expires_in' => (string) ($gateway['pix_expires_in'] ?? '3d')]);

            if (!empty($res['ok'])) {
                IuguCharges::save($invoiceId, $clientId, 'pix', [
                    'iugu_id'       => $res['iugu_id'],
                    'status'        => 'pending',
                    'qrcode_base64' => $res['pix']['qrcode'],
                    'qrcode_text'   => $res['pix']['qrcode_text'],
                    'secure_url'    => $link !== '' ? $link : $res['secure_url'],
                    'expires_at'    => $res['expires_at'],
                    'amount_cents'  => $cents,
                ]);
            } else {
                logActivity('Iugu: Pix nao gerado para a fatura #' . $invoiceId . ': ' . $res['error']);
            }
        } catch (\Throwable $e) {
            logActivity('Iugu: excecao ao gerar Pix da fatura #' . $invoiceId . ': ' . $e->getMessage());
        }
    }

    // Boleto exige documento válido; sem isso a Iugu recusa. O Pix não exige,
    // por isso ele é tentado de qualquer jeito e o boleto só quando dá.
    if ($precisaBoleto && !empty($built['cpf_ok'])) {
        try {
            $res = $service->createBoleto($args);

            if (!empty($res['ok'])) {
                IuguCharges::save($invoiceId, $clientId, 'boleto', [
                    'iugu_id'           => $res['iugu_id'],
                    'status'            => 'pending',
                    'bank_slip_barcode' => $res['bank_slip']['digitable_line'],
                    'bank_slip_pdf'     => $res['bank_slip']['pdf_url'],
                    'secure_url'        => $res['secure_url'],
                    'expires_at'        => $res['expires_at'],
                    'amount_cents'      => $cents,
                ]);
            } else {
                logActivity('Iugu: boleto nao gerado para a fatura #' . $invoiceId . ': ' . $res['error']);
            }
        } catch (\Throwable $e) {
            logActivity('Iugu: excecao ao gerar boleto da fatura #' . $invoiceId . ': ' . $e->getMessage());
        }
    } elseif ($precisaBoleto) {
        iugu_log($debug, 'hooks: boleto nao emitido, documento invalido', ['fatura' => $invoiceId]);
    }
}

// ═══════════════════════════════════════════════════════════════════════════
// Fatura criada - emite já
// ═══════════════════════════════════════════════════════════════════════════

add_hook('InvoiceCreated', 1, static function (array $vars): void {
    try {
        iugu_hook_ensure_charges((int) ($vars['invoiceid'] ?? 0));
    } catch (\Throwable $e) {
        logActivity('Iugu (InvoiceCreated): ' . $e->getMessage());
    }
});

// ═══════════════════════════════════════════════════════════════════════════
// Antes do lembrete de vencimento - garante que existe o que cobrar
// ═══════════════════════════════════════════════════════════════════════════

add_hook('InvoicePaymentReminder', 1, static function (array $vars): void {
    try {
        iugu_hook_ensure_charges((int) ($vars['invoiceid'] ?? 0));
    } catch (\Throwable $e) {
        logActivity('Iugu (InvoicePaymentReminder): ' . $e->getMessage());
    }
});

// ═══════════════════════════════════════════════════════════════════════════
// Valor da fatura mudou - reemite
// ═══════════════════════════════════════════════════════════════════════════

add_hook('UpdateInvoiceTotal', 1, static function ($vars): void {
    try {
        // Este hook às vezes entrega um array e às vezes o próprio id.
        $invoiceId = is_array($vars) ? (int) ($vars['invoiceid'] ?? 0) : (int) $vars;

        $inv = iugu_hook_invoice($invoiceId);
        if ($inv === null) {
            return;
        }

        $novoCents = (int) round(((float) $inv->total) * 100);
        $abertas   = IuguCharges::openAll($invoiceId);

        if ($abertas === []) {
            return; // ainda não emitiu nada: InvoiceCreated cuida
        }

        // Só age se algum valor divergir. Sem esta comparação o hook reemitiria
        // a cada salvamento da fatura, trocando o código do cliente à toa.
        $mudou = false;
        foreach ($abertas as $linha) {
            if ((int) $linha->amount_cents !== $novoCents) {
                $mudou = true;
                break;
            }
        }

        if ($mudou) {
            iugu_hook_ensure_charges($invoiceId, true);
            logActivity('Iugu: cobrancas da fatura #' . $invoiceId . ' reemitidas com o novo valor.');
        }
    } catch (\Throwable $e) {
        logActivity('Iugu (UpdateInvoiceTotal): ' . $e->getMessage());
    }
});

// ═══════════════════════════════════════════════════════════════════════════
// Fatura cancelada - cancela na Iugu
// ═══════════════════════════════════════════════════════════════════════════

add_hook('InvoiceCancelled', 1, static function (array $vars): void {
    try {
        $invoiceId = (int) ($vars['invoiceid'] ?? 0);
        $gateway   = iugu_hook_gateway();

        if ($invoiceId < 1 || $gateway === null) {
            return;
        }

        if (IuguCharges::openAll($invoiceId) === []) {
            return;
        }

        // Deixar a cobrança aberta na Iugu depois de cancelar a fatura é como
        // o cliente acaba pagando algo que não devia mais.
        $n = IuguCharges::cancelOpen(iugu_make_client($gateway), $invoiceId);

        if ($n > 0) {
            logActivity('Iugu: ' . $n . ' cobranca(s) da fatura #' . $invoiceId . ' cancelada(s) na Iugu.');
        }
    } catch (\Throwable $e) {
        logActivity('Iugu (InvoiceCancelled): ' . $e->getMessage());
    }
});

// ═══════════════════════════════════════════════════════════════════════════
// Tela da fatura no admin - link da cobrança na Iugu
// ═══════════════════════════════════════════════════════════════════════════

add_hook('AdminInvoicesControlsOutput', 1, static function (array $vars): string {
    try {
        $invoiceId = (int) ($vars['invoiceid'] ?? 0);
        if ($invoiceId < 1) {
            return '';
        }

        $linha = Capsule::table(IUGU_TABLE)
            ->where('whmcs_invoice_id', $invoiceId)
            ->whereNotNull('secure_url')
            ->where('secure_url', '!=', '')
            ->orderBy('id', 'desc')
            ->first();

        if (!$linha) {
            return '';
        }

        $url = htmlspecialchars((string) $linha->secure_url, ENT_QUOTES, 'UTF-8');

        return '<div style="margin-top:10px;padding:10px 12px;border:1px solid #e5e7eb;'
             . 'border-radius:8px;background:#fafafa">'
             . '<strong style="font-size:13px">Cobrança Iugu</strong><br>'
             . '<a href="' . $url . '" target="_blank" rel="noopener" class="btn btn-sm btn-primary" '
             . 'style="margin-top:6px">Abrir página de pagamento</a>'
             . '<input type="text" readonly value="' . $url . '" onclick="this.select()" '
             . 'style="margin-top:8px;width:100%;font-size:12px;padding:6px 8px;'
             . 'border:1px solid #e5e7eb;border-radius:6px;background:#fff">'
             . '</div>';
    } catch (\Throwable $e) {
        return ''; // um link a menos nunca vale quebrar a tela da fatura
    }
});

// ═══════════════════════════════════════════════════════════════════════════
// Cron diário - conferência das cobranças pendentes
// ═══════════════════════════════════════════════════════════════════════════

add_hook('DailyCronJob', 1, static function (): void {
    try {
        $gateway = iugu_hook_gateway();

        if ($gateway === null) {
            return;
        }

        // O WHMCS não chama _upgrade() em gateway. Esta é a passagem diária
        // que garante o esquema depois de uma atualização de versão.
        iugu_ensure_schema();

        if (($gateway['enable_reconcile'] ?? '') !== 'on') {
            return;
        }

        require_once __DIR__ . '/reconcile.php';
        $r = iugu_reconcile();

        if ($r['baixadas'] > 0) {
            logActivity('Iugu: conferencia diaria baixou ' . $r['baixadas'] . ' fatura(s).');
        }
    } catch (\Throwable $e) {
        logActivity('Iugu (DailyCronJob): ' . $e->getMessage());
    }
});

// ═══════════════════════════════════════════════════════════════════════════
// Admin - quadro de suporte, atualização e versão na tela do gateway
// ═══════════════════════════════════════════════════════════════════════════

/**
 * Coloca as informações do módulo na tela de configuração do gateway.
 *
 * O WHMCS monta essa tela sozinho, a partir de iugu_config(), e ali não cabe
 * HTML nosso. Então o conteúdo é montado no PHP e um trecho de JavaScript
 * cria com ele uma LINHA da própria tabela de configuração, na posição certa:
 * imediatamente antes da linha do botão Salvar, com o rótulo na primeira
 * coluna e o conteúdo na segunda - exatamente como as outras linhas.
 *
 * As classes das células são COPIADAS de uma linha que já existe na tabela,
 * em vez de adivinhadas. Assim o bloco herda o estilo do tema do admin,
 * inclusive em tema escuro, e não quebra o alinhamento das colunas.
 *
 * Por que uma linha, e não um bloco solto: uma <div> dentro de <table> é
 * expulsa da tabela pelo navegador e vai parar no topo da página; uma célula
 * com colspan desalinha as colunas do formulário.
 */
add_hook('AdminAreaFooterOutput', 1, static function ($vars): string {
    try {
        $arquivo = is_array($vars) ? (string) ($vars['filename'] ?? '') : '';
        $script  = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
        $uri     = (string) ($_SERVER['REQUEST_URI'] ?? '');

        // Só na tela de gateways de pagamento. Três formas de reconhecê-la,
        // porque o WHMCS tem página legada (configgateways.php) e páginas
        // novas que passam pelo roteador (index.php?rp=/admin/...).
        $ehTelaGateway = $arquivo === 'configgateways'
            || $script === 'configgateways.php'
            || str_contains($uri, 'configgateways')
            || (str_contains($uri, 'rp=') && str_contains($uri, 'payment'));

        if (!$ehTelaGateway) {
            return '';
        }

        require_once __DIR__ . '/config.php';

        // "verificar" apaga o cache do manifesto antes de montar o conteúdo.
        if (!empty($_GET['iugucheck'])) {
            iugu_forget_latest();
        }

        require_once __DIR__ . '/admin_panel.php';

        $dados = json_encode([
            'label' => iugu_admin_panel_label(),
            'html'  => iugu_admin_panel_html(),
        ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

        return <<<JS
<script>
(function () {
    var DADOS = {$dados};

    /* Âncora: o campo de token, que só este módulo tem. */
    var campo = document.querySelector('[name*="api_token_live"], [id*="api_token_live"]');
    if (!campo) { return; }

    var linhaModelo = campo.closest('tr');
    var form        = campo.closest('form');
    if (!linhaModelo || !form) { return; }

    var corpo = linhaModelo.parentNode;

    /* A linha do botão Salvar é a última do mesmo corpo de tabela que tenha
       um submit. Se não houver, entra no fim da tabela. */
    var linhaSalvar = null;
    var submit = form.querySelector('input[type=submit], button[type=submit]');
    if (submit && submit.closest('tr') && submit.closest('tr').parentNode === corpo) {
        linhaSalvar = submit.closest('tr');
    }

    /* Monta a linha nova copiando a estrutura e as classes da linha modelo. */
    var tr = document.createElement('tr');
    tr.id  = 'iugu-info-linha';

    var celulas = linhaModelo.cells;

    for (var i = 0; i < celulas.length; i++) {
        var td = document.createElement('td');
        td.className = celulas[i].className;   // herda o estilo do tema

        if (i === 0) {
            td.textContent = DADOS.label;
        } else if (i === 1) {
            td.innerHTML = DADOS.html;
        }
        tr.appendChild(td);
    }

    if (linhaSalvar) { corpo.insertBefore(tr, linhaSalvar); }
    else { corpo.appendChild(tr); }
})();
</script>
JS;
    } catch (\Throwable $e) {
        return ''; // o quadro é informativo: nunca vale derrubar o admin
    }
});
