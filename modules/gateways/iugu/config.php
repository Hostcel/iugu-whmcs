<?php

declare(strict_types=1);

/**
 * config.php - constantes, identidade e utilitários compartilhados do módulo.
 *
 * CAMINHO NO WHMCS: /modules/gateways/iugu/config.php
 *
 * Este arquivo não faz nada sozinho. Ele é incluído por todos os outros
 * arquivos do módulo e concentra três coisas:
 *
 *   1. A VERSÃO instalada e os links do projeto (usados no painel de suporte
 *      que aparece na tela de configuração do gateway).
 *   2. O leitor do manifesto de versões (para avisar quando sai versão nova).
 *   3. Utilitários que todo mundo usa: log, token anti-CSRF e nome da tabela.
 *
 * Por que uma constante de versão e não ler de um .json? Porque o WHMCS pode
 * ter o módulo em cache de opcode; constante é o jeito mais barato e confiável
 * de saber o que está realmente instalado.
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

// Proteção contra dupla inclusão: config.php é carregado pelo gateway, pelos
// hooks, pelo webhook e pelos endpoints AJAX. Sem isto, "Cannot redeclare".
if (defined('IUGU_VERSION')) {
    return;
}

// ═══════════════════════════════════════════════════════════════════════════
// IDENTIDADE
// ═══════════════════════════════════════════════════════════════════════════

/** Versão instalada. Subir SEMPRE que publicar. Formato semântico X.Y.Z. */
define('IUGU_VERSION', '2.0.0');

/** Identificador do módulo. É o nome da pasta e do arquivo do gateway. */
define('IUGU_SLUG', 'iugu');

/** Nome exibido na tela de pagamento e no admin. */
define('IUGU_NAME', 'Iugu - Pix, Boleto e Cartão');

/** Repositório oficial. É de onde sai a atualização. */
define('IUGU_GITHUB', 'https://github.com/Hostcel/iugu-whmcs');

/** Página de documentação/instalação. */
define('IUGU_DOCS', 'https://github.com/Hostcel/iugu-whmcs#readme');

/** Onde abrir dúvida ou defeito (issues do próprio repositório). */
define('IUGU_SUPPORT', 'https://github.com/Hostcel/iugu-whmcs/issues');

/** Site do autor. */
define('IUGU_AUTHOR_URL', 'https://www.hostcel.com.br');

/** Telefone de suporte, como se lê. */
define('IUGU_SUPPORT_PHONE', '(81) 99326-7690');

/**
 * O mesmo número para o link do WhatsApp, SEM o nono dígito.
 *
 * Com o 9 o wa.me abre uma página vazia, sem foto nem nome do perfil. Sem ele
 * a conversa abre certo. Não é engano de digitação: é o formato que funciona.
 */
define('IUGU_SUPPORT_PHONE_E164', '558193267690');

// ═══════════════════════════════════════════════════════════════════════════
// ATUALIZAÇÃO
// ═══════════════════════════════════════════════════════════════════════════

/**
 * Manifesto de versão deste módulo. Responde um JSON no formato:
 *   {"version":"2.0.0","date":"2026-08-23","url":"...","changelog":["...","..."]}
 *
 * Se o endereço não responder, o painel apenas informa que não conseguiu
 * checar - o módulo NUNCA deixa de funcionar por causa disso, e NUNCA
 * atualiza nada sozinho. Quem atualiza é o dono do WHMCS.
 */
define('IUGU_UPDATE_MANIFEST', 'https://hostcel.com.br/downloads/manifesto.php?m=iugu');

/** Lista dos outros módulos gratuitos publicados (usada no painel de suporte). */
define('IUGU_MARKET_MANIFEST', 'https://hostcel.com.br/downloads/manifesto.php?m=modulos');

// ═══════════════════════════════════════════════════════════════════════════
// BANCO
// ═══════════════════════════════════════════════════════════════════════════

/**
 * Única tabela criada pelo módulo. Guarda o vínculo entre a fatura do WHMCS
 * e a cobrança correspondente na Iugu, mais o Pix copia-e-cola / linha
 * digitável já prontos para reenviar sem chamar a API de novo.
 */
define('IUGU_TABLE', 'mod_iugu_charges');

// ═══════════════════════════════════════════════════════════════════════════
// ENDEREÇO DA INSTALAÇÃO
// ═══════════════════════════════════════════════════════════════════════════

/**
 * Endereço do WHMCS, sem a barra final.
 *
 * Serve para montar EXEMPLOS reais na tela de configuração: em vez de escrever
 * "https://SEU-WHMCS/...", o campo já mostra o endereço da instalação de quem
 * está lendo. Quem configura copia e cola em vez de adivinhar.
 *
 * Não confundir com $params['systemurl'], que o WHMCS entrega às funções do
 * gateway - aquele é o caminho normal. Este existe porque iugu_config() é
 * chamada sem parâmetro nenhum.
 */
function iugu_system_url(): string
{
    try {
        $u = (string) (\WHMCS\Config\Setting::getValue('SystemURL') ?? '');
        if ($u !== '') {
            return rtrim($u, '/');
        }
    } catch (\Throwable $e) {
        // Instalação antiga ou contexto sem a classe: cai no $GLOBALS abaixo.
    }

    return rtrim((string) ($GLOBALS['CONFIG']['SystemURL'] ?? ''), '/');
}

// ═══════════════════════════════════════════════════════════════════════════
// LOG
// ═══════════════════════════════════════════════════════════════════════════

