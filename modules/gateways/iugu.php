<?php

declare(strict_types=1);

/**
 * iugu.php - porta de entrada do gateway no WHMCS.
 *
 * CAMINHO NO WHMCS: /modules/gateways/iugu.php
 *
 * O WHMCS descobre um gateway pelo nome do arquivo. Toda função aqui que
 * comece com `iugu_` e tenha nome reservado (_MetaData, _config, _activate,
 * _deactivate, _upgrade, _link, _refund) é chamada pelo próprio WHMCS.
 * As demais são auxiliares deste módulo.
 *
 * O que este arquivo NÃO faz: falar com a Iugu. Isso é do IuguClient. Aqui
 * só ficam as regras de "o que o WHMCS precisa saber" e a tela que o cliente
 * vê na fatura.
 *
 * Arquivos que acompanham (todos em /modules/gateways/iugu/):
 *   config.php        constantes, versão e utilitários
 *   IuguClient.php    HTTP da API
 *   IuguInvoice.php   montagem de Pix / Boleto / Cartão
 *   IuguHelpers.php   CPF, telefone, centavos, itens
 *   IuguCharges.php   leitura e gravação da tabela mod_iugu_charges
 *   create_charge.php endpoint chamado pela tela da fatura
 *   check_status.php  "já pagou?" consultado em laço pela tela
 *   reconcile.php     conferência diária das cobranças pendentes
 *   hooks.php         hooks de fatura + painel de suporte no admin
 * E ainda /modules/gateways/callback/iugu.php, o webhook.
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

require_once __DIR__ . '/iugu/config.php';
require_once __DIR__ . '/iugu/IuguClient.php';
require_once __DIR__ . '/iugu/IuguHelpers.php';
require_once __DIR__ . '/iugu/IuguInvoice.php';
require_once __DIR__ . '/iugu/IuguCharges.php';

use WHMCS\Database\Capsule;

// ═══════════════════════════════════════════════════════════════════════════
// METADATA - o que o WHMCS mostra e assume sobre o gateway
// ═══════════════════════════════════════════════════════════════════════════

/**
 * DisableLocalCreditCardInput = true: o WHMCS não exibe o formulário de cartão
 * dele. O número do cartão nunca chega ao servidor de quem instala - quem
 * captura é a iugu.js, direto no navegador.
 *
 * TokenisedStorage = false: este módulo NÃO guarda cartão para cobrança
 * automática, e por isso não declara que guarda. Anunciar true sem
 * implementar iugu_storeremote/iugu_capture faria o WHMCS oferecer uma
 * recorrência no cartão que nunca aconteceria. Está no roteiro, ver README.
 */
function iugu_MetaData(): array
{
    return [
        'DisplayName'                 => IUGU_NAME,
        'APIVersion'                  => '1.1',
        'DisableLocalCreditCardInput' => true,
        'TokenisedStorage'            => false,
    ];
}

// ═══════════════════════════════════════════════════════════════════════════
// CONFIG - os campos da tela Configurações ▸ Pagamentos ▸ Gateways
// ═══════════════════════════════════════════════════════════════════════════

/**
 * Cada chave vira uma linha na tela e um registro em tbl_paymentgateways.
 * As descrições são o manual: quem instalar não deveria precisar abrir o
 * README para preencher.
 *
 * Regra que vale para o módulo todo: nenhum campo vem preenchido com endereço
 * de terceiro. Campo em branco = recurso desligado, nunca um endereço chutado.
 */
