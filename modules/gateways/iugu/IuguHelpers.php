<?php

declare(strict_types=1);

/**
 * IuguHelpers.php - utilitários de sanitização e formatação.
 *
 * CAMINHO NO WHMCS: /modules/gateways/iugu/IuguHelpers.php
 *
 * Tudo aqui é estático e sem efeito colateral: não toca banco, não faz
 * requisição, não escreve log. É a camada que traduz o jeito do WHMCS
 * (float, telefone com máscara, custom field) para o jeito da Iugu
 * (centavos, DDD separado do número, cpf_cnpj só com dígitos).
 *
 * A única exceção é findClientDocument(), que precisa consultar
 * tblcustomfields para descobrir o NOME do campo a partir do ID - a API do
 * WHMCS devolve os custom fields por ID, sem o nome.
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

final class IuguHelpers
{
    // ──────────────────────────────────────────────────────────────────────
    // Documento (CPF/CNPJ)
    // ──────────────────────────────────────────────────────────────────────

    /** Remove tudo que não é dígito. Base de quase todas as normalizações. */
    public static function onlyDigits(string $v): string
    {
        return preg_replace('/\D/', '', $v) ?? '';
    }

    /**
     * Valida CPF pelo dígito verificador.
     *
     * A Iugu recusa boleto com CPF inválido, e o erro que ela devolve não é
     * óbvio para o cliente. Validar aqui permite dar uma mensagem clara
     * ("atualize seu cadastro") antes de gastar uma chamada de API.
     */
    public static function isValidCpf(string $cpf): bool
    {
        $cpf = self::onlyDigits($cpf);

        // 11 dígitos e nada de sequência repetida (111.111.111-11 passa no
        // cálculo do DV, mas não é CPF de ninguém).
        if (strlen($cpf) !== 11 || preg_match('/^(\d)\1{10}$/', $cpf)) {
            return false;
        }

        // Calcula o 10º dígito com peso 10..2 e depois o 11º com peso 11..2.
        for ($t = 9; $t < 11; $t++) {
            $s = 0;
            for ($i = 0; $i < $t; $i++) {
                $s += (int) $cpf[$i] * (($t + 1) - $i);
            }
            $d = ((10 * $s) % 11) % 10;
            if ((int) $cpf[$t] !== $d) {
                return false;
            }
        }

        return true;
    }

    /** Valida CNPJ pelos dois dígitos verificadores. */
    public static function isValidCnpj(string $cnpj): bool
    {
        $cnpj = self::onlyDigits($cnpj);
        if (strlen($cnpj) !== 14 || preg_match('/^(\d)\1{13}$/', $cnpj)) {
            return false;
        }

        $w1 = [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];
        $w2 = [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];

        $calc = static function (string $slice, array $w): int {
            $s = 0;
            foreach ($w as $i => $peso) {
                $s += (int) $slice[$i] * $peso;
            }
            $m = $s % 11;
            return $m < 2 ? 0 : 11 - $m;
        };

        return $calc($cnpj, $w1) === (int) $cnpj[12]
            && $calc($cnpj, $w2) === (int) $cnpj[13];
    }

    /** Escolhe CPF ou CNPJ pelo comprimento e valida. */
    public static function isValidCpfCnpj(string $v): bool
    {
        $clean = self::onlyDigits($v);

        return match (strlen($clean)) {
            11      => self::isValidCpf($clean),
            14      => self::isValidCnpj($clean),
            default => false,
        };
    }

    /**
     * Acha o CPF/CNPJ do cliente.
     *
     * Ordem: campo nativo tax_id (WHMCS 8.0+) → custom field.
     *
     * O detalhe chato: localAPI('GetClientsDetails') devolve os custom fields
     * como uma lista de {id, value}, sem o nome. Para saber qual deles é o
     * documento, é preciso cruzar o id com tblcustomfields. E o fieldname
     * às vezes vem no formato "slug|Rótulo", por isso a busca olha os dois
     * lados do pipe.
     *
     * @param array       $clientDetails   Retorno de localAPI('GetClientsDetails').
     * @param string|null $customFieldName Nome exato configurado pelo admin.
     * @return string Só dígitos; vazio quando não achou.
     */
    public static function findClientDocument(array $clientDetails, ?string $customFieldName = null): string
    {
        // 1) Campo nativo. É o caminho feliz e não custa consulta nenhuma.
        if (!empty($clientDetails['tax_id'])) {
            return self::onlyDigits((string) $clientDetails['tax_id']);
        }
        if (!empty($clientDetails['client']['tax_id'])) {
            return self::onlyDigits((string) $clientDetails['client']['tax_id']);
        }

        // 2) Custom fields.
        $rawFields = $clientDetails['customfields']
            ?? $clientDetails['client']['customfields']
            ?? [];

        if (!is_array($rawFields) || $rawFields === []) {
            return '';
        }

        $ids = array_filter(array_map(static fn ($cf) => (int) ($cf['id'] ?? 0), $rawFields));
        if ($ids === []) {
            return '';
        }

        try {
            $nameMap = \WHMCS\Database\Capsule::table('tblcustomfields')
                ->whereIn('id', $ids)
                ->pluck('fieldname', 'id')
                ->toArray();
        } catch (\Throwable $e) {
            // Sem o mapa não dá para casar id→nome; melhor devolver vazio do
            // que arriscar mandar um campo errado como documento.
            return '';
        }

        // O nome configurado pelo admin tem prioridade; os demais são
        // tentativas comuns em instalações brasileiras.
        $candidates = array_values(array_filter(array_unique([
            $customFieldName,
            'CPF/CNPJ', 'CPF ou CNPJ', 'CPF', 'CNPJ', 'Documento', 'tax_id',
        ])));

        foreach ($rawFields as $cf) {
            $id        = (int) ($cf['id'] ?? 0);
            $value     = (string) ($cf['value'] ?? '');
            $fieldname = (string) ($nameMap[$id] ?? '');
            $parts     = explode('|', $fieldname, 2);

            foreach ($candidates as $c) {
                foreach ($parts as $p) {
                    if ($c !== '' && stripos($p, (string) $c) !== false) {
                        return self::onlyDigits($value);
                    }
                }
            }
        }

        return '';
    }

    // ──────────────────────────────────────────────────────────────────────
    // Valores
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Converte reais em centavos, que é a unidade que a Iugu usa em tudo.
     * 99.90 → 9990. O round() evita o clássico 9989 por ponto flutuante.
     */
    public static function toCents(float|string $amount): int
    {
        return (int) round((float) $amount * 100);
    }

    // ──────────────────────────────────────────────────────────────────────
    // Telefone
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Separa o telefone em DDD + número, como a Iugu espera.
     *
     * Aceita +5581988887777, 5581988887777 e 81988887777. Quando o número
     * não tem tamanho de telefone brasileiro, devolve vazio nos dois campos -
     * é melhor mandar o payer sem telefone do que mandar telefone quebrado,
     * porque a Iugu rejeita o payload inteiro (422).
     *
     * @return array{prefix:string,number:string}
     */
    public static function splitPhone(string $raw): array
    {
        $d = self::onlyDigits($raw);

        // Tira o DDI 55 quando o número veio no formato internacional.
        if (strlen($d) > 11 && str_starts_with($d, '55')) {
            $d = substr($d, 2);
        }

        // DDD (2) + número (8 fixo ou 9 celular).
        if (strlen($d) < 10 || strlen($d) > 11) {
            return ['prefix' => '', 'number' => ''];
        }

        return [
            'prefix' => substr($d, 0, 2),
            'number' => substr($d, 2),
        ];
    }

    // ──────────────────────────────────────────────────────────────────────
    // Expiração
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Quantos DIAS o Pix vale, a partir do valor escolhido na configuração.
     *
     * A API /v1/invoices da Iugu recebe expires_in em DIAS. Por isso a lista
     * de opções do módulo é em dias - a versão anterior oferecia "1h" e "6h",
     * que na prática viravam 1 dia e enganavam quem configurava.
     */
    public static function expiresInDays(string $preset): int
    {
        $map = ['1d' => 1, '2d' => 2, '3d' => 3, '7d' => 7, '15d' => 15, '30d' => 30];
        return $map[$preset] ?? 1;
    }

    /**
     * A mesma expiração, em data, para guardar no banco e saber quando o
     * copia-e-cola guardado deixou de valer.
     *
     * Vence no FIM do último dia: o Pix criado às 23h com 1 dia continua
     * válido durante todo o dia seguinte, que é como a Iugu conta.
     */
    public static function expiresAt(string $preset): string
    {
        $days = self::expiresInDays($preset);
        return (new \DateTime('today +' . $days . ' days 23:59:59'))->format('Y-m-d H:i:s');
    }

    // ──────────────────────────────────────────────────────────────────────
    // Payload
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Monta a estrutura `payer`, exigida pela Iugu em Pix e Boleto.
     *
     * Os textos de reserva ("Não informado") existem porque a Iugu recusa
     * endereço vazio no boleto. Não são inventados para enganar: são o
     * mínimo aceito quando o cadastro do WHMCS está incompleto, e o boleto
     * sai com o CPF/CNPJ correto, que é o que identifica o pagador.
     *
     * @param array  $client  Bloco de dados do cliente (GetClientsDetails).
     * @param string $cpfCnpj Documento já validado.
     */
    public static function buildPayer(array $client, string $cpfCnpj): array
    {
        $phone = self::splitPhone((string) ($client['phonenumber'] ?? ''));

        return [
            'name'         => trim(($client['firstname'] ?? '') . ' ' . ($client['lastname'] ?? '')),
            'email'        => (string) ($client['email'] ?? ''),
            'cpf_cnpj'     => self::onlyDigits($cpfCnpj),
            'phone_prefix' => $phone['prefix'],
            'phone'        => $phone['number'],
            'address'      => [
                'zip_code' => self::onlyDigits((string) ($client['postcode'] ?? '')),
                'street'   => (string) (((string) ($client['address1'] ?? '')) ?: 'Nao informado'),
                'number'   => 'S/N',
                'city'     => (string) (((string) ($client['city'] ?? '')) ?: 'Nao informada'),
                'state'    => strtoupper(substr((string) ($client['state'] ?? ''), 0, 2)),
                'country'  => 'Brasil',
            ],
        ];
    }

    /**
     * Converte os itens da fatura do WHMCS no formato de itens da Iugu.
     *
     * @param array $invoiceItems Bloco ['items']['item'] de localAPI('GetInvoice').
     */
    public static function buildItems(array $invoiceItems): array
    {
        $out = [];

        foreach ($invoiceItems as $item) {
            $cents = self::toCents($item['amount'] ?? 0);

            // Item de valor zero ou negativo (desconto lançado como item) faz a
            // Iugu recusar a fatura inteira. Some o negativo no primeiro item.
            if ($cents <= 0) {
                if ($out !== [] && $cents < 0) {
                    $out[0]['price_cents'] += $cents;
                }
                continue;
            }

            $out[] = [
                'description' => mb_substr(((string) ($item['description'] ?? '')) ?: 'Fatura', 0, 255),
                'quantity'    => 1,
                'price_cents' => $cents,
            ];
        }

        // Se os descontos zeraram o primeiro item, a fatura não é cobrável.
        if ($out !== [] && $out[0]['price_cents'] <= 0) {
            return [];
        }

        return $out;
    }

    /**
     * Traduz o status da Iugu para o vocabulário interno do módulo.
     *
     * 'authorized' conta como pago porque, no cartão, é o momento em que o
     * dinheiro está garantido. 'in_analysis' NÃO conta: é o antifraude
     * pensando, e ainda pode recusar.
     *
     * @return string paid|pending|in_analysis|canceled|refunded|unknown
     */
    public static function normalizeStatus(string $iuguStatus): string
    {
        return match (strtolower($iuguStatus)) {
            'paid', 'partially_paid', 'authorized' => 'paid',
            'in_analysis', 'in_protest'            => 'in_analysis',
            'pending'                              => 'pending',
            'canceled', 'expired'                  => 'canceled',
            'refunded', 'chargeback'               => 'refunded',
            default                                => 'unknown',
        };
    }

    /**
     * Tira chaves vazias do payload, recursivamente.
     *
     * A Iugu devolve 422 quando recebe campo presente e vazio (telefone sem
     * DDD, por exemplo). Omitir é aceito; mandar vazio, não.
     */
    public static function pruneEmpty(array $arr): array
    {
        $out = [];

        foreach ($arr as $k => $v) {
            if (is_array($v)) {
                $v = self::pruneEmpty($v);
                if ($v !== []) {
                    $out[$k] = $v;
                }
            } elseif ($v !== '' && $v !== null) {
                $out[$k] = $v;
            }
        }

        return $out;
    }
}
