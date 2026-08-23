<?php

declare(strict_types=1);

/**
 * IuguCharges.php - a única porta de entrada da tabela mod_iugu_charges.
 *
 * CAMINHO NO WHMCS: /modules/gateways/iugu/IuguCharges.php
 *
 * Por que existe: na versão anterior a mesma regra de gravação estava escrita
 * três vezes (um hook de criação, um de lembrete e um de alteração de valor).
 * Qualquer correção precisava ser feita nos três, e as três já tinham
 * divergido. Aqui a regra existe uma vez só.
 *
 * A tabela guarda, para cada fatura do WHMCS, uma linha por método de
 * pagamento (pix, boleto, card). A chave lógica é (whmcs_invoice_id, method).
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
require_once __DIR__ . '/IuguClient.php';   // cancelOpen() recebe um IuguClient

use WHMCS\Database\Capsule;

final class IuguCharges
{
    /** Estados em que a cobrança ainda pode ser paga. */
    public const OPEN = ['pending', 'in_analysis'];

    /**
     * Grava (ou atualiza) a cobrança de um método para uma fatura.
     *
     * É upsert por (whmcs_invoice_id, method): reemitir um Pix substitui a
     * linha antiga em vez de acumular lixo. O histórico de quem PAGOU não se
     * perde porque a linha paga nunca é reemitida (ver openOf()).
     *
     * @param array $data Chaves aceitas: iugu_id, iugu_charge_id, status,
     *   qrcode_base64, qrcode_text, bank_slip_barcode, bank_slip_pdf,
     *   secure_url, expires_at, amount_cents, paid_at.
     */
    public static function save(int $invoiceId, int $clientId, string $method, array $data): void
    {
        $now = date('Y-m-d H:i:s');

        try {
            Capsule::table(IUGU_TABLE)->updateOrInsert(
                ['whmcs_invoice_id' => $invoiceId, 'method' => $method],
                [
                    'whmcs_client_id'   => $clientId,
                    'iugu_invoice_id'   => (string) ($data['iugu_id'] ?? ''),
                    'iugu_charge_id'    => $data['iugu_charge_id'] ?? null,
                    'status'            => (string) ($data['status'] ?? 'pending'),
                    'qrcode_base64'     => $data['qrcode_base64'] ?? null,
                    'qrcode_text'       => $data['qrcode_text'] ?? null,
                    'bank_slip_barcode' => $data['bank_slip_barcode'] ?? null,
                    'bank_slip_pdf'     => $data['bank_slip_pdf'] ?? null,
                    'secure_url'        => $data['secure_url'] ?? null,
                    'expires_at'        => $data['expires_at'] ?? null,
                    'amount_cents'      => (int) ($data['amount_cents'] ?? 0),
                    'paid_at'           => $data['paid_at'] ?? null,
                    'updated_at'        => $now,
                    'created_at'        => $now,
                ]
            );
        } catch (\Throwable $e) {
            // Gravar é importante, mas não a ponto de derrubar a tela do
            // cliente que já tem o QR na mão. Registra e segue.
            logActivity('Iugu: falha ao gravar cobranca ' . $method . ' da fatura #'
                . $invoiceId . ': ' . $e->getMessage());
        }
    }

    /**
     * Cobrança aberta e ainda válida de um método, ou null.
     *
     * "Válida" = status aberto E dentro do prazo. É o que evita bater na API
     * da Iugu a cada refresh da página da fatura, e é o que garante que o
     * copia-e-cola já enviado por WhatsApp continue sendo o mesmo.
     */
    public static function openOf(int $invoiceId, string $method): ?object
    {
        try {
            $row = Capsule::table(IUGU_TABLE)
                ->where('whmcs_invoice_id', $invoiceId)
                ->where('method', $method)
                ->whereIn('status', self::OPEN)
                ->orderBy('id', 'desc')
                ->first();
        } catch (\Throwable $e) {
            return null;
        }

        if (!$row) {
            return null;
        }

        if (!empty($row->expires_at) && strtotime((string) $row->expires_at) <= time()) {
            return null; // existe, mas venceu: quem chamou deve emitir de novo
        }

        return $row;
    }

    /** Todas as cobranças ainda abertas de uma fatura. */
    public static function openAll(int $invoiceId): array
    {
        try {
            return Capsule::table(IUGU_TABLE)
                ->where('whmcs_invoice_id', $invoiceId)
                ->whereIn('status', self::OPEN)
                ->get()
                ->all();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** Métodos que já têm cobrança aberta ou paga (não precisa reemitir). */
    public static function methodsInUse(int $invoiceId): array
    {
        try {
            return Capsule::table(IUGU_TABLE)
                ->where('whmcs_invoice_id', $invoiceId)
                ->whereIn('status', array_merge(self::OPEN, ['paid']))
                ->pluck('method')
                ->all();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** Marca um status pelo ID da fatura na Iugu. */
    public static function setStatusByIuguId(string $iuguInvoiceId, string $status, ?string $paidAt = null): void
    {
        if ($iuguInvoiceId === '') {
            return;
        }

        $update = ['status' => $status, 'updated_at' => date('Y-m-d H:i:s')];
        if ($paidAt !== null) {
            $update['paid_at'] = $paidAt;
        }

        try {
            Capsule::table(IUGU_TABLE)->where('iugu_invoice_id', $iuguInvoiceId)->update($update);
        } catch (\Throwable $e) {
            logActivity('Iugu: falha ao atualizar status da cobranca ' . $iuguInvoiceId . ': ' . $e->getMessage());
        }
    }

    /** Marca um status pela linha (id interno). */
    public static function setStatusById(int $rowId, string $status): void
    {
        try {
            Capsule::table(IUGU_TABLE)->where('id', $rowId)
                ->update(['status' => $status, 'updated_at' => date('Y-m-d H:i:s')]);
        } catch (\Throwable $e) {
            logActivity('Iugu: falha ao atualizar status da linha ' . $rowId . ': ' . $e->getMessage());
        }
    }

    /**
     * Cancela na Iugu, e marca localmente, todas as cobranças abertas da
     * fatura - opcionalmente poupando uma (a que acabou de ser paga).
     *
     * Isso é o que impede o cliente de pagar duas vezes: assim que o Pix cai,
     * o boleto que estava na mão dele para de valer.
     *
     * @param string $exceptIuguId ID da Iugu a NÃO cancelar (''= cancela todas).
     * @return int Quantas foram canceladas.
     */
    public static function cancelOpen(IuguClient $client, int $invoiceId, string $exceptIuguId = ''): int
    {
        $n = 0;

        foreach (self::openAll($invoiceId) as $row) {
            $iuguId = (string) $row->iugu_invoice_id;

            if ($iuguId === '' || $iuguId === $exceptIuguId) {
                continue;
            }

            try {
                // Um erro aqui costuma ser "já estava cancelada/expirada" na
                // Iugu - não é motivo para deixar a linha local aberta.
                $client->cancelInvoice($iuguId);
            } catch (\Throwable $e) {
                logActivity('Iugu: falha ao cancelar cobranca ' . $iuguId
                    . ' da fatura #' . $invoiceId . ': ' . $e->getMessage());
            }

            self::setStatusById((int) $row->id, 'canceled');
            $n++;
        }

        return $n;
    }

    /**
     * Faturas do WHMCS com Pix/boleto pendente, para o cron conferir.
     *
     * @param int $limit Teto por execução, para o cron não estourar o tempo.
     */
    public static function pendingForReconcile(int $limit = 200): array
    {
        try {
            return Capsule::table(IUGU_TABLE . ' as c')
                ->join('tblinvoices as i', 'i.id', '=', 'c.whmcs_invoice_id')
                ->whereIn('c.status', self::OPEN)
                ->where('i.status', 'Unpaid')
                ->where('c.iugu_invoice_id', '!=', '')
                ->orderBy('c.id', 'desc')
                ->limit($limit)
                ->get(['c.id', 'c.whmcs_invoice_id', 'c.iugu_invoice_id', 'c.method'])
                ->all();
        } catch (\Throwable $e) {
            return [];
        }
    }
}
