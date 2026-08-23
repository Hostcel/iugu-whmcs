<?php

declare(strict_types=1);

/**
 * iugu.php - carregador dos hooks do módulo Iugu.
 *
 * CAMINHO NO WHMCS: /includes/hooks/iugu.php
 *
 * POR QUE ESTE ARQUIVO EXISTE, E POR QUE ELE NÃO PODE FALTAR
 *
 * O WHMCS carrega sozinho todo arquivo que esteja em /includes/hooks/. Já o
 * hooks.php de dentro da pasta de um módulo só é detectado para módulos de
 * PROVISIONAMENTO, REGISTRAR e ADDON - gateway não entra nessa lista
 * (developers.whmcs.com/hooks/module-hooks/).
 *
 * Sem este carregador, o módulo continua recebendo pagamento pela tela da
 * fatura, mas TUDO que ele faz sozinho para de acontecer:
 *   - o Pix e o boleto deixam de ser emitidos junto com a fatura;
 *   - fatura cancelada no WHMCS continua cobrável na Iugu;
 *   - fatura que muda de valor fica com a cobrança do valor antigo;
 *   - a conferência diária não roda, e pagamento sem webhook não é baixado.
 *
 * A lógica não mora aqui de propósito: fica em
 * /modules/gateways/iugu/hooks.php, junto com o resto do módulo. Assim
 * atualizar o módulo é substituir uma pasta só, e este arquivo quase nunca
 * muda.
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

$iuguHooks = __DIR__ . '/../../modules/gateways/iugu/hooks.php';

// Se o módulo foi removido e este arquivo ficou para trás, sai calado. Um
// carregador não pode ser o motivo de o WHMCS inteiro parar de abrir.
if (is_file($iuguHooks)) {
    // O require_once, somado à trava IUGU_HOOKS_LOADED lá dentro, garante que
    // os hooks sejam registrados uma vez só - mesmo que uma versão futura do
    // WHMCS passe a carregar o hooks.php do módulo também.
    require_once $iuguHooks;
}

unset($iuguHooks);