function iugu_config(): array
{
    // O verpix fica na RAIZ DO WHMCS, junto do init.php - não no site. Então
    // o campo já nasce preenchido com o endereço da própria instalação, sem
    // ninguém precisar digitar nada.
    $verpix = iugu_system_url() . '/verpix.php';

    return [
        'FriendlyName' => [
            'Type'  => 'System',
            'Value' => IUGU_NAME,
        ],

        // ═══ 1. CONEXÃO COM A IUGU ═══════════════════════════════════════
        // Tudo que este bloco pede vem do painel da Iugu. Sem ele o módulo
        // não faz nada. O resto da tela é decisão de negócio, não conexão.
        'api_token_live' => [
            'FriendlyName' => 'Token de API (produção)',
            'Type'         => 'password',
            'Size'         => '50',
            'Description'  => 'Painel da Iugu ▸ Configurações ▸ Conta ▸ Tokens de API.',
        ],
        'api_token_test' => [
            'FriendlyName' => 'Token de API (teste)',
            'Type'         => 'password',
            'Size'         => '50',
            'Description'  => 'Usado só quando o Modo está em test.',
        ],
        'account_id' => [
            'FriendlyName' => 'ID da conta',
            'Type'         => 'text',
            'Size'         => '40',
            'Description'  => 'Painel da Iugu ▸ Configurações ▸ Conta. Obrigatório para cartão.',
        ],
        'mode' => [
            'FriendlyName' => 'Modo',
            'Type'         => 'dropdown',
            'Options'      => 'live,test',
            'Default'      => 'live',
            'Description'  => 'live usa o token de produção; test usa o de teste.',
        ],

        // ═══ 2. FORMAS DE PAGAMENTO ══════════════════════════════════════
        'enable_pix' => [
            'FriendlyName' => 'Aceitar Pix',
            'Type'         => 'yesno',
            'Default'      => 'yes',
            'Description'  => 'Precisa estar habilitado na conta Iugu.',
        ],
        'enable_boleto' => [
            'FriendlyName' => 'Aceitar boleto',
            'Type'         => 'yesno',
            'Default'      => 'yes',
            'Description'  => 'Exige CPF ou CNPJ válido no cadastro do cliente.',
        ],
        'enable_card' => [
            'FriendlyName' => 'Aceitar cartão',
            'Type'         => 'yesno',
            'Default'      => 'no',
            'Description'  => 'Exige o ID da conta preenchido.',
        ],

        // ═══ 3. PRAZOS E PARCELAS ════════════════════════════════════════
        'pix_expires_in' => [
            'FriendlyName' => 'Pix vale por',
            'Type'         => 'dropdown',
            'Options'      => '1d,2d,3d,7d,15d,30d',
            'Default'      => '3d',
            'Description'  => 'Enquanto valer, o mesmo código é reaproveitado.',
        ],
        'card_max_installments' => [
            'FriendlyName' => 'Parcelas no cartão',
            'Type'         => 'dropdown',
            'Options'      => '1,2,3,4,5,6,7,8,9,10,11,12',
            'Default'      => '1',
            'Description'  => 'Máximo oferecido ao cliente. Os juros são definidos no painel da Iugu.',
        ],

        // ═══ 4. MULTA E JUROS ════════════════════════════════════════════
        'late_fee_pct' => [
            'FriendlyName' => 'Multa por atraso (%)',
            'Type'         => 'text',
            'Size'         => '5',
            'Default'      => '0',
            'Description'  => 'Aplicada depois do vencimento. 0 desliga.',
        ],
        'monthly_interest_pct' => [
            'FriendlyName' => 'Juros ao mês (%)',
            'Type'         => 'text',
            'Size'         => '5',
            'Default'      => '0',
            'Description'  => 'Proporcional por dia de atraso. 0 desliga.',
        ],

        // ═══ 5. COMPORTAMENTO ════════════════════════════════════════════
        'ignore_due_email' => [
            'FriendlyName' => 'A Iugu não envia e-mail',
            'Type'         => 'yesno',
            'Default'      => 'yes',
            'Description'  => 'Impede a Iugu de mandar e-mail de cobrança ao seu cliente.',
        ],
        'tax_id_field_name' => [
            'FriendlyName' => 'Campo do CPF/CNPJ',
            'Type'         => 'text',
            'Size'         => '40',
            'Default'      => 'CPF/CNPJ',
            'Description'  => 'Campo personalizado consultado quando o documento nativo está vazio.',
        ],
        // O verpix é a página pública de pagamento do módulo - a que se manda
        // por WhatsApp. O endereço é configurável, e não fixo no código: cada
        // instalação aponta para o seu próprio.
        'public_pay_url' => [
            'FriendlyName' => 'Link do verpix',
            'Type'         => 'text',
            'Size'         => '60',
            'Default'      => $verpix,
            'Description'  => 'Página de pagamento que você manda ao cliente por WhatsApp. O módulo '
                . 'acrescenta o número da fatura e um token de acesso; sem o token a página não abre.'
                . '<br>Terminando em <code>.php</code>, o link sai como <code>…/verpix.php?i=20&amp;t=…</code> '
                . 'e funciona sem configurar nada. Tirando o <code>.php</code>, sai curto '
                . '(<code>…/verpix/20/…</code>) - mas exige uma regra de reescrita no <code>.htaccess</code>, '
                . 'que está no arquivo INSTALACAO.md.'
                . '<br>Em branco, o link gravado é o da página da própria Iugu.',
        ],
        'enable_reconcile' => [
            'FriendlyName' => 'Conferência diária',
            'Type'         => 'yesno',
            'Default'      => 'yes',
            'Description'  => 'No cron diário, baixa as cobranças pagas que o aviso não confirmou.',
        ],
        'debug_log' => [
            'FriendlyName' => 'Log de diagnóstico',
            'Type'         => 'yesno',
            'Default'      => 'no',
            'Description'  => 'Grava o passo a passo em arquivo no diretório temporário do servidor.',
        ],
    ];
}

// ═══════════════════════════════════════════════════════════════════════════
// ACTIVATE / DEACTIVATE / UPGRADE
// ═══════════════════════════════════════════════════════════════════════════

/**
 * Cria a tabela do módulo no clique em Ativar.
 *
 * Uma linha por (fatura, método). Guardar o copia-e-cola e a linha digitável
 * aqui é o que permite reenviar a cobrança sem gastar chamada de API e sem
 * trocar o código que o cliente já recebeu.
 */
