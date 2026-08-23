<?php

declare(strict_types=1);

/**
 * admin_panel.php - informações do módulo, acima do botão Salvar.
 *
 * CAMINHO NO WHMCS: /modules/gateways/iugu/admin_panel.php
 *
 * Gateway não tem aba própria: o WHMCS desenha a tela de configuração sozinho
 * a partir de iugu_config(). O hook AdminAreaFooterOutput (hooks.php) pega o
 * que esta função devolve e monta com ele uma LINHA da própria tabela de
 * configuração, imediatamente antes da linha do botão Salvar - rótulo na
 * primeira coluna, conteúdo na segunda, igual a todas as outras linhas.
 *
 * Por isso esta função devolve só o CONTEÚDO da célula, sem tabela por fora:
 * quem monta a linha é o JavaScript, copiando as classes de uma linha que já
 * existe. Foi assim que parou de quebrar o layout.
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

require_once __DIR__ . '/config.php';

/** Rótulo da linha, para a primeira coluna da tabela. */
function iugu_admin_panel_label(): string
{
    return 'Informações do módulo';
}

/**
 * Pergunta à Iugu se a credencial ainda vale - só quando o admin clica.
 *
 * Credencial revogada é a falha mais comum e a que menos se explica sozinha:
 * a fatura simplesmente para de gerar Pix. Este teste devolve o motivo real
 * ("401 Unauthorized") em uma linha.
 *
 * @return array{ok:bool,texto:string}|null null = o admin ainda não pediu.
 */
function iugu_testar_conexao(): ?array
{
    if (empty($_GET['iuguconexao'])) {
        return null;
    }

    try {
        if (!function_exists('getGatewayVariables')) {
            require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
        }
        require_once __DIR__ . '/../iugu.php';

        $gateway = getGatewayVariables('iugu');

        if (empty($gateway['type'])) {
            return ['ok' => false, 'texto' => 'gateway inativo'];
        }

        $res = iugu_make_client($gateway)->healthCheck();

        if (!empty($res['ok'])) {
            return ['ok' => true, 'texto' => 'conectado'];
        }

        // O texto da Iugu é curto e direto ("Unauthorized"); o código HTTP
        // diz o resto. Cortado para não estourar a linha.
        return ['ok' => false, 'texto' => 'HTTP ' . (int) $res['status'] . ' - '
            . mb_substr((string) ($res['error'] ?? 'sem resposta'), 0, 60)];
    } catch (\Throwable $e) {
        return ['ok' => false, 'texto' => mb_substr($e->getMessage(), 0, 60)];
    }
}

/**
 * Conteúdo da linha: tudo numa linha só, separado por barra.
 *
 * Versão e status, suporte, páginas e autor. Nada além disso - endereço de
 * webhook, instruções e explicação não são informação do módulo e não entram
 * aqui.
 */
function iugu_admin_panel_html(): string
{
    $latest = iugu_latest();
    $nova   = iugu_has_update($latest);

    $h = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

    if ($nova) {
        $cor    = 'danger';
        $status = 'Desatualizado';
    } elseif ($latest) {
        $cor    = 'success';
        $status = 'Atualizado';
    } else {
        // Sem resposta do servidor de versões. Dizer "atualizado" aqui seria
        // afirmar o que não foi verificado.
        $cor    = 'default';
        $status = 'Não verificado';
    }

    $uri     = (string) ($_SERVER['REQUEST_URI'] ?? '');
    $recheck = $h($uri . (str_contains($uri, '?') ? '&' : '?') . 'iugucheck=1');

    $partes = [];

    $partes[] = '<b>v' . $h(IUGU_VERSION) . '</b>';
    $partes[] = '<span class="label label-' . $cor . '">' . $status . '</span>'
        . ($nova
            ? ' <a href="' . $h($latest['url'] ?? IUGU_GITHUB) . '" target="_blank" rel="noopener">baixar v'
              . $h($latest['version']) . '</a>'
            : '')
        . ' <a href="' . $recheck . '">verificar</a>';

    /* Conexão com a Iugu. Só consulta quando o admin pede, clicando em
       "testar" - não gasta uma chamada de API a cada abertura da tela. */
    $conexao = iugu_testar_conexao();

    if ($conexao !== null) {
        $partes[] = 'Iugu <span class="label label-' . ($conexao['ok'] ? 'success' : 'danger') . '">'
            . $h($conexao['texto']) . '</span>';
    } else {
        $partes[] = 'Iugu <a href="' . $h($uri . (str_contains($uri, '?') ? '&' : '?') . 'iuguconexao=1')
            . '">testar conexão</a>';
    }

    $partes[] = 'Suporte <b>' . $h(IUGU_SUPPORT_PHONE) . '</b>'
        . ' <a href="https://wa.me/' . $h(IUGU_SUPPORT_PHONE_E164) . '" target="_blank" rel="noopener">WhatsApp</a>';

    $partes[] = '<a href="' . $h(IUGU_DOCS) . '" target="_blank" rel="noopener">Documentação</a>';
    $partes[] = '<a href="' . $h(IUGU_SUPPORT) . '" target="_blank" rel="noopener">Suporte técnico</a>';
    $partes[] = '<a href="' . $h(IUGU_GITHUB) . '" target="_blank" rel="noopener">GitHub</a>';
    $partes[] = 'Edilson Souza <a href="' . $h(IUGU_AUTHOR_URL) . '" target="_blank" rel="noopener">Hostcel</a>';

    return '<div class="iugu-adm" style="font-size:13px;line-height:2">'
        . implode(' <span style="color:#ccc">|</span> ', $partes)
        . '</div>';
}
