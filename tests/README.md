# Testes e validação

## Teste rápido local

Execute a partir da pasta do projeto:

```powershell
& "C:\xampp\php\php.exe" tests/smoke.php
```

O teste cobre formatação de moeda, criação de slug, escape HTML, conversão de data/hora, rejeição de data inválida e geração de URL.

## Checklist manual com XAMPP e AbacatePay Dev mode

- [ ] Importar `database/schema.sql` em um banco vazio.
- [ ] Criar o administrador com `scripts/create_admin.php`.
- [ ] Entrar no painel e confirmar que as páginas administrativas exigem autenticação.
- [ ] Criar, editar e publicar um evento.
- [ ] Criar ingresso com e sem lote.
- [ ] Criar cupom percentual e cupom de valor fixo; verificar o valor final.
- [ ] Comprar ingresso com nome de cada participante preenchido.
- [ ] Criar cobrança usando chave Dev mode da AbacatePay.
- [ ] Confirmar que o QR Code e o código Pix aparecem na página de pagamento.
- [ ] Simular pagamento e verificar webhook `transparent.completed`.
- [ ] Reenviar o mesmo webhook e confirmar que não há ingressos duplicados.
- [ ] Confirmar que é emitido um ingresso por participante.
- [ ] Exportar TXT/CSV e conferir acentos e conteúdo.
- [ ] Validar ingresso e tentar realizar o mesmo check-in novamente.
- [ ] Testar pedidos expirados, cupons esgotados, estoque esgotado e falhas da API.

Os testes de pagamento exigem credenciais Dev mode e um webhook acessível por HTTPS/túnel. Não foram simulados neste ambiente de edição.
