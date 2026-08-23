<?php

declare(strict_types=1);

/**
 * verpix.php - página pública de pagamento.
 *
 * CAMINHO NO WHMCS: /verpix.php  (na RAIZ, junto do init.php)
 * ENDEREÇO:         https://SEU-WHMCS/verpix.php?i=1234&t=<token>
 *
 * PARA QUE SERVE
 * É o link que você manda ao cliente por WhatsApp. Ele abre, vê o valor, e
 * paga por Pix, boleto ou cartão - sem login, sem procurar fatura, sem senha.
 * Quem cobra por mensagem sabe o quanto isso muda a taxa de pagamento.
 *
 * COMO É PROTEGIDO
 * O endereço leva um token assinado com o token de API da Iugu, que só existe
 * no banco do WHMCS. Sem ele, a página responde 404 e não diz sequer se a
 * fatura existe.
 *
 * Sem token não há como varrer números de fatura: cada link abre a sua, e
 * só a sua. Não existe parâmetro que force reemissão, então o copia-e-cola
 * que o cliente já recebeu não é invalidado por ninguém de fora.
 *
 * A página carrega o init.php e usa as classes do módulo, como qualquer
 * arquivo do WHMCS - por isso precisa ficar na RAIZ, junto dele.
 *
 * SOBRE O CSS DESTA PÁGINA
 * Aqui existe CSS próprio, e é proposital: esta página roda FORA da área do
 * cliente, sem tema do WHMCS carregado. Não há classe de tema para herdar.
 * Dentro do WHMCS (a tela da fatura) o módulo usa só classe do tema.
 * A marca vem das configurações do WHMCS: nome da empresa e logo.
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

require_once __DIR__ . '/init.php';
require_once __DIR__ . '/includes/gatewayfunctions.php';
require_once __DIR__ . '/includes/invoicefunctions.php';
require_once __DIR__ . '/modules/gateways/iugu.php';

use WHMCS\Database\Capsule;

$invoiceId = (int) ($_GET['i'] ?? 0);
$token     = (string) ($_GET['t'] ?? '');
$acao      = (string) ($_GET['a'] ?? '');
$ehJson    = $acao !== '';

/** Encerra a requisição. Página ou JSON, conforme o modo. */
function verpix_fim(int $http, string $msg, array $json = []): never
{
    global $ehJson;

    http_response_code($http);

    if ($ehJson) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($json ?: ['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
        exit;
    }

    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>Pagamento</title>'
        . '<div style="font:16px/1.6 -apple-system,Segoe UI,Roboto,sans-serif;max-width:420px;'
        . 'margin:80px auto;padding:0 20px;text-align:center;color:#333">'
        . htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') . '</div>';
    exit;
}

/** Escapa para HTML. */
function verpix_h(mixed $v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}

// ═══════════════════════════════════════════════════════════════════════════
// 1. Gateway e token
// ═══════════════════════════════════════════════════════════════════════════

$gateway = getGatewayVariables('iugu');

if (empty($gateway['type'])) {
    verpix_fim(503, 'Pagamento indisponível no momento.');
}

$debug = ($gateway['debug_log'] ?? '') === 'on';

// Sem fatura ou sem token: 404 seco. Não confirmamos nem negamos que a fatura
// existe - quem não tem o link não descobre nada por aqui.
if ($invoiceId < 1 || $token === '') {
    verpix_fim(404, 'Página não encontrada.');
}

try {
    $apiToken = iugu_api_token($gateway);
} catch (\Throwable $e) {
    verpix_fim(503, 'Pagamento indisponível no momento.');
}

if (!hash_equals(iugu_verpix_token($invoiceId, $apiToken), $token)) {
    iugu_log($debug, 'verpix: token invalido', ['fatura' => $invoiceId]);
    verpix_fim(404, 'Página não encontrada.');
}

// ═══════════════════════════════════════════════════════════════════════════
// 2. Fatura
// ═══════════════════════════════════════════════════════════════════════════

try {
    $inv = Capsule::table('tblinvoices')->where('id', $invoiceId)->first();
} catch (\Throwable $e) {
    verpix_fim(503, 'Não foi possível carregar a fatura agora.');
}

if (!$inv) {
    verpix_fim(404, 'Página não encontrada.');
}

$paga = $inv->status === 'Paid';

// "Já pagou?" - consultado em laço pela página, igual ao check_status.php da
// área do cliente. Nunca chama a Iugu: lê o status no WHMCS, que o webhook e
// a conferência diária mantêm em dia.
if ($acao === 'check') {
    verpix_fim(200, '', ['paid' => $paga, 'status' => (string) $inv->status]);
}

if (!$paga && in_array($inv->status, ['Cancelled', 'Refunded', 'Draft'], true)) {
    verpix_fim(410, 'Esta fatura não está mais em aberto.');
}

// ═══════════════════════════════════════════════════════════════════════════
// 3. Emissão (Pix, boleto, cartão) - mesmas regras da tela da fatura
// ═══════════════════════════════════════════════════════════════════════════

if ($acao !== '') {
    if ($paga) {
        verpix_fim(200, '', ['ok' => true, 'paid' => true]);
    }

    if (!in_array($acao, ['pix', 'boleto', 'card'], true)
        || !in_array($acao, iugu_enabled_methods($gateway), true)) {
        verpix_fim(400, '', ['ok' => false, 'error' => 'Forma de pagamento indisponível.']);
    }

    $totalCents = IuguHelpers::toCents((float) $inv->total);
    $clientId   = (int) $inv->userid;

    // Reaproveita a cobrança guardada - desde que o VALOR ainda bata. É o que
    // mantém o mesmo copia-e-cola que o cliente já recebeu, sem servir uma
    // cobrança de valor velho.
    if (in_array($acao, ['pix', 'boleto'], true)) {
        $c = IuguCharges::openOf($invoiceId, $acao);

        if ($c && (int) $c->amount_cents === $totalCents) {
            if ($acao === 'pix' && !empty($c->qrcode_text)) {
                verpix_fim(200, '', ['ok' => true, 'pix' => [
                    'qrcode'      => (string) $c->qrcode_base64,
                    'qrcode_text' => (string) $c->qrcode_text,
                ]]);
            }
            if ($acao === 'boleto' && !empty($c->bank_slip_barcode)) {
                verpix_fim(200, '', ['ok' => true, 'boleto' => [
                    'digitable_line' => (string) $c->bank_slip_barcode,
                    'pdf_url'        => (string) $c->bank_slip_pdf,
                    'barcode_url'    => IuguInvoice::barcodeUrl((string) $c->secure_url),
                ]]);
            }
        }
    }

    $built = iugu_build_charge_args($gateway, $invoiceId);

    if (!$built['ok']) {
        verpix_fim(400, '', ['ok' => false, 'error' => (string) $built['error']]);
    }

    if (in_array($acao, ['boleto', 'card'], true) && !$built['cpf_ok']) {
        verpix_fim(400, '', ['ok' => false,
            'error' => 'O CPF ou CNPJ do cadastro não está correto. Fale com o suporte.']);
    }

    try {
        $client  = iugu_make_client($gateway);
        $service = new IuguInvoice($client);
        $args    = $built['args'];

        // Cobrança aberta de valor diferente: cancela antes de emitir a nova,
        // senão ficam duas válidas e o cliente pode pagar a errada.
        if (in_array($acao, ['pix', 'boleto'], true)) {
            $antiga = IuguCharges::openOf($invoiceId, $acao);
            if ($antiga && !empty($antiga->iugu_invoice_id)) {
                try { $client->cancelInvoice((string) $antiga->iugu_invoice_id); } catch (\Throwable $e) {}
                IuguCharges::setStatusById((int) $antiga->id, 'canceled');
            }
        }

        if ($acao === 'pix') {
            $res = $service->createPix($args + ['expires_in' => (string) ($gateway['pix_expires_in'] ?? '3d')]);

            if (empty($res['ok'])) {
                iugu_log($debug, 'verpix: pix falhou', (string) $res['error']);
                verpix_fim(502, '', ['ok' => false, 'error' => 'Não foi possível gerar o Pix agora.']);
            }

            IuguCharges::save($invoiceId, $clientId, 'pix', [
                'iugu_id'       => $res['iugu_id'],
                'status'        => 'pending',
                'qrcode_base64' => $res['pix']['qrcode'],
                'qrcode_text'   => $res['pix']['qrcode_text'],
                'secure_url'    => $res['secure_url'],
                'expires_at'    => $res['expires_at'],
                'amount_cents'  => $totalCents,
            ]);

            verpix_fim(200, '', ['ok' => true, 'pix' => [
                'qrcode'      => $res['pix']['qrcode'],
                'qrcode_text' => $res['pix']['qrcode_text'],
            ]]);
        }

        if ($acao === 'boleto') {
            $res = $service->createBoleto($args);

            if (empty($res['ok'])) {
                iugu_log($debug, 'verpix: boleto falhou', (string) $res['error']);
                verpix_fim(502, '', ['ok' => false, 'error' => 'Não foi possível gerar o boleto agora.']);
            }

            IuguCharges::save($invoiceId, $clientId, 'boleto', [
                'iugu_id'           => $res['iugu_id'],
                'status'            => 'pending',
                'bank_slip_barcode' => $res['bank_slip']['digitable_line'],
                'bank_slip_pdf'     => $res['bank_slip']['pdf_url'],
                'secure_url'        => $res['secure_url'],
                'amount_cents'      => $totalCents,
            ]);

            verpix_fim(200, '', ['ok' => true, 'boleto' => [
                'digitable_line' => $res['bank_slip']['digitable_line'],
                'pdf_url'        => $res['bank_slip']['pdf_url'],
                'barcode_url'    => $res['bank_slip']['barcode_url'],
            ]]);
        }

        // ── Cartão ────────────────────────────────────────────────────────
        $cardToken = trim((string) ($_POST['card_token'] ?? ''));
        $meses     = max(1, min((int) ($gateway['card_max_installments'] ?? 1), (int) ($_POST['months'] ?? 1)));

        if ($cardToken === '') {
            verpix_fim(400, '', ['ok' => false, 'error' => 'Confira os dados do cartão.']);
        }

        $res = $service->chargeCard([
            'token'            => $cardToken,
            'email'            => $args['email'],
            'payer'            => $args['payer'],
            'items'            => $args['items'],
            'months'           => $meses,
            'whmcs_invoice_id' => $invoiceId,
            'notification_url' => $args['notification_url'],
        ]);

        if (empty($res['ok'])) {
            verpix_fim(402, '', ['ok' => false, 'error' => (string) ($res['error'] ?? 'Cartão recusado.')]);
        }

        // "success" no /charge não quer dizer pago: pode estar em análise.
        $iuguId = (string) ($res['iugu_id'] ?? '');
        $status = 'pending';

        if ($iuguId !== '') {
            $g = $client->getInvoice($iuguId);
            if (!empty($g['ok'])) {
                $status = IuguHelpers::normalizeStatus((string) ($g['body']['status'] ?? 'pending'));
            }
        }

        IuguCharges::save($invoiceId, $clientId, 'card', [
            'iugu_id'        => $iuguId,
            'iugu_charge_id' => $res['card']['transaction_id'] ?? null,
            'status'         => in_array($status, ['paid', 'in_analysis', 'pending'], true) ? $status : 'pending',
            'amount_cents'   => $totalCents,
            'paid_at'        => $status === 'paid' ? date('Y-m-d H:i:s') : null,
        ]);

        if ($status !== 'paid') {
            verpix_fim(200, '', ['ok' => true, 'paid' => false,
                'message' => 'Pagamento em análise pela operadora. Assim que for aprovado, '
                           . 'esta tela atualiza sozinha.']);
        }

        // transid = ID da fatura na Iugu, o MESMO que o webhook usaria: se ele
        // chegar depois, o WHMCS reconhece a duplicidade e não lança duas vezes.
        addInvoicePayment(
            $invoiceId,
            $iuguId !== '' ? $iuguId : ('iugu-card-' . $invoiceId),
            number_format((float) $inv->total, 2, '.', ''),
            '0.00',
            'iugu'
        );

        IuguCharges::cancelOpen($client, $invoiceId, $iuguId);

        verpix_fim(200, '', ['ok' => true, 'paid' => true]);
    } catch (\Throwable $e) {
        iugu_log($debug, 'verpix: excecao', ['fatura' => $invoiceId, 'msg' => $e->getMessage()]);
        verpix_fim(500, '', ['ok' => false, 'error' => 'Não foi possível concluir agora.']);
    }
}

// ═══════════════════════════════════════════════════════════════════════════
// 4. A página
// ═══════════════════════════════════════════════════════════════════════════

// Marca de quem instalou, tirada das configurações do WHMCS. Nada fixo.
$empresa = (string) (\WHMCS\Config\Setting::getValue('CompanyName') ?? '');
$logo    = (string) (\WHMCS\Config\Setting::getValue('LogoURL') ?? '');

$metodos = iugu_enabled_methods($gateway);
$conta   = trim((string) ($gateway['account_id'] ?? ''));

if ($conta === '') {
    $metodos = array_values(array_filter($metodos, static fn ($m) => $m !== 'card'));
}

$cfg = json_encode([
    'base'       => '?i=' . $invoiceId . '&t=' . rawurlencode($token),
    'metodos'    => array_values($metodos),
    'conta'      => $conta,
    'teste'      => ($gateway['mode'] ?? 'live') === 'test',
    'parcelas'   => max(1, (int) ($gateway['card_max_installments'] ?? 1)),
    'paga'       => $paga,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

$valor = number_format((float) $inv->total, 2, ',', '.');
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Pagamento - Fatura #<?= $invoiceId ?><?= $empresa !== '' ? ' - ' . verpix_h($empresa) : '' ?></title>
<style>
/* Página fora da área do cliente: sem tema do WHMCS para herdar, então o
   estilo mora aqui. Curto de propósito. */
*{box-sizing:border-box}
body{margin:0;padding:24px 16px 60px;background:#f4f6f9;color:#20242b;
     font:16px/1.55 -apple-system,"Segoe UI",Roboto,Helvetica,Arial,sans-serif}
.caixa{max-width:460px;margin:0 auto;background:#fff;border-radius:12px;
       box-shadow:0 2px 12px rgba(0,0,0,.07);overflow:hidden}
.topo{padding:22px 24px;border-bottom:1px solid #eceff3;text-align:center}
.topo img{max-height:44px;max-width:200px;margin-bottom:10px}
.topo .empresa{font-weight:600;color:#5a6472;font-size:14px}
.valor{padding:20px 24px;text-align:center;border-bottom:1px solid #eceff3}
.valor .n{font-size:32px;font-weight:700;letter-spacing:-.5px}
.valor .r{color:#78828f;font-size:14px}
.corpo{padding:22px 24px}
.abas{display:flex;gap:8px;margin-bottom:20px}
.abas button{flex:1;min-height:48px;font-size:15px;font-weight:600;cursor:pointer;
             border:1px solid #d5dae1;background:#f7f9fb;color:#3a424e;border-radius:8px}
.abas button:hover{background:#eaeef3}
.abas button.on{background:#20242b;border-color:#20242b;color:#fff}
.painel{display:none}.painel.on{display:block}
.centro{text-align:center}
.aviso{padding:12px 14px;border-radius:8px;font-size:14px;margin-bottom:16px}
.aviso.info{background:#eaf3fb;color:#1f5c8b}
.aviso.erro{background:#fdecec;color:#9c2b2b}
.aviso.ok{background:#e9f7ee;color:#1d6c39}
img.qr{width:100%;max-width:230px;display:block;margin:0 auto 16px}
img.barras{width:100%;margin-bottom:14px}
.codigo{width:100%;font:13px/1.5 ui-monospace,Menlo,Consolas,monospace;padding:12px;
        border:1px solid #dde2e8;border-radius:8px;resize:none;background:#fafbfc;color:#20242b}
.linha{font:15px/1.5 ui-monospace,Menlo,Consolas,monospace;padding:14px;background:#fafbfc;
       border:1px solid #dde2e8;border-radius:8px;word-break:break-all;text-align:center}
.b{display:block;width:100%;min-height:48px;margin-top:12px;border-radius:8px;cursor:pointer;
   font-size:15px;font-weight:600;border:1px solid #d5dae1;background:#fff;color:#3a424e;
   text-align:center;text-decoration:none;line-height:46px}
.b:hover{background:#f2f5f8}
.b.forte{background:#20242b;border-color:#20242b;color:#fff}
.b.forte:hover{background:#343a45}
.dupla{display:flex;gap:10px}.dupla .b{margin-top:12px}
label{display:block;font-size:13px;font-weight:600;margin:14px 0 4px;color:#4a525e}
input,select{width:100%;height:46px;padding:10px 12px;font-size:16px;
             border:1px solid #d5dae1;border-radius:8px;background:#fff;color:#20242b}
input:focus,select:focus{outline:0;border-color:#20242b}
input.ruim{border-color:#d14343;background:#fffafa}
.meio{display:flex;gap:10px}.meio>div{flex:1}
.rodape{padding:14px 24px 20px;text-align:center;color:#98a1ad;font-size:12px}
</style>
</head>
<body>

<div class="caixa">
    <div class="topo">
        <?php if ($logo !== ''): ?>
            <img src="<?= verpix_h($logo) ?>" alt="<?= verpix_h($empresa) ?>"
                 onerror="this.style.display='none'">
        <?php endif; ?>
        <?php if ($empresa !== ''): ?>
            <div class="empresa"><?= verpix_h($empresa) ?></div>
        <?php endif; ?>
    </div>

    <div class="valor">
        <div class="n">R$ <?= verpix_h($valor) ?></div>
        <div class="r">Fatura #<?= $invoiceId ?></div>
    </div>

    <div class="corpo" id="corpo">
        <div class="abas" id="abas"></div>

        <div class="painel centro" id="p-pix">
            <div class="estado"></div>
            <div class="conteudo" hidden>
                <img class="qr" id="qr" alt="Código Pix">
                <textarea class="codigo" id="codigo" readonly rows="3"></textarea>
                <button type="button" class="b forte" id="copiar-pix">Copiar código Pix</button>
            </div>
        </div>

        <div class="painel" id="p-boleto">
            <div class="estado"></div>
            <div class="conteudo" hidden>
                <img class="barras" id="barras" alt="Código de barras" hidden>
                <div class="linha" id="linha"></div>
                <div class="dupla">
                    <button type="button" class="b" id="copiar-boleto">Copiar linha</button>
                    <a class="b forte" id="pdf" target="_blank" rel="noopener">Ver boleto</a>
                </div>
            </div>
        </div>

        <div class="painel" id="p-card">
            <div class="estado"></div>
            <form id="form-card" autocomplete="on" novalidate>
                <label for="cc-nome">Nome impresso no cartão</label>
                <input type="text" id="cc-nome" data-iugu="full_name" autocomplete="cc-name"
                       autocapitalize="characters" maxlength="60" placeholder="COMO ESTÁ NO CARTÃO">
                <label for="cc-num">Número do cartão</label>
                <input type="text" id="cc-num" data-iugu="number" autocomplete="cc-number"
                       inputmode="numeric" maxlength="23" placeholder="0000 0000 0000 0000">
                <div class="meio">
                    <div>
                        <label for="cc-val">Validade</label>
                        <input type="text" id="cc-val" data-iugu="expiration" autocomplete="cc-exp"
                               inputmode="numeric" maxlength="7" placeholder="MM/AAAA">
                    </div>
                    <div>
                        <label for="cc-cvv">Código de segurança</label>
                        <input type="text" id="cc-cvv" data-iugu="verification_value"
                               autocomplete="cc-csc" inputmode="numeric" maxlength="4" placeholder="CVV">
                    </div>
                </div>
                <div id="parcelas-campo" hidden>
                    <label for="parcelas">Parcelas</label>
                    <select id="parcelas"></select>
                </div>
                <button type="submit" class="b forte" id="pagar-card">Pagar com cartão</button>
            </form>
        </div>
    </div>

    <div class="rodape">Pagamento processado pela Iugu.</div>
</div>

<script>
(function () {
    var CFG = <?= $cfg ?>;

    var $ = function (id) { return document.getElementById(id); };

    var ROTULO = { pix: 'Pix', boleto: 'Boleto', card: 'Cartão' };

    function painel(m)   { return $('p-' + m); }
    function estado(m)   { return painel(m).querySelector('.estado'); }
    function conteudo(m) { return painel(m).querySelector('.conteudo'); }

    function limpar(m) { estado(m).innerHTML = ''; }
    function mostra(m, classe, texto) {
        estado(m).innerHTML = '<div class="aviso ' + classe + '">' + texto + '</div>';
    }

    /* Fatura já paga: não há o que oferecer. */
    if (CFG.paga) {
        $('corpo').innerHTML = '<div class="aviso ok centro">Esta fatura já está paga. Obrigado!</div>';
        return;
    }

    // ── Abas ─────────────────────────────────────────────────────────────
    var abas = $('abas'), botoes = {}, atual = null;

    function abrir(m) {
        if (atual === m) { return; }
        atual = m;

        CFG.metodos.forEach(function (x) {
            painel(x).classList.toggle('on', x === m);
            botoes[x].classList.toggle('on', x === m);
        });

        if (m === 'card') { return; }
        if (!conteudo(m).hidden) { return; }
        gerar(m);
    }

    CFG.metodos.forEach(function (m) {
        var b = document.createElement('button');
        b.type = 'button';
        b.textContent = ROTULO[m];
        b.addEventListener('click', function () { abrir(m); });
        abas.appendChild(b);
        botoes[m] = b;
    });

    // ── Servidor ─────────────────────────────────────────────────────────
    function pedir(acao, corpo) {
        return fetch(CFG.base + '&a=' + acao, {
            method: corpo ? 'POST' : 'GET',
            headers: corpo ? { 'Content-Type': 'application/x-www-form-urlencoded' } : {},
            body: corpo || undefined
        }).then(function (r) { return r.json(); });
    }

    function ajustar(ta) {
        ta.rows = 2;
        var n = 0;
        while (ta.scrollHeight > ta.clientHeight && n++ < 14) { ta.rows++; }
    }

    function gerar(m) {
        mostra(m, 'info', m === 'pix' ? 'Gerando o código Pix…' : 'Gerando o boleto…');

        pedir(m).then(function (d) {
            if (!d || !d.ok) { return mostra(m, 'erro', (d && d.error) || 'Não foi possível concluir agora.'); }
            if (d.paid) { location.reload(); return; }

            if (m === 'pix') {
                $('qr').src = d.pix.qrcode;
                $('codigo').value = d.pix.qrcode_text;
            } else {
                $('linha').textContent = d.boleto.digitable_line;
                var bar = $('barras');
                if (d.boleto.barcode_url) { bar.src = d.boleto.barcode_url; bar.hidden = false; }
                else { bar.hidden = true; }
                var pdf = $('pdf');
                if (d.boleto.pdf_url) { pdf.href = d.boleto.pdf_url; pdf.hidden = false; }
                else { pdf.hidden = true; }
            }

            limpar(m);
            conteudo(m).hidden = false;
            if (m === 'pix') { ajustar($('codigo')); }
            vigiar();
        }).catch(function () { mostra(m, 'erro', 'Não foi possível concluir agora.'); });
    }

    // ── "Já pagou?" ──────────────────────────────────────────────────────
    var laco = null;
    function vigiar() {
        if (laco) { return; }
        laco = setInterval(function () {
            pedir('check').then(function (j) {
                if (j && j.paid) { clearInterval(laco); location.reload(); }
            }).catch(function () {});
        }, 6000);
    }

    // ── Copiar ───────────────────────────────────────────────────────────
    function copiar(texto, botao) {
        var pronto = function () {
            var antes = botao.textContent;
            botao.textContent = 'Copiado!';
            setTimeout(function () { botao.textContent = antes; }, 2000);
        };
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(texto).then(pronto, pronto);
        } else {
            var ta = document.createElement('textarea');
            ta.value = texto; document.body.appendChild(ta); ta.select();
            try { document.execCommand('copy'); } catch (e) {}
            document.body.removeChild(ta); pronto();
        }
    }

    $('copiar-pix').addEventListener('click', function () { copiar($('codigo').value, this); });
    $('copiar-boleto').addEventListener('click', function () { copiar($('linha').textContent, this); });

    // ── Cartão ───────────────────────────────────────────────────────────
    if (CFG.metodos.indexOf('card') !== -1) {
        var num = $('cc-num'), val = $('cc-val'), cvv = $('cc-cvv'), nome = $('cc-nome');
        var so = function (s) { return s.replace(/\D/g, ''); };

        num.addEventListener('input', function () {
            var d = so(this.value).slice(0, 19);
            this.value = d.replace(/(\d{4})(?=\d)/g, '$1 ').trim();
            this.classList.remove('ruim');
        });
        val.addEventListener('input', function () {
            var d = so(this.value).slice(0, 6);
            this.value = d.length >= 3 ? (d.slice(0, 2) + '/' + d.slice(2)) : d;
            this.classList.remove('ruim');
        });
        val.addEventListener('blur', function () {
            var p = this.value.split('/');
            if (p.length === 2 && p[1].length === 2) { this.value = p[0] + '/20' + p[1]; }
        });
        cvv.addEventListener('input', function () {
            this.value = so(this.value).slice(0, 4);
            this.classList.remove('ruim');
        });
        nome.addEventListener('input', function () {
            this.value = this.value.toUpperCase().replace(/[^A-ZÀ-Ü '.-]/g, '');
            this.classList.remove('ruim');
        });

        var sel = $('parcelas');
        for (var i = 1; i <= CFG.parcelas; i++) {
            var o = document.createElement('option');
            o.value = i; o.textContent = i + 'x';
            sel.appendChild(o);
        }
        if (CFG.parcelas > 1) { $('parcelas-campo').hidden = false; }

        var pronto = false;
        var s = document.createElement('script');
        s.src = 'https://js.iugu.com/v2';
        s.onload = function () {
            try {
                Iugu.setAccountID(CFG.conta);
                Iugu.setTestMode(!!CFG.teste);
                pronto = true;
            } catch (e) { mostra('card', 'erro', 'Não foi possível carregar o pagamento por cartão.'); }
        };
        s.onerror = function () { mostra('card', 'erro', 'Não foi possível carregar o pagamento por cartão.'); };
        document.head.appendChild(s);

        function valido() {
            var falhas = 0;
            [[nome, nome.value.trim().length < 3],
             [num,  so(num.value).length < 13],
             [val,  !/^\d{2}\/\d{4}$/.test(val.value)],
             [cvv,  cvv.value.length < 3]].forEach(function (p) {
                if (p[1]) { falhas++; p[0].classList.add('ruim'); }
            });
            return falhas === 0;
        }

        $('form-card').addEventListener('submit', function (ev) {
            ev.preventDefault();
            limpar('card');

            if (!pronto)   { return mostra('card', 'erro', 'Aguarde o formulário carregar.'); }
            if (!valido()) { return mostra('card', 'erro', 'Confira os campos destacados.'); }

            var botao = $('pagar-card');
            botao.disabled = true;
            mostra('card', 'info', 'Processando o pagamento…');

            Iugu.createPaymentToken(this, function (resp) {
                if (!resp || resp.errors) {
                    botao.disabled = false;
                    return mostra('card', 'erro', 'Confira os dados do cartão e tente novamente.');
                }

                pedir('card', 'card_token=' + encodeURIComponent(resp.id)
                            + '&months=' + encodeURIComponent(sel.value)).then(function (d) {
                    botao.disabled = false;

                    if (!d || !d.ok) { return mostra('card', 'erro', (d && d.error) || 'Cartão recusado.'); }
                    if (d.paid) { location.reload(); return; }

                    $('form-card').hidden = true;
                    mostra('card', 'info', d.message || 'Pagamento em análise.');
                    vigiar();
                }).catch(function () {
                    botao.disabled = false;
                    mostra('card', 'erro', 'Não foi possível concluir agora.');
                });
            });
        });
    }

    abrir(CFG.metodos[0]);
})();
</script>
</body>
</html>
