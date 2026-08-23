# Configuração - cada campo da tela

Onde fica: **Configurações ▸ Pagamentos ▸ Gateways de Pagamento ▸ Iugu - Pix,
Boleto e Cartão** (*Setup ▸ Payments ▸ Payment Gateways*).

Para cada campo: o que ele faz, o que acontece se ficar em branco, e o que
costuma dar errado.

---

## Credenciais

### Token de API (produção)

Credencial da sua conta Iugu. Painel da Iugu ▸ **Configurações ▸ Conta ▸ Tokens
de API**, o token **LIVE**.

- **Em branco:** o módulo não emite nada. A tela do cliente mostra "o meio de
  pagamento ainda não está configurado".
- **Cuidado:** esse token dá acesso total à sua conta Iugu. É guardado no banco
  do WHMCS, na tabela `tblpaymentgateways`. Trate como senha.
- **Efeito colateral de trocar o token:** os links de pagamento que os clientes
  já abriram param de valer até recarregarem a página. Isso é proposital - o
  token anti-CSRF é assinado com essa credencial.

### Token de API (teste)

O token de sandbox, da mesma tela da Iugu.

- **Em branco:** tudo bem, desde que o **Modo** esteja em `live`.
- Só é usado quando o Modo está em `test`.

### ID da conta

Painel da Iugu ▸ **Configurações ▸ Conta**. Uma sequência de letras e números.

- **Em branco:** Pix e boleto funcionam normalmente. O **cartão não aparece**
  para o cliente, porque a biblioteca da Iugu no navegador precisa desse ID
  para tokenizar.
- Também é usado no teste de credencial.

### Modo

`live` (produção) ou `test` (sandbox).

- Define **qual dos dois tokens** o módulo usa.
- Em `test` nenhum dinheiro se move de verdade. Use os cartões de teste da
  documentação da Iugu.
- **Lembre de voltar para `live`.** Fatura paga em modo de teste é baixada no
  WHMCS igual, e você fica com receita que não existe.

---

## Formas de pagamento

### Aceitar Pix · Aceitar boleto · Aceitar cartão

Quais botões aparecem na página da fatura.

- Marcar aqui **não habilita nada na Iugu**. O meio precisa estar ativo na sua
  conta lá. Se não estiver, o módulo devolve um erro claro na primeira
  tentativa ("A conta Iugu criou a fatura sem Pix").
- **Boleto** exige CPF ou CNPJ válido no cadastro do cliente - a Iugu recusa
  sem isso. Pix não exige.
- **Cartão** exige o **ID da conta** preenchido.
- Se você desmarcar todos, a fatura mostra um aviso pedindo para o cliente
  falar com o suporte.

**Padrão:** Pix e boleto marcados, cartão desmarcado.

---

## Pix

### Pix vale por

`1d`, `2d`, `3d`, `7d`, `15d` ou `30d`. A Iugu conta esse prazo em **dias** -
por isso não existe opção em horas.

Enquanto o prazo não vence, o módulo **reaproveita o mesmo código**: o cliente
que recebeu o copia-e-cola por WhatsApp continua conseguindo pagar com ele, e
cada visita à fatura não gasta uma chamada de API.

- **Prazo curto (1d):** o código morre rápido; quem recebeu o aviso ontem
  precisa entrar de novo na fatura.
- **Prazo longo (30d):** o mesmo código vale o ciclo inteiro. Bom para
  cobrança recorrente por mensagem.

**Padrão:** `3d`.

---

## Boleto

### Tolerância do boleto (dias)

Quantos dias **depois do vencimento** a sua conta Iugu ainda aceita o boleto.

Este campo **não muda o boleto**. Ele só diz ao módulo até quando reaproveitar
o boleto já emitido antes de emitir outro. O prazo de verdade é o configurado
no painel da Iugu.

- **`0`:** o módulo reemite a partir do dia seguinte ao vencimento.
- **Valor maior que a sua conta aceita:** o módulo entrega ao cliente um boleto
  que o banco já recusa. Confira o número no painel da Iugu.

**Padrão:** `0`.

---

## Cartão

### Parcelas no cartão

De 1 a 12. É o máximo oferecido ao cliente na tela.

- Com `1`, o seletor de parcelas nem aparece.
- **Quem define juros e quem paga é o painel da Iugu**, não este campo. O
  módulo apenas informa o número de parcelas na cobrança.
- O valor lançado na fatura do WHMCS é sempre o valor da fatura. Se a sua
  conta Iugu repassa juros ao cliente, o cliente paga mais do que a fatura
  mostra - isso é acordo seu com a Iugu.

**Padrão:** `1`.

---

## Multa e juros

### Multa por atraso (%)

Percentual único aplicado depois do vencimento, cobrado pela Iugu no boleto e
no Pix.

- **`0`:** nenhuma multa.
- No Brasil, a multa por atraso em relação de consumo é limitada por lei.
  Confira o limite aplicável ao seu caso antes de preencher.

**Padrão:** `0`.

### Juros ao mês (%)

Cobrado proporcionalmente por **dia** de atraso.

- **`0`:** nenhum juro.
- Vale o mesmo aviso legal do campo acima.

**Padrão:** `0`.

---

## Avançado

### A Iugu não envia e-mail

- **Marcado:** a Iugu não manda e-mail nenhum ao seu cliente. Quem avisa é o
  WHMCS, com os seus modelos e a sua marca. É o que quase todo mundo quer.
- **Desmarcado:** o cliente recebe e-mail dos dois. Costuma gerar dúvida
  ("recebi duas cobranças?").

**Padrão:** marcado.

### Campo do CPF/CNPJ

Nome do **campo personalizado de cliente** onde você guarda o documento.

O módulo procura nesta ordem:

