# Instalação - Módulo Iugu para WHMCS

Tempo estimado: 15 minutos, sendo 10 deles no painel da Iugu.

Se você já instalou módulo de WHMCS antes, o [resumo do README](README.md#instalação)
basta. Este arquivo é o passo a passo completo.

---

## Antes de começar

Tenha em mãos:

- [ ] Acesso de administrador ao WHMCS.
- [ ] Acesso por FTP, SSH ou gerenciador de arquivos à pasta do WHMCS.
- [ ] Uma conta na [Iugu](https://iugu.com) com Pix e/ou boleto **já
      habilitados no painel deles**. O módulo não habilita meio de pagamento
      na sua conta - ele usa o que estiver habilitado.
- [ ] O WHMCS acessível por **HTTPS**. O webhook da Iugu e a captura de cartão
      não funcionam em HTTP.

Confira também:

- **PHP 8.1 ou superior.** Veja em *Utilitários ▸ Estado do Sistema* (System
  Health). Em PHP 8.0 ou anterior o módulo não carrega.
- **Extensão `curl` habilitada.** Também aparece nessa tela.

> **Faça backup antes.** É um módulo de pagamento. Se algo der errado, você quer
> poder voltar. Nenhum arquivo existente é sobrescrito por esta instalação, mas
> backup é backup.

---

## Passo 1 - Copiar os arquivos

Baixe o repositório (botão **Code ▸ Download ZIP**, ou `git clone`) e copie as
pastas **`modules/`** e **`includes/`**, mais o arquivo **`verpix.php`**, para a **raiz do seu WHMCS**,
preservando a estrutura.

A raiz do WHMCS é a pasta onde ficam `init.php` e `configuration.php`.

> ⚠️ **São duas pastas, não uma.** O arquivo `includes/hooks/iugu.php` é o que
> faz o módulo trabalhar sozinho - emitir o Pix junto com a fatura, cancelar na
> Iugu quando a fatura é anulada e rodar a conferência diária. Sem ele o módulo
> ainda recebe pagamento pela tela da fatura, mas nada acontece automaticamente.

Depois de copiar, você deve ter exatamente isto:

```
<raiz do WHMCS>/
├── verpix.php               ← página pública de pagamento
├── includes/
│   └── hooks/
│       └── iugu.php          ← NÃO ESQUEÇA ESTE
└── modules/
    └── gateways/
        ├── iugu.php
        ├── iugu/
        │   ├── .htaccess
        │   ├── admin_panel.php
        │   ├── check_status.php
        │   ├── config.php
        │   ├── create_charge.php
        │   ├── hooks.php
        │   ├── IuguCharges.php
        │   ├── IuguClient.php
        │   ├── IuguHelpers.php
        │   ├── IuguInvoice.php
        │   ├── logo.png
        │   ├── reconcile.php
        │   └── whmcs.json
        └── callback/
            └── iugu.php
```

⚠️ **A pasta `callback/` já existe no seu WHMCS.** Você está acrescentando um
arquivo `iugu.php` dentro dela, não substituindo a pasta. Se o seu programa de
FTP perguntar "substituir pasta?", escolha **mesclar/merge**, nunca "substituir".

⚠️ **O `.htaccess` é oculto.** Muitos clientes de FTP não o copiam por padrão.
Confira se ele foi junto - sem ele o módulo continua funcionando (todo arquivo
tem trava própria contra acesso direto), mas é uma camada a menos.

### Por SSH, se preferir

```bash
cd /caminho/do/whmcs
unzip ~/iugu-whmcs-main.zip -d /tmp/iugu
cp -rn /tmp/iugu/iugu-whmcs-main/modules/. ./modules/
cp -rn /tmp/iugu/iugu-whmcs-main/includes/. ./includes/
cp -n  /tmp/iugu/iugu-whmcs-main/verpix.php ./
```

O `-n` do `cp` é proposital: ele não sobrescreve nada que já exista.

### Permissões

Os arquivos precisam ser apenas **legíveis** pelo usuário do PHP. O padrão
`644` para arquivos e `755` para pastas resolve. **Não** use `777`.

---

## Passo 2 - Ativar no WHMCS

1. Entre no admin.
2. Vá em **Configurações ▸ Pagamentos ▸ Gateways de Pagamento**
   (*Setup ▸ Payments ▸ Payment Gateways*).
3. Clique na aba **Todos os Gateways de Pagamento** (*All Payment Gateways*).
4. Procure **Iugu - Pix, Boleto e Cartão** e clique nele.

Nesse clique o WHMCS ativa o módulo e cria a tabela `mod_iugu_charges`. Se
aparecer erro nessa hora, ele é do banco - confira se o usuário do WHMCS tem
permissão de `CREATE TABLE`.

O módulo passa para a aba **Gateways Ativos** (*Manage Existing Gateways*), com
os campos de configuração à mostra.

---

## Passo 3 - Pegar as credenciais na Iugu

No painel da Iugu:

1. **Configurações ▸ Conta**. Copie o **ID da Conta** (uma sequência de letras
   e números).
2. Na mesma área, em **Tokens de API**, copie o token **de produção** (LIVE).
   Se for testar antes, copie também o de **teste**.

> O token de API dá acesso total à sua conta Iugu. Trate como senha: não mande
> por WhatsApp, não cole em chamado, e troque se vazar.

---

## Passo 4 - Preencher a configuração

De volta ao WHMCS, na tela do gateway:

| Campo | O que colocar |
|---|---|
| **Token de API (produção)** | O token LIVE que você copiou. |
| **Token de API (teste)** | O token de teste, ou deixe vazio. |
| **ID da conta** | O ID da Conta. Obrigatório se for aceitar cartão. |
| **Modo** | `live` para valer, `test` para experimentar. |
| **Aceitar Pix / boleto / cartão** | Marque o que a sua conta Iugu tem. |
| **Pix vale por** | `3d` é um bom começo. |
| **Campo do CPF/CNPJ** | O nome exato do campo personalizado onde você guarda o documento, se não usa o campo nativo do WHMCS. |

Clique em **Salvar Alterações**.

Os demais campos têm padrão razoável e estão explicados em
**[CONFIGURACAO.md](CONFIGURACAO.md)**.

---

## Passo 5 - Cadastrar o gatilho (webhook) na Iugu

Sem este passo **a fatura não é baixada sozinha**. O cliente paga e a fatura
continua em aberto até a conferência diária rodar.

No painel da Iugu:

1. **Configurações ▸ Gatilhos** (ou *Webhooks*).
2. Adicione um gatilho novo.
3. **Evento:** `invoice.status_changed`
4. **URL:**
   ```
   https://SEU-WHMCS/modules/gateways/callback/iugu.php
   ```
   Troque `SEU-WHMCS` pelo endereço real, com o caminho até a raiz do WHMCS.
   Se o seu WHMCS fica em `https://central.exemplo.com.br/`, a URL é
   `https://central.exemplo.com.br/modules/gateways/callback/iugu.php`.
5. Salve.

### Com o segredo do webhook (recomendado)

Se você preencher o campo **Senha do aviso de pagamento** na configuração do módulo,
acrescente a chave ao final da URL cadastrada na Iugu:

```
https://SEU-WHMCS/modules/gateways/callback/iugu.php?key=A_SENHA
```

A partir daí o módulo recusa qualquer chamada que não traga a senha.

---

## Passo 6 - Link curto do verpix (opcional)

Do jeito que sai da caixa, o link que você manda ao cliente é assim:

```
https://SEU-WHMCS/verpix.php?i=1234&t=a1b2c3…
```

Funciona em qualquer servidor, sem configurar nada. Mas dá para deixar curto:

```
https://SEU-WHMCS/verpix/1234/a1b2c3…
```

Para isso, duas coisas:

**1. Acrescente a regra de reescrita** ao `.htaccess` que já existe na raiz do
seu WHMCS - a mesma pasta do `verpix.php` e do `init.php`:

```apache
# Link curto do verpix:  /verpix/<fatura>/<token>  →  /verpix.php?i=…&t=…
# Cole este bloco no .htaccess EXISTENTE, logo após "RewriteEngine On".
# NÃO substitua o arquivo inteiro: ele já tem regras do WHMCS.

RewriteEngine On
RewriteRule ^verpix/([0-9]+)/([A-Fa-f0-9]+)/?$ /verpix.php?i=$1&t=$2 [L,QSA]
```

**2. Tire o `.php` do campo** *Link do verpix*, na configuração do gateway:

| Campo | Link gerado |
|---|---|
| `https://SEU-WHMCS/verpix.php` | `…/verpix.php?i=1234&t=…` (sem configurar nada) |
| `https://SEU-WHMCS/verpix` | `…/verpix/1234/…` (precisa da regra acima) |

> Só em **Apache** e **Litespeed**. Em Nginx a regra equivalente é:
> ```nginx
> rewrite ^/verpix/([0-9]+)/([A-Fa-f0-9]+)/?$ /verpix.php?i=$1&t=$2 last;
> ```

Se a regra não pegar, o link cai em 404 - e é só voltar o campo para
`verpix.php` que tudo volta a funcionar.

---

## Passo 7 - Conferir se o cron está rodando

A conferência diária depende do cron do WHMCS, que você já deve ter
configurado na instalação. Confira em **Utilitários ▸ Estado do Sistema**: se
o WHMCS reclamar que o cron não roda há dias, resolva isso - não é do módulo,
mas é dele que a rede de segurança depende.

---

## Passo 8 - Testar

### Teste do Pix

1. Crie uma fatura de valor baixo para um cliente de teste (com CPF válido
   no cadastro).
2. Abra a fatura pela área do cliente.
3. Escolha **Iugu** como forma de pagamento e clique em **Pagar com Pix**.
4. O QR code deve aparecer em poucos segundos.
5. Pague pelo aplicativo do banco.
6. A tela deve recarregar sozinha em até ~30 segundos, com a fatura quitada.

### Se o QR não aparecer

Ligue o **Log de diagnóstico** na configuração do gateway, repita o teste e
leia o arquivo:

```bash
tail -50 /tmp/whmcs_iugu.log
```

(Em alguns servidores o diretório temporário não é `/tmp`. O caminho exato é
o que `sys_get_temp_dir()` do PHP devolve.)

Também vale olhar **Utilitários ▸ Logs ▸ Log de Gateway** no admin do WHMCS.

**Desligue o log depois do teste.**

### Erros comuns

| Sintoma | Causa mais provável |
|---|---|
| "O meio de pagamento ainda não está configurado" | Token de API em branco para o Modo escolhido. |
| "Seu CPF ou CNPJ não está preenchido corretamente" | O documento do cliente está vazio, ou o *Campo do CPF/CNPJ* não bate com o nome do campo personalizado. |
| "A conta Iugu criou a fatura sem Pix" | Pix não está habilitado na sua conta Iugu. |
| Pagou e a fatura não baixou | Gatilho não cadastrado, URL errada, ou a senha do aviso está preenchida no módulo mas não na URL da Iugu. |
| Cartão não abre o formulário | **ID da conta** em branco. |

---

## Atualizar para uma versão nova

1. Baixe a versão nova do repositório.
2. Substitua os arquivos em `modules/gateways/` pelos novos.
3. Recarregue a tela de configuração do gateway.

Suas configurações e o histórico de cobranças são preservados - nada em
`mod_iugu_charges` é apagado.

O quadro na tela de configuração avisa quando sai versão nova, com o changelog.

---

## Desinstalar

1. **Configurações ▸ Pagamentos ▸ Gateways de Pagamento** → desative o Iugu.
   A tabela **não** é apagada: o histórico de quem pagou o quê fica.
2. Se quiser remover mesmo, apague os arquivos listados no passo 1 - inclusive
   `includes/hooks/iugu.php` - e rode:
   ```sql
   DROP TABLE mod_iugu_charges;
   ```
3. No painel da Iugu, apague o gatilho.

---

Dúvida ou defeito: [abra uma issue](https://github.com/Hostcel/iugu-whmcs/issues).

Desenvolvido por **Edilson Souza** - [Hostcel](https://www.hostcel.com.br).
Licença de Proteção a Código Aberto Não Comercializável - ver [LICENSE](LICENSE).
