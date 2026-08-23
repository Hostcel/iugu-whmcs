# Segurança e decisões de projeto

Este documento existe porque **um módulo de pagamento merece ser lido antes de
entrar na sua operação**. Aqui está o que o módulo faz para proteger o seu
dinheiro e os dados dos seus clientes, por que cada decisão foi tomada, e o
que ele deliberadamente não faz.

Nada aqui é promessa: cada item aponta o arquivo onde a regra está escrita, e
o código é aberto para você conferir.

---

## O dinheiro

### A baixa é conferida, nunca confiada

A Iugu não assina os gatilhos (webhooks) dela - a documentação informa apenas
o IP de saída. Por isso o módulo **nunca acredita no que o POST diz**.

Ele extrai apenas o identificador da cobrança e vai **perguntar à API da Iugu**
qual é o status real. Só quita a fatura se a própria Iugu responder `paid`.

Um POST forjado não paga fatura nenhuma: quem tentar só faz o módulo consultar
a Iugu e receber "não pago".

📄 `modules/gateways/callback/iugu.php`

### Pagamento não é lançado duas vezes

O identificador da transação gravado no WHMCS é sempre o **ID da fatura na
Iugu** - o mesmo no webhook, no cartão e na conferência diária. Se o gatilho
chegar depois de a baixa já ter acontecido, o WHMCS reconhece a duplicidade e
não lança de novo.

Na conferência diária a checagem é feita por consulta, e não pela função do
WHMCS que encerra o processo - assim uma duplicata nunca interrompe o cron.

📄 `modules/gateways/iugu/reconcile.php`

### O que o webhook não entregar, o cron pega

Rede falha. O servidor pode estar fora naquele minuto, um firewall pode barrar,
a Iugu pode desistir depois das tentativas dela. O resultado seria o pior
possível: **cliente pagou e a fatura continua em aberto**, com risco de
suspensão do serviço.

A conferência diária reconsulta as cobranças ainda pendentes e baixa as que
foram pagas. É a rede de segurança do módulo.

📄 `modules/gateways/iugu/reconcile.php`

### Pagou um meio, os outros param de valer

Quando o Pix cai, o boleto que estava com o cliente é cancelado na Iugu - e
vice-versa. Sem isso, quem já pagou consegue pagar de novo pelo outro meio.

📄 `IuguCharges::cancelOpen()`

### Cobrança guardada só vale enquanto o valor bater

O módulo reaproveita o Pix e o boleto já emitidos, para que o copia-e-cola que
o cliente recebeu por WhatsApp continue funcionando. Mas antes de reaproveitar
ele **confere o valor**: se a fatura mudou, a cobrança antiga é cancelada na
Iugu e uma nova é emitida.

Isso fecha a única porta por onde o cliente poderia pagar um valor
desatualizado achando que quitou a fatura.

📄 `modules/gateways/iugu/create_charge.php`, `verpix.php`

---

## Os acessos

### Emitir cobrança exige três provas

O endpoint que emite Pix, boleto e cartão a pedido do cliente pede, nesta
ordem:

1. **sessão** - de cliente logado, ou de administrador;
2. **token assinado** - HMAC-SHA256 comparado com `hash_equals`, o que impede
   que outro site force o navegador do seu cliente a emitir cobranças (CSRF);
3. **posse da fatura** - cliente só mexe na própria; administrador, em
   qualquer uma.

📄 `modules/gateways/iugu/create_charge.php`

### O link público não pode ser adivinhado

O `verpix.php` - a página que você manda por WhatsApp - recebe o número da
fatura **e um token assinado**. Sem o token, ou com o token de outra fatura, a
página responde **404** e não confirma sequer que a fatura existe.

Não existe parâmetro que force reemissão: ninguém de fora consegue invalidar o
código que o seu cliente já recebeu.

O segredo que assina os tokens é o próprio token de API da Iugu, que só existe
no banco do seu WHMCS. Trocar a credencial invalida os links antigos - que é o
comportamento correto.

📄 `verpix.php`, `iugu_verpix_token()` em `config.php`

### Opcional, mas recomendado: proteger o endereço do gatilho

O endereço do webhook é público por natureza - a Iugu precisa alcançá-lo. Nada
que chegue nele consegue forjar pagamento, mas cada chamada consome uma
consulta à sua conta na Iugu.

---

## Os dados

### O cartão não passa pelo seu servidor

A biblioteca da própria Iugu captura o cartão no navegador do cliente e devolve
um token de uso único. O seu servidor vê o token, nunca o número.

📄 `iugu_link()` em `modules/gateways/iugu.php`

### O log fica fora da pasta publicada, e desligado

Quando ligado para diagnóstico, o log grava no diretório temporário do sistema
- nunca dentro de `/modules/`, que é servido pela web. Assim quem instala não
precisa configurar nada para ficar protegido.

Nele entram evento, identificador e status. **Não entram** payload de webhook,
CPF, cartão nem dados do pagador. É decisão de LGPD, não esquecimento.

📄 `iugu_log()` e `iugu_log_path()` em `config.php`

### O erro que o cliente vê é genérico

Mensagem de exceção, caminho de arquivo e linha do PHP ficam no log do
servidor. A tela do cliente recebe uma frase que ele consegue entender.

A única exceção é a recusa do cartão, em que a mensagem da operadora **é** útil
para o cliente e não expõe nada da sua instalação.

### Certificado sempre verificado

`CURLOPT_SSL_VERIFYPEER` e `VERIFYHOST` ligados em todas as chamadas. Não
desligue para "resolver" erro de SSL: isso abre a porta para interceptarem a
sua credencial.

📄 `modules/gateways/iugu/IuguClient.php`

### Nada sai da sua instalação

O módulo fala com dois endereços: a **API da Iugu**, com a credencial que você
configurar, e a **biblioteca da Iugu**, só quando o cartão está habilitado.

O painel de versão consulta um arquivo público para informar se existe versão
nova. Essa consulta **não envia nada** do seu WHMCS, e se o endereço estiver
fora do ar o módulo funciona igual.

Não há verificação de licença, chamada de ativação, telemetria nem código
ofuscado.

---

## O que o módulo deliberadamente não faz

Dito com todas as letras, para ninguém instalar esperando:

- **Não guarda cartão para cobrança automática.** `TokenisedStorage` é `false`
  de propósito: o módulo não anuncia ao WHMCS um recurso que não tem. Está no
  roteiro.
- **Não limita requisições por IP** no endpoint de emissão. A proteção hoje é
  sessão + token + posse da fatura, e o reaproveitamento da cobrança já
  absorve repetição. Um cliente logado ainda pode pedir emissão em sequência
  para as próprias faturas.
- **Não faz split de pagamento, assinatura Iugu, saque nem antecipação.**
- **Não calcula juros de parcelamento.** As parcelas são oferecidas até o
  máximo configurado; quem define juros e quem paga é o painel da Iugu.
- **Não tem tela de relatório no admin.** O que existe é o link da cobrança na
  própria fatura e o quadro de versão na configuração.

---

## Encontrou um problema de segurança?

Abra uma [issue](https://github.com/Hostcel/iugu-whmcs/issues) **sem detalhar a
exploração**, e a gente combina o resto por um canal privado.

---

Desenvolvido por **Edilson Souza** - [Hostcel](https://www.hostcel.com.br).