function iugu_activate(): array
{
    try {
        if (Capsule::schema()->hasTable(IUGU_TABLE)) {
            // Reativação, ou atualização vinda de uma versão anterior: a tabela
            // já existe e é preservada. Só conferimos se falta alguma coluna.
            iugu_ensure_schema();
            return ['status' => 'success', 'description' => 'Tabela ja existia; historico preservado.'];
        }

        Capsule::schema()->create(IUGU_TABLE, function ($t) {
            $t->increments('id');
            $t->unsignedInteger('whmcs_invoice_id');
            $t->unsignedInteger('whmcs_client_id');
            $t->string('iugu_invoice_id', 64);
            $t->string('iugu_charge_id', 64)->nullable();
            $t->enum('method', ['pix', 'boleto', 'card', 'open']);
            $t->enum('status', ['pending', 'in_analysis', 'paid', 'canceled', 'expired', 'refunded', 'failed'])
              ->default('pending');
            // O QR vem em base64 e passa de 64 KB em algumas contas.
            $t->mediumText('qrcode_base64')->nullable();
            $t->text('qrcode_text')->nullable();
            $t->string('bank_slip_barcode', 80)->nullable();
            $t->string('bank_slip_pdf', 255)->nullable();
            $t->string('secure_url', 255)->nullable();
            $t->unsignedInteger('amount_cents')->default(0);
            $t->unsignedInteger('fees_cents')->default(0);
            $t->dateTime('expires_at')->nullable();
            $t->dateTime('paid_at')->nullable();
            $t->timestamps();

            $t->index('whmcs_invoice_id', 'idx_whmcs_invoice');
            $t->index('iugu_invoice_id', 'idx_iugu_invoice');
            $t->index(['status', 'method'], 'idx_status_method');
            $t->index('expires_at', 'idx_expires');
        });

        return ['status' => 'success', 'description' => 'Tabela ' . IUGU_TABLE . ' criada.'];
    } catch (\Throwable $e) {
        return ['status' => 'error', 'description' => 'Erro ao criar a tabela: ' . $e->getMessage()];
    }
}

/**
 * Desativar NÃO apaga nada.
 *
 * A tabela é o histórico de quem pagou o quê e por qual meio. Quem quiser
 * mesmo remover roda o DROP TABLE à mão - está documentado no README.
 */
function iugu_deactivate(): array
{
    return [
        'status'      => 'success',
        'description' => 'Gateway desativado. A tabela ' . IUGU_TABLE . ' foi preservada com o historico.',
    ];
}

/**
 * Confere se a tabela tem todas as colunas que a versão atual usa.
 *
 * O WHMCS chama `_upgrade()` em módulo addon, **não em gateway** - declarar
 * `iugu_upgrade()` daria a impressão de que a migração acontece sozinha, e
 * não aconteceria nunca. Então a conferência é feita de dois jeitos que
 * realmente rodam: na ativação e uma vez por dia, pelo cron (hooks.php).
 *
 * Só acrescenta o que faltar. Nunca apaga coluna nem dado.
 */
function iugu_ensure_schema(): void
{
    try {
        if (!Capsule::schema()->hasTable(IUGU_TABLE)) {
            return; // quem cria é iugu_activate()
        }

        // Colunas acrescentadas depois da 1.0. A lista cresce a cada versão
        // que mexer no esquema; conferir é barato e roda pouquíssimas vezes.
        $faltando = [];

        if (!Capsule::schema()->hasColumn(IUGU_TABLE, 'bank_slip_pdf')) {
            $faltando['bank_slip_pdf'] = static fn ($t) => $t->string('bank_slip_pdf', 255)->nullable();
        }
        if (!Capsule::schema()->hasColumn(IUGU_TABLE, 'fees_cents')) {
            $faltando['fees_cents'] = static fn ($t) => $t->unsignedInteger('fees_cents')->default(0);
        }

        if ($faltando === []) {
            return;
        }

        Capsule::schema()->table(IUGU_TABLE, function ($t) use ($faltando) {
            foreach ($faltando as $add) {
                $add($t);
            }
        });

        logActivity('Iugu: colunas acrescentadas em ' . IUGU_TABLE . ': ' . implode(', ', array_keys($faltando)));
    } catch (\Throwable $e) {
        logActivity('Iugu: falha ao conferir o esquema: ' . $e->getMessage());
    }
}

// ═══════════════════════════════════════════════════════════════════════════
// _link - a tela que o cliente vê na fatura
// ═══════════════════════════════════════════════════════════════════════════

/**
 * Devolve o HTML mostrado na página da fatura (viewinvoice.php).
 *
 * REGRA DESTA TELA: **nenhum CSS próprio, nenhuma classe inventada.** Tudo é
 * classe que o WHMCS já carrega na área do cliente (Bootstrap 3):
 * nav-pills, tab-content, tab-pane, form-group, form-control, input-lg,
 * btn-group-justified, img-responsive, alert, has-error, hidden.
 *
 * O motivo é prático: com CSS fixo o módulo fica bonito num tema e quebrado
 * em todos os outros, e não acompanha tema escuro. Usando só as classes do
 * WHMCS, a tela herda o visual de quem instalar, qualquer que seja o tema.
 *
 * A cobrança só nasce quando o cliente escolhe o meio e o JS chama
 * create_charge.php - abrir a fatura não cria nada na Iugu. E a aba troca no
 * clique, antes da resposta do servidor, para a tela nunca ficar presa quando
 * a API recusar.
 *
 * O token anti-CSRF é calculado aqui e viaja no HTML; create_charge.php o
 * confere. Ver iugu_form_token() em config.php.
 */
