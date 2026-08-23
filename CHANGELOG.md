# Changelog

Formato: o que muda para quem usa, não o que mudou no diff.

---

## 2.0.0 - 2026-08-23

Primeira versão pública.

### Pagamento

- **Pix** com QR code e copia-e-cola na página da fatura.
- **Boleto** com código de barras, linha digitável e PDF.
- **Cartão de crédito** com parcelamento, sem o número passar pelo seu
  servidor - a captura é feita pela biblioteca da própria Iugu, no navegador.
- **`verpix.php`**: página pública de pagamento, para mandar por WhatsApp. O
  cliente abre, vê o valor e paga, sem login. Protegida por token assinado.
  Ela se veste com o nome e a logo configurados no seu WHMCS.

### Automação

- **Cobrança emitida junto com a fatura.** Quando o WHMCS gera a fatura, o Pix
  e o boleto já são criados e guardados. Na hora de cobrar, o código está
  pronto - e continua o mesmo enquanto valer.
- **Baixa automática conferida na API** antes de quitar a fatura.
- **Conferência diária** que baixa o que o gatilho não confirmou.
- **Cancelamento cruzado**: pagou o Pix, o boleto para de valer, e vice-versa.
- **Valor alterado, cobrança acompanha**: fatura editada no admin faz o módulo
  cancelar a cobrança antiga na Iugu e emitir outra com o valor novo.
- **Estorno** pelo botão do próprio WHMCS.

### Administração

- Aparece em **Sistema ▸ Apps e Integrações** com logo, categoria e descrição.
- **Informações do módulo** na tela de configuração, acima do botão de salvar:
  versão instalada, versão publicada, teste de conexão com a Iugu, suporte e
  páginas do projeto.
- **Multa e juros** de atraso configuráveis.
- **Log de diagnóstico** opcional, fora da pasta publicada.

### Requisitos

- WHMCS 8.x ou 9.x, PHP 8.1+, extensões `curl` e `json`.
- Sem Composer, sem ionCube, sem verificação de licença.

---

## Instalação

São **três coisas** para copiar para a raiz do WHMCS: `modules/`, `includes/`
e `verpix.php`. O passo a passo está em [INSTALACAO.md](INSTALACAO.md).

O arquivo `includes/hooks/iugu.php` é o que faz o módulo trabalhar sozinho -
sem ele o pagamento funciona, mas nada acontece automaticamente.