1. O campo nativo de documento do WHMCS (`tax_id`), quando preenchido.
2. O campo personalizado com o nome que você escrever aqui.
3. Nomes comuns, como tentativa final: `CPF/CNPJ`, `CPF ou CNPJ`, `CPF`,
   `CNPJ`, `Documento`, `tax_id`.

- **Em branco:** só a ordem acima, sem o seu nome específico.
- **Nome errado:** boleto e cartão param de funcionar para todo mundo, com a
  mensagem "Seu CPF ou CNPJ não está preenchido corretamente". Confira o nome
  exato em *Configurações ▸ Campos Personalizados de Cliente*.
- A busca não diferencia maiúsculas de minúsculas e aceita nome parcial.

**Padrão:** `CPF/CNPJ`.

### Link de pagamento para o cliente

É o endereço que o módulo guarda como *onde pagar esta fatura*. Ele aparece no
botão **Abrir página de pagamento**, na tela da fatura no admin, e é o link que
você manda para o cliente por WhatsApp.

**Em branco, usa a página de pagamento da própria Iugu** - que já vem pronta,
com o Pix e o boleto daquela cobrança. Para a maioria das instalações isso
basta, e não há motivo para mexer aqui.

Se você prefere mandar o cliente para uma página **sua**, escreva o endereço
usando `{fatura}` onde entra o número:

| O que você escreve | O que é gravado para a fatura 1234 |
|---|---|
| *(em branco)* | a página da cobrança na Iugu |
| `https://seu-whmcs.com.br/viewinvoice.php?id={fatura}` | `...viewinvoice.php?id=1234` |
| `https://exemplo.com.br/pagar/{fatura}` | `https://exemplo.com.br/pagar/1234` |
| `https://exemplo.com.br/pagar` | `https://exemplo.com.br/pagar/1234` |

O `{fatura}` existe porque endereço de fatura raramente termina no número - no
próprio WHMCS ele fica no meio de uma *query string*. Sem o marcador, o número
vai para o fim.

Na tela do módulo o exemplo já aparece com o **endereço real da sua
instalação**, pronto para copiar. É só um exemplo: troque pelo da sua página.

O módulo **não fornece** essa página. Se você não tem uma, deixe em branco.

**Padrão:** em branco.

### Senha do aviso de pagamento

*(Fica no bloco de conexão, logo abaixo do Modo - é configuração da ligação com
a Iugu, não do seu negócio.)*

Quando alguém paga, a Iugu avisa este endereço do seu WHMCS:

```
https://SEU-WHMCS/modules/gateways/callback/iugu.php
```

Ele é **público**: qualquer um na internet pode chamá-lo. Ninguém consegue
forjar um pagamento - o módulo sempre reconsulta a fatura na API da Iugu antes
de quitar qualquer coisa -, mas **cada chamada gasta uma consulta na sua conta
Iugu**, e dá para usar isso para te incomodar.

Escrevendo uma senha aqui, e acrescentando `?key=A_SENHA` ao final do endereço
cadastrado no painel da Iugu, o módulo passa a **recusar com 403** quem não
trouxer a senha, antes de consultar coisa alguma:

```
https://SEU-WHMCS/modules/gateways/callback/iugu.php?key=A_SENHA
```

- **Em branco:** aceita de todos. Seguro contra baixa falsa, mas aberto a
  incômodo.
- **Preenchido aqui e esquecido na Iugu:** as baixas param de chegar. A
  conferência diária ainda salva, mas com atraso de até um dia.
- A Iugu **não assina** os gatilhos dela (a documentação informa apenas o IP de
  saída). Essa senha na URL é a verificação possível hoje.

Na tela do módulo o endereço já aparece montado com o seu domínio.

**Padrão:** em branco.

### Conferência diária

- **Marcado:** no cron diário do WHMCS, o módulo reconsulta na Iugu todas as
  cobranças que ainda constam como pendentes de faturas em aberto, e baixa as
  que foram pagas. É a rede de segurança para quando o webhook não chega.
- **Desmarcado:** o módulo depende só do webhook. Não recomendado.

Custa uma consulta à API por cobrança pendente, com teto de 200 por execução.

**Padrão:** marcado.

### Log de diagnóstico

- **Marcado:** grava o passo a passo em `whmcs_iugu.log`, no diretório
  temporário do servidor - **fora** da pasta publicada na web.
- **Desmarcado:** não grava nada.

No log entram evento, identificador e status. **Não** entram payload de
webhook, CPF, cartão nem dados do pagador - é uma decisão de LGPD, não um
esquecimento.

Ligue para investigar um problema e **desligue depois**. O arquivo cresce e
ninguém limpa sozinho.

**Padrão:** desmarcado.

---

## O quadro acima do botão de salvar

Na mesma tela, logo antes de **Salvar Alterações**, o módulo mostra:

- a **versão instalada** e a **última publicada**;
- o **changelog** quando há versão nova, com o link para baixar;
- links de **documentação**, **relatar problema** e **código no GitHub**;
- um link **Verificar novamente**, que limpa o cache de 1 hora da consulta.

Esse quadro **não atualiza nada sozinho** e não envia dado nenhum do seu WHMCS.
Se o servidor de versões estiver fora do ar, ele apenas informa que não
conseguiu consultar - a tela abre igual.

---

## Onde as configurações ficam guardadas

Na tabela `tblpaymentgateways` do WHMCS, uma linha por campo, com
`gateway = 'iugu'`. Desativar o gateway **não apaga** esses valores: reativar
volta tudo como estava.

---

Desenvolvido por **Edilson Souza** - [Hostcel](https://www.hostcel.com.br).
Licença de Proteção a Código Aberto Não Comercializável - ver [LICENSE](LICENSE).