function iugu_link(array $params): string
{
    $invoiceId = (int) ($params['invoiceid'] ?? 0);
    $clientId  = (int) ($params['clientdetails']['userid'] ?? $params['userid'] ?? 0);
    $methods   = iugu_enabled_methods($params);

    if ($methods === []) {
        return '<div class="alert alert-warning">Nenhuma forma de pagamento está disponível no momento. '
            . 'Entre em contato com o suporte.</div>';
    }

    try {
        $token = iugu_form_token($invoiceId, $clientId, iugu_api_token($params));
    } catch (\Throwable $e) {
        return '<div class="alert alert-warning">O meio de pagamento ainda não está configurado. '
            . 'Entre em contato com o suporte.</div>';
    }

    $base      = rtrim((string) $params['systemurl'], '/');
    $accountId = trim((string) ($params['account_id'] ?? ''));

    /**
     * Classe do botão, escolhida pelo tema da área do cliente.
     *
     * Não dá para mandar as duas juntas. O tema `six` é Bootstrap 3 e usa
     * `btn-default`; o `nexus` e o `twenty-one` são Bootstrap 4/5 e usam
     * `btn-outline-secondary`. Só que o nexus ainda define
     * `.btn-default:hover{background-color:#fff!important}` - então, com as
     * duas classes no mesmo botão, passar o mouse deixava o botão branco no
     * branco e ele sumia da tela. Conferido no CSS do tema e reproduzido.
     *
     * Tema desconhecido cai no Bootstrap 4/5, que é o padrão do WHMCS atual.
     */
    $temaBootstrap3 = in_array(
        strtolower((string) (\WHMCS\Config\Setting::getValue('Template') ?? '')),
        ['six', 'five', 'default'],
        true
    );
    $btn = $temaBootstrap3 ? 'btn-default' : 'btn-outline-secondary';

    // Cartão só aparece com ID da conta: sem ele a iugu.js não tokeniza, e um
    // botão que não funciona é pior do que botão nenhum.
    $methods = array_values(array_filter(
        $methods,
        static fn ($m) => $m !== 'card' || $accountId !== ''
    ));

    $cfg = json_encode([
        'invoiceId'  => $invoiceId,
        'token'      => $token,
        'createUrl'  => $base . '/modules/gateways/iugu/create_charge.php',
        'statusUrl'  => $base . '/modules/gateways/iugu/check_status.php',
        'accountId'  => $accountId,
        'testMode'   => ($params['mode'] ?? 'live') === 'test',
        'methods'    => $methods,
        'maxParcels' => max(1, (int) ($params['card_max_installments'] ?? 1)),
        'btn'        => $btn,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

    ob_start();
    ?>
<div id="iugu-pay">

    <!-- As abas são BOTÕES, não nav-pills: o tema nexus (e o twenty-one) não
         estiliza nav-pills, e a barra saía como texto corrido. Botão em
         grade é o que os três temas do WHMCS desenham igual.
         `form-group` no lugar de margem inline dá o espaço embaixo. -->
    <div class="row form-group" id="iugu-abas"></div>

    <div class="tab-content">

        <!-- Pix --------------------------------------------------------->
        <div class="tab-pane active" id="iugu-painel-pix">
            <div class="iugu-estado"></div>
            <div class="iugu-conteudo d-none hidden">
                <p class="text-muted text-center">Escaneie pelo aplicativo do seu banco ou copie o código.</p>
                <div class="form-group">
                    <img class="img-responsive img-fluid center-block mx-auto d-block" id="iugu-qr" alt="Código Pix" width="250" height="250">
                </div>
                <div class="form-group">
                    <textarea class="form-control" id="iugu-codigo" readonly rows="3"></textarea>
                </div>
                <a href="#" role="button" class="btn <?= $btn ?> btn-block btn-lg" id="iugu-copiar-pix">Copiar código Pix</a>
                <p class="text-muted text-center"><small>A tela atualiza sozinha quando o pagamento cair.</small></p>
            </div>
        </div>

        <!-- Boleto ------------------------------------------------------>
        <div class="tab-pane" id="iugu-painel-boleto">
            <div class="iugu-estado"></div>
            <div class="iugu-conteudo d-none hidden">
                <img class="img-responsive img-fluid center-block mx-auto d-block d-none hidden" id="iugu-barras" alt="Código de barras do boleto">
                <div class="form-group">
                    <input type="text" class="form-control input-lg form-control-lg text-center" id="iugu-linha" readonly>
                </div>
                <!-- Duas colunas iguais em vez de btn-group-justified: essa
                     classe não existe em nenhum dos temas do WHMCS. Com
                     btn-block as duas ficam da mesma largura E altura. -->
                <div class="row">
                    <div class="col-xs-6 col-6">
                        <a href="#" role="button" class="btn <?= $btn ?> btn-block btn-lg"
                           id="iugu-copiar-boleto">Copiar linha digitável</a>
                    </div>
                    <div class="col-xs-6 col-6">
                        <a href="#" role="button" class="btn <?= $btn ?> btn-block btn-lg"
                           id="iugu-pdf" target="_blank" rel="noopener">Boleto em PDF</a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Cartão ------------------------------------------------------>
        <div class="tab-pane" id="iugu-painel-card">
            <div class="iugu-estado"></div>
            <form id="iugu-form-cartao" autocomplete="on" novalidate>
                <div class="form-group">
                    <label class="control-label" for="iugu-cc-nome">Nome impresso no cartão</label>
                    <input type="text" class="form-control input-lg form-control-lg" id="iugu-cc-nome" data-iugu="full_name"
                           autocomplete="cc-name" autocapitalize="characters"
                           maxlength="60" placeholder="COMO ESTÁ NO CARTÃO" required>
                </div>
                <div class="form-group">
                    <label class="control-label" for="iugu-cc-num">Número do cartão</label>
                    <input type="text" class="form-control input-lg form-control-lg" id="iugu-cc-num" data-iugu="number"
                           autocomplete="cc-number" inputmode="numeric"
                           maxlength="23" placeholder="0000 0000 0000 0000" required>
                </div>
                <div class="row">
                    <div class="col-xs-6 col-6">
                        <div class="form-group">
                            <label class="control-label" for="iugu-cc-val">Validade</label>
                            <input type="text" class="form-control input-lg form-control-lg" id="iugu-cc-val" data-iugu="expiration"
                                   autocomplete="cc-exp" inputmode="numeric"
                                   maxlength="7" placeholder="MM/AAAA" required>
                        </div>
                    </div>
                    <div class="col-xs-6 col-6">
                        <div class="form-group">
                            <label class="control-label" for="iugu-cc-cvv">Código de segurança</label>
                            <input type="text" class="form-control input-lg form-control-lg" id="iugu-cc-cvv" data-iugu="verification_value"
                                   autocomplete="cc-csc" inputmode="numeric"
                                   maxlength="4" placeholder="CVV" required>
                        </div>
                    </div>
                </div>
                <div class="form-group d-none hidden" id="iugu-parcelas-campo">
                    <label class="control-label" for="iugu-parcelas">Parcelas</label>
                    <select class="form-control input-lg form-control-lg" id="iugu-parcelas"></select>
                </div>
                <button type="submit" class="btn <?= $btn ?> btn-block btn-lg" id="iugu-pagar-cartao">Pagar com cartão</button>
            </form>
        </div>
    </div>
</div>

<script>
(function () {
    var CFG = <?= $cfg ?>;

    var $ = function (id) { return document.getElementById(id); };

    var ROTULO = { pix: 'Pix', boleto: 'Boleto', card: 'Cartão' };

    /* Esconder e mostrar usando as DUAS convenções do WHMCS ao mesmo tempo:
       `hidden` é do tema six (Bootstrap 3) e `d-none` é do nexus e do
       twenty-one (Bootstrap 4/5). Nenhum tema tem as duas, e cada um ignora
       a que não conhece - então a tela funciona em qualquer um deles sem CSS
       nosso. Vale o mesmo para os pares input-lg/form-control-lg,
       img-responsive/img-fluid e col-xs-6/col-6 na marcação acima. */
    function esconder(el) { el.classList.add('hidden', 'd-none'); }
    function mostrar(el)  { el.classList.remove('hidden', 'd-none'); }
    function escondido(el) { return el.classList.contains('hidden') || el.classList.contains('d-none'); }

    // ── Estado de cada aba (gerando / erro / vazio) ───────────────────────
    function painel(m)   { return $('iugu-painel-' + m); }
    function estado(m)   { return painel(m).querySelector('.iugu-estado'); }
    function conteudo(m) { return painel(m).querySelector('.iugu-conteudo'); }

    function limpar(m) { estado(m).innerHTML = ''; }

    function carregando(m, texto) {
        estado(m).innerHTML = '<div class="alert alert-info">' + texto + '</div>';
    }

    function erro(m, texto) {
        estado(m).innerHTML = '<div class="alert alert-danger">'
            + (texto || 'Não foi possível concluir agora. Tente novamente em instantes.')
            + '</div>';
    }

    function aviso(m, texto) {
        estado(m).innerHTML = '<div class="alert alert-info">' + texto + '</div>';
    }

    // ── Abas ─────────────────────────────────────────────────────────────
    var abas = $('iugu-abas');
    var botoes = {};
    var atual = null;

    /* A aba escolhida ganha `active`, que Bootstrap 3 e 5 desenham como botão
       pressionado. A classe base de todos vem do PHP (CFG.btn), escolhida
       pelo tema - ver o comentário em iugu_link().

       Nenhum botão desta tela usa btn-primary: no nexus ele é redefinido como
       background:var(--bg-inverted), variável que não existe em nenhum CSS
       do tema, e o botão sairia branco no branco. */
    function pintar(botao, ativo) {
        botao.classList.toggle('active', ativo);
        botao.setAttribute('aria-pressed', ativo ? 'true' : 'false');
    }

    /* Troca de aba acontece NA HORA do clique, nunca depois da resposta. */
    function abrir(m) {
        if (atual === m) { return; }
        atual = m;

        CFG.methods.forEach(function (x) {
            painel(x).classList.toggle('active', x === m);
            pintar(botoes[x], x === m);
        });

        if (m === 'card') { return; }               // é formulário, não busca nada
        if (!escondido(conteudo(m))) { return; }    // já carregado

        gerar(m);
    }

    /* 1 forma ocupa a linha toda, 2 ocupam metade, 3 um terço. */
    var largura = Math.floor(12 / Math.max(1, CFG.methods.length));

    CFG.methods.forEach(function (m) {
        var coluna = document.createElement('div');
        coluna.className = 'col-xs-' + largura + ' col-' + largura;

        var b = document.createElement('button');
        b.type = 'button';
        b.className = 'btn ' + CFG.btn + ' btn-block btn-lg';
        b.textContent = ROTULO[m];
        b.addEventListener('click', function () { abrir(m); });
        pintar(b, false);

        coluna.appendChild(b);
        abas.appendChild(coluna);
        botoes[m] = b;
    });

    /* O <textarea> cresce até caber o código inteiro, sem barra de rolagem.
       Feito pelo atributo rows, não por altura fixa em CSS. */
    function ajustarAltura(ta) {
        ta.rows = 2;
        var limite = 0;
        while (ta.scrollHeight > ta.clientHeight && limite++ < 14) {
            ta.rows++;
        }
    }

    // ── Conversa com o servidor ──────────────────────────────────────────
    function post(acao, extra) {
        var corpo = 'action=' + encodeURIComponent(acao)
                  + '&invoice_id=' + encodeURIComponent(CFG.invoiceId)
                  + '&token=' + encodeURIComponent(CFG.token);

        for (var k in (extra || {})) {
            corpo += '&' + encodeURIComponent(k) + '=' + encodeURIComponent(extra[k]);
        }

        return fetch(CFG.createUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: corpo,
            credentials: 'same-origin'
        }).then(function (r) { return r.json(); });
    }

    function gerar(m) {
        carregando(m, m === 'pix' ? 'Gerando o código Pix…' : 'Gerando o boleto…');

        post(m, {}).then(function (d) {
            if (!d || !d.ok) { return erro(m, d && d.error); }
            if (d.paid) { location.reload(); return; }

            if (m === 'pix') {
                $('iugu-qr').src = d.pix.qrcode;
                $('iugu-codigo').value = d.pix.qrcode_text;
            } else {
                $('iugu-linha').value = d.boleto.digitable_line;

                var barras = $('iugu-barras');
                if (d.boleto.barcode_url) {
                    barras.src = d.boleto.barcode_url;
                    mostrar(barras);
                } else {
                    esconder(barras);
                }

                var pdf = $('iugu-pdf');
                if (d.boleto.pdf_url) { pdf.href = d.boleto.pdf_url; mostrar(pdf); }
                else { esconder(pdf); }
            }

            limpar(m);
            mostrar(conteudo(m));

            if (m === 'pix') { ajustarAltura($('iugu-codigo')); }

            vigiar();
        }).catch(function () { erro(m); });
    }

    // ── "Já pagou?" ──────────────────────────────────────────────────────
    var laco = null;
    function vigiar() {
        if (laco) { return; }
        laco = setInterval(function () {
            fetch(CFG.statusUrl + '?invoice_id=' + CFG.invoiceId, { credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (j) { if (j && j.paid) { clearInterval(laco); location.reload(); } })
                .catch(function () { /* rede oscilando: tenta no próximo ciclo */ });
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

    $('iugu-copiar-pix').addEventListener('click', function (ev) {
        ev.preventDefault();
        copiar($('iugu-codigo').value, this);
    });
    $('iugu-copiar-boleto').addEventListener('click', function (ev) {
        ev.preventDefault();
        copiar($('iugu-linha').value, this);
    });

    // ── Cartão ───────────────────────────────────────────────────────────
    if (CFG.methods.indexOf('card') !== -1) {
        var num  = $('iugu-cc-num');
        var val  = $('iugu-cc-val');
        var cvv  = $('iugu-cc-cvv');
        var nome = $('iugu-cc-nome');

        var so = function (s) { return s.replace(/\D/g, ''); };

        /* Marcar campo errado, nas duas convenções: has-error no form-group
           (tema six) e is-invalid no próprio campo (nexus, twenty-one). */
        function marcar(el, ruim) {
            var grupo = el.closest('.form-group');
            if (grupo) { grupo.classList.toggle('has-error', !!ruim); }
            el.classList.toggle('is-invalid', !!ruim);
        }

        num.addEventListener('input', function () {
            var d = so(this.value).slice(0, 19);
            this.value = d.replace(/(\d{4})(?=\d)/g, '$1 ').trim();
            marcar(this, false);
        });

        /* Validade: a barra entra sozinha, e o ano vira 4 dígitos -
           é o formato MM/AAAA que a iugu.js espera. */
        val.addEventListener('input', function () {
            var d = so(this.value).slice(0, 6);
            this.value = d.length >= 3 ? (d.slice(0, 2) + '/' + d.slice(2)) : d;
            marcar(this, false);
        });
        val.addEventListener('blur', function () {
            var p = this.value.split('/');
            if (p.length === 2 && p[1].length === 2) {   // 12/30 → 12/2030
                this.value = p[0] + '/20' + p[1];
            }
        });

        cvv.addEventListener('input', function () {
            this.value = so(this.value).slice(0, 4);
            marcar(this, false);
        });

        nome.addEventListener('input', function () {
            this.value = this.value.toUpperCase().replace(/[^A-ZÀ-Ü '.-]/g, '');
            marcar(this, false);
        });

        var sel = $('iugu-parcelas');
        for (var i = 1; i <= CFG.maxParcels; i++) {
            var o = document.createElement('option');
            o.value = i;
            o.textContent = i + 'x';
            sel.appendChild(o);
        }
        if (CFG.maxParcels > 1) { mostrar($('iugu-parcelas-campo')); }

        /* A iugu.js vem do domínio da própria Iugu. É obrigatório: é ela que
           troca o cartão por um token sem o número passar pelo servidor. */
        var pronto = false;
        var s = document.createElement('script');
        s.src = 'https://js.iugu.com/v2';
        s.onload = function () {
            try {
                Iugu.setAccountID(CFG.accountId);
                Iugu.setTestMode(!!CFG.testMode);
                pronto = true;
            } catch (e) { erro('card', 'Não foi possível carregar o pagamento por cartão.'); }
        };
        s.onerror = function () { erro('card', 'Não foi possível carregar o pagamento por cartão.'); };
        document.head.appendChild(s);

        /* Confere o básico aqui para o cliente não gastar uma ida ao servidor
           por causa de um campo em branco. */
        function valido() {
            var falhas = 0;
            [[nome, nome.value.trim().length < 3],
             [num,  so(num.value).length < 13],
             [val,  !/^\d{2}\/\d{4}$/.test(val.value)],
             [cvv,  cvv.value.length < 3]].forEach(function (par) {
                if (par[1]) { falhas++; }
                marcar(par[0], par[1]);
            });
            return falhas === 0;
        }

        $('iugu-form-cartao').addEventListener('submit', function (ev) {
            ev.preventDefault();
            limpar('card');

            if (!pronto)  { return erro('card', 'Aguarde o formulário terminar de carregar.'); }
            if (!valido()) { return erro('card', 'Confira os campos destacados.'); }

            var botao = $('iugu-pagar-cartao');
            botao.disabled = true;
            carregando('card', 'Processando o pagamento…');

            Iugu.createPaymentToken(this, function (resp) {
                if (!resp || resp.errors) {
                    botao.disabled = false;
                    return erro('card', 'Confira os dados do cartão e tente novamente.');
                }

                post('card', { card_token: resp.id, months: sel.value }).then(function (d) {
                    botao.disabled = false;

                    if (!d || !d.ok) { return erro('card', d && d.error); }
                    if (d.paid) { location.reload(); return; }

                    /* Antifraude analisando: não é recusa nem aprovação. */
                    esconder($('iugu-form-cartao'));
                    aviso('card', d.message || 'Pagamento em análise. Esta tela atualiza sozinha quando for aprovado.');
                    vigiar();
                }).catch(function () {
                    botao.disabled = false;
                    erro('card');
                });
            });
        });
    }

    // Abre a primeira forma de pagamento já na chegada.
    abrir(CFG.methods[0]);
})();
</script>
    <?php
    return (string) ob_get_clean();
}


// ═══════════════════════════════════════════════════════════════════════════
// _refund - botão Estornar da fatura no admin
// ═══════════════════════════════════════════════════════════════════════════

/**
 * Estorna na Iugu o valor informado pelo admin.
 *
 * transid costuma ser o ID da fatura na Iugu, gravado na baixa. Quando vier
 * vazio (baixa manual antiga), procuramos a cobrança paga da fatura.
 */
function iugu_refund(array $params): array
{
    try {
        $client = iugu_make_client($params);

        $iuguId = trim((string) ($params['transid'] ?? ''));

        if ($iuguId === '') {
            $iuguId = (string) (Capsule::table(IUGU_TABLE)
                ->where('whmcs_invoice_id', (int) ($params['invoiceid'] ?? 0))
                ->where('status', 'paid')
                ->orderBy('paid_at', 'desc')
                ->value('iugu_invoice_id') ?? '');
        }

        if ($iuguId === '') {
            return ['status' => 'error', 'rawdata' => ['msg' => 'Cobranca da Iugu nao localizada para esta fatura.']];
        }

        $res = $client->refundInvoice($iuguId, IuguHelpers::toCents((float) $params['amount']));

        if ($res['ok']) {
            IuguCharges::setStatusByIuguId($iuguId, 'refunded');
            return ['status' => 'success', 'rawdata' => $res['body'], 'transid' => $iuguId];
        }

        return [
            'status'        => 'declined',
            'rawdata'       => ['error' => $res['error']],
            'declinereason' => (string) ($res['error'] ?? 'Erro nao identificado.'),
        ];
    } catch (\Throwable $e) {
        return ['status' => 'error', 'rawdata' => ['exception' => $e->getMessage()]];
    }
}

// ═══════════════════════════════════════════════════════════════════════════
// Auxiliares - usados pelos endpoints, pelo webhook e pelos hooks
// ═══════════════════════════════════════════════════════════════════════════

/** Token de API em uso, conforme o Modo. Lança se não estiver configurado. */
function iugu_api_token(array $params): string
{
    $isTest = ($params['mode'] ?? 'live') === 'test';
    $token  = trim((string) ($isTest ? ($params['api_token_test'] ?? '') : ($params['api_token_live'] ?? '')));

    if ($token === '') {
        throw new RuntimeException('Token da Iugu nao configurado para o modo ' . ($isTest ? 'teste' : 'producao') . '.');
    }

    return $token;
}

/** Monta o cliente HTTP a partir da configuração do gateway. */
function iugu_make_client(array $params): IuguClient
{
    return new IuguClient(
        apiToken:  iugu_api_token($params),
        accountId: trim((string) ($params['account_id'] ?? '')) ?: null,
        testMode:  ($params['mode'] ?? 'live') === 'test',
    );
}

/**
 * Formas de pagamento ligadas na configuração.
 *
 * Campos yesno do WHMCS chegam como 'on' quando marcados e '' quando não.
 *
 * @return string[] Ex.: ['pix','boleto']
 */
function iugu_enabled_methods(array $params): array
{
    $out = [];

    if (($params['enable_pix'] ?? '') === 'on')    { $out[] = 'pix'; }
    if (($params['enable_boleto'] ?? '') === 'on') { $out[] = 'boleto'; }
    if (($params['enable_card'] ?? '') === 'on')   { $out[] = 'card'; }

    return $out;
}

/** Endereço que a Iugu deve chamar quando a fatura mudar de status. */
function iugu_webhook_url(array $params): string
{
    return rtrim((string) $params['systemurl'], '/') . '/modules/gateways/callback/iugu.php';
}

/** Para onde a Iugu manda o cliente depois de pagar na página dela. */
function iugu_return_url(array $params, int $invoiceId): string
{
    return rtrim((string) $params['systemurl'], '/') . '/viewinvoice.php?id=' . $invoiceId;
}

/**
 * Monta o link do verpix para esta fatura - com o token de acesso.
 *
 * O link leva um token assinado: sem ele a página responde 404 e não confirma
 * nem que a fatura existe. Por isso o formato é "base?i=NÚMERO&t=TOKEN".
 *
 * Base vazia, nenhum link é gravado - o módulo não inventa endereço.
 */
function iugu_pay_link(array $params, int $invoiceId): string
{
    $base = trim((string) ($params['public_pay_url'] ?? ''));

    if ($base === '') {
        return '';
    }

    try {
        $token = iugu_verpix_token($invoiceId, iugu_api_token($params));
    } catch (\Throwable $e) {
        return ''; // sem credencial não há token, e link sem token não abre
    }

    // Aponta para um arquivo (.php) ou já traz query string: o link vai com
    // parâmetros, e funciona em qualquer servidor, sem configurar nada.
    //     https://seu-whmcs/verpix.php?i=20&t=<token>
    if (str_contains($base, '?') || str_ends_with(strtolower($base), '.php')) {
        return $base
            . (str_contains($base, '?') ? '&' : '?')
            . 'i=' . $invoiceId
            . '&t=' . $token;
    }

    // Não termina em .php: o dono quer o link curto. Exige uma regra de
    // reescrita no .htaccess - está em INSTALACAO.md.
    //     https://seu-whmcs/verpix/20/<token>
    return rtrim($base, '/') . '/' . $invoiceId . '/' . $token;
}

/**
 * Reúne tudo que a Iugu precisa saber sobre uma fatura do WHMCS.
 *
 * Centraliza aqui a busca de cliente, documento e itens porque os quatro
 * caminhos que emitem cobrança (tela do cliente, hook de criação, hook de
 * lembrete e hook de alteração de valor) precisam exatamente do mesmo pacote.
 *
 * @return array{ok:bool,error?:string,args?:array,client_id?:int,amount_cents?:int,cpf_ok?:bool}
 */
function iugu_build_charge_args(array $gateway, int $invoiceId): array
{
    try {
        $inv = Capsule::table('tblinvoices')->where('id', $invoiceId)->first();
    } catch (\Throwable $e) {
        return ['ok' => false, 'error' => 'Falha ao ler a fatura.'];
    }

    if (!$inv) {
        return ['ok' => false, 'error' => 'Fatura não encontrada.'];
    }

    $clientId = (int) $inv->userid;

    $cd = localAPI('GetClientsDetails', ['clientid' => $clientId, 'stats' => false]);
    if (($cd['result'] ?? '') !== 'success') {
        return ['ok' => false, 'error' => 'Cadastro do cliente não encontrado.'];
    }

    $cpf   = IuguHelpers::findClientDocument($cd, (string) ($gateway['tax_id_field_name'] ?? 'CPF/CNPJ'));
    $cpfOk = IuguHelpers::isValidCpfCnpj($cpf);

    $invData = localAPI('GetInvoice', ['invoiceid' => $invoiceId]);
    $items   = IuguHelpers::buildItems($invData['items']['item'] ?? []);

    if ($items === []) {
        return ['ok' => false, 'error' => 'Fatura sem itens cobráveis.'];
    }

    $payer = IuguHelpers::buildPayer($cd['client'] ?? $cd, $cpf);

    return [
        'ok'           => true,
        'client_id'    => $clientId,
        'cpf_ok'       => $cpfOk,
        'amount_cents' => (int) array_sum(array_column($items, 'price_cents')),
        'args'         => [
            'whmcs_invoice_id'     => $invoiceId,
            'email'                => (string) ($cd['email'] ?? $cd['client']['email'] ?? ''),
            'payer'                => $payer,
            'items'                => $items,
            // A Iugu recusa vencimento no passado; para fatura atrasada,
            // o vencimento vira hoje e a multa/juros já vão no payload.
            'due_date'             => max(date('Y-m-d'), (string) $inv->duedate),
            'late_fee_pct'         => (float) ($gateway['late_fee_pct'] ?? 0),
            'monthly_interest_pct' => (float) ($gateway['monthly_interest_pct'] ?? 0),
            'notification_url'     => iugu_webhook_url($gateway),
            'return_url'           => iugu_return_url($gateway, $invoiceId),
            'ignore_email'         => ($gateway['ignore_due_email'] ?? '') === 'on',
        ],
    ];
}