/**
 * Caminho do arquivo de log.
 *
 * Fica no diretório temporário do sistema, NUNCA dentro de /modules/, porque
 * um arquivo dentro da pasta do módulo é servido pela web em qualquer
 * instalação que não tenha uma regra de bloqueio - e este log cita nome e
 * e-mail de pagador. Quem instalar o módulo não precisa configurar nada
 * para ficar protegido.
 */
function iugu_log_path(): string
{
    return rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'whmcs_iugu.log';
}

/**
 * Escreve uma linha no log de diagnóstico.
 *
 * Desligado por padrão: só grava quando o admin marca "Log de diagnóstico"
 * na configuração do gateway. Não registre payload de webhook nem documento
 * de cliente aqui - só o suficiente para achar o problema (LGPD).
 *
 * @param bool   $enabled Valor do campo debug_log do gateway.
 * @param string $msg     Mensagem curta, sem dado pessoal.
 * @param mixed  $ctx     Contexto opcional (array pequeno ou string).
 */
function iugu_log(bool $enabled, string $msg, mixed $ctx = null): void
{
    if (!$enabled) {
        return;
    }
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg;
    if ($ctx !== null) {
        $line .= ' | ' . (is_string($ctx) ? $ctx : json_encode($ctx, JSON_UNESCAPED_UNICODE));
    }
    @file_put_contents(iugu_log_path(), $line . "\n", FILE_APPEND | LOCK_EX);
}

// ═══════════════════════════════════════════════════════════════════════════
// TOKEN ANTI-CSRF DOS ENDPOINTS DO CLIENTE
// ═══════════════════════════════════════════════════════════════════════════

/**
 * Assina o par (fatura, cliente) com o token de API do gateway.
 *
 * O botão "Gerar Pix" da área do cliente manda um POST para create_charge.php.
 * Sem token, bastaria um site qualquer forçar o navegador de um cliente logado
 * a emitir cobranças. O token resolve isso sem tabela nova e sem sessão extra:
 * o segredo é o próprio token de API, que já está guardado no WHMCS e que
 * ninguém de fora conhece. Trocar a credencial da Iugu invalida os tokens
 * antigos - que é o comportamento correto.
 *
 * @param string $apiToken  Token de API em uso (produção ou teste).
 */
function iugu_form_token(int $invoiceId, int $clientId, string $apiToken): string
{
    return hash_hmac('sha256', $invoiceId . '|' . $clientId, $apiToken);
}

/**
 * Assina o link público do verpix.
 *
 * Prefixo diferente do token do formulário de propósito: um token não serve
 * para o outro, mesmo tendo o mesmo segredo. Sem isso, quem tivesse o link do
 * verpix conseguiria falar com o endpoint da área do cliente, e vice-versa.
 *
 * Assinar o link é o que impede varrer números de fatura: sem o token, a
 * página não abre nem confirma que a fatura existe.
 */
function iugu_verpix_token(int $invoiceId, string $apiToken): string
{
    return hash_hmac('sha256', 'verpix|' . $invoiceId, $apiToken);
}

/** Confere o token recebido, em tempo constante (evita ataque de temporização). */
function iugu_check_form_token(string $given, int $invoiceId, int $clientId, string $apiToken): bool
{
    if ($given === '' || $apiToken === '') {
        return false;
    }
    return hash_equals(iugu_form_token($invoiceId, $clientId, $apiToken), $given);
}

// ═══════════════════════════════════════════════════════════════════════════
// MANIFESTO DE VERSÃO
// ═══════════════════════════════════════════════════════════════════════════

/**
 * Busca um JSON remoto com cache em disco. Falha em silêncio.
 *
 * Timeout curto de propósito: esta chamada acontece enquanto o admin abre a
 * tela de configuração do gateway. Se o servidor de versões estiver fora, a
 * tela tem que abrir do mesmo jeito.
 *
 * @param int $ttl Segundos de cache. 1 hora é o suficiente e evita martelar.
 */
function iugu_fetch_json(string $url, string $cacheName, int $ttl = 3600): array
{
    $cache = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . $cacheName;

    if (is_readable($cache) && (time() - (int) filemtime($cache)) < $ttl) {
        $d = json_decode((string) @file_get_contents($cache), true);
        if (is_array($d)) {
            return $d;
        }
    }

    try {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_USERAGENT      => 'WHMCS-Iugu/' . IUGU_VERSION,
        ]);
        $raw  = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code === 200 && $raw) {
            $d = json_decode((string) $raw, true);
            if (is_array($d)) {
                @file_put_contents($cache, $raw);
                return $d;
            }
        }
    } catch (\Throwable $e) {
        // Sem rede: segue sem novidade. Nunca quebra a tela.
    }

    return [];
}

/** Última versão publicada, ou null quando não deu para checar. */
function iugu_latest(): ?array
{
    $d = iugu_fetch_json(IUGU_UPDATE_MANIFEST, 'iugu_latest.json');
    return !empty($d['version']) ? $d : null;
}

/** Apaga o cache do manifesto para forçar uma nova consulta. */
function iugu_forget_latest(): void
{
    @unlink(rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'iugu_latest.json');
}

/** true quando o manifesto anuncia versão maior que a instalada. */
function iugu_has_update(?array $latest = null): bool
{
    $latest = $latest ?? iugu_latest();
    return $latest !== null && version_compare((string) $latest['version'], IUGU_VERSION, '>');
}
