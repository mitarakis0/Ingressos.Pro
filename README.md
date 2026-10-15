# Ingressos Pro — SaaS de venda de ingressos

Aplicação PHP 8.1+ e MySQL/MariaDB com integração de cobrança Pix pela AbacatePay v2. A versão inicial inclui catálogo público de eventos, painel protegido, cadastro/edição de eventos, tipos de ingresso e lotes iniciais, checkout, cobrança Pix, cupons, listagem/exportação de participantes, pedidos, ingressos digitais e validação de check-in.

> **Status honesto:** este pacote é uma primeira implementação funcional, não uma certificação de produção. Não foram executados testes de ponta a ponta com uma conta AbacatePay real porque isso exige credenciais suas e uma URL pública HTTPS para webhook. Revise os itens “Limitações conhecidas” antes de publicar.

## Requisitos

- PHP 8.1 ou superior com `pdo_mysql`, `curl`, `mbstring`, `iconv` e `json` habilitados.
- MySQL 8+ ou MariaDB compatível.
- HTTPS obrigatório em produção.
- Conta AbacatePay e chave de API com permissão `TRANSPARENT:CREATE`; configure também a leitura de status quando a conta exigir permissão correspondente.

## Instalação no Windows com XAMPP

1. Extraia a pasta `ingressos-abacatepay` dentro de `C:\xampp\htdocs\`. O arquivo `.htaccess` na raiz bloqueia o acesso web aos arquivos de configuração e diretórios internos; para produção, prefira configurar o DocumentRoot diretamente para a pasta `public`.
2. Abra o XAMPP e inicie Apache e MySQL.
3. Abra `http://localhost/phpmyadmin`, crie o banco `ingressos_abacatepay` com collation `utf8mb4_unicode_ci`, selecione esse banco e importe o arquivo `database/schema.sql`. O arquivo não tenta criar o banco, evitando o erro de permissão MySQL `#1044` em hospedagens que não permitem `CREATE DATABASE`. As tabelas usam `CREATE TABLE IF NOT EXISTS` e nomes exclusivos de chaves estrangeiras para permitir continuar uma importação parcial.
4. Copie `.env.example` para `.env` e preencha `DB_NAME`, `DB_USER`, `DB_PASS` e `APP_URL`. Configure também `ABACATEPAY_API_KEY` e defina um `ABACATEPAY_WEBHOOK_SECRET` longo e aleatório antes de cadastrar o webhook. Em instalação local padrão, o banco pode usar `root` sem senha, mas não use isso em produção.
5. Configure `APP_URL=http://localhost/ingressos-abacatepay/public` para este caminho de pasta. Se usar outro caminho, ajuste-o. Se `APP_URL` estiver vazio, a aplicação tenta detectar automaticamente a pasta `/public` para gerar os links dos arquivos CSS/JS e das páginas.
6. Configure a chave de desenvolvimento AbacatePay em `ABACATEPAY_API_KEY`. Chaves de desenvolvimento simulam pagamentos; a chave de produção movimenta dinheiro real. A API usa a mesma base URL v2 para os dois ambientes, sendo a chave quem determina o modo.
7. No terminal PowerShell do VS Code, entre na pasta `ingressos-abacatepay` e execute o comando abaixo. Se o PHP do XAMPP não estiver no PATH, use o caminho completo mostrado:

   ```powershell
   & "C:\xampp\php\php.exe" scripts/create_admin.php admin@exemplo.com 'UmaSenhaMuitoForte123!' 'Administrador'
   ```

8. Acesse `http://localhost/ingressos-abacatepay/public/admin/login.php`.
9. Crie um evento, adicione tipos de ingresso e publique o evento. O evento só aparece no catálogo público quando estiver publicado.

## Configuração AbacatePay

- Documentação oficial: https://docs.abacatepay.com/
- Endpoint usado para cobrança Pix transparente: `POST https://api.abacatepay.com/v2/transparents/create`.
- A integração usa `Authorization: Bearer <chave>`, `method: PIX`, valor em centavos e `externalId` vinculado ao pedido local.
- A resposta deve fornecer identificador da cobrança, `brCode` e `brCodeBase64`; a aplicação apresenta o QR Code e o código copia e cola retornados.
- Crie o webhook no painel AbacatePay apontando para `https://SEU-DOMINIO/ingressos-abacatepay/public/webhook.php?webhookSecret=SEU_SEGREDO_FORTE` quando estiver hospedado em subpasta. Se o DocumentRoot já apontar para `public`, use `/webhook.php?webhookSecret=SEU_SEGREDO_FORTE`. Configure o mesmo valor em `ABACATEPAY_WEBHOOK_SECRET`. Use HTTPS em produção.
- A implementação exige os dois controles: `webhookSecret` na URL e o header `X-Webhook-Signature` validado por HMAC-SHA256/base64. Confirme a assinatura com um webhook de teste do Dev mode antes de ir para produção.
- Eventos de pagamento tratados: `transparent.completed`/`checkout.completed`, reembolso e disputa. O ID do evento é único para evitar processamento repetido.
- O endpoint de status usado para consulta é `GET /v2/transparents/check?id=...`; verifique na documentação atual e nas permissões da chave se sua conta tem acesso.

## Segurança e produção

- Mantenha `.env` fora do repositório e bloqueie seu acesso via servidor web. Configure permissões restritas para arquivos e diretórios.
- Use um banco dedicado e um usuário MySQL com permissões mínimas; não use `root` em produção.
- Configure HTTPS, backups, logs monitorados, atualizações de PHP/MySQL e proteção contra abuso/rate limit.
- Configure a URL do webhook como endpoint final, sem redirecionamentos.
- Teste webhook, reenvio duplicado, falha de rede, pagamento pendente, pagamento confirmado, expiração e reembolso com a chave de desenvolvimento antes de mudar para produção.
- Não publique o site como pronto para receber dinheiro real antes de concluir esses testes com a conta e domínio configurados.

## Rotas principais

- `public/index.php` — eventos publicados.
- `public/event.php?slug=...` — detalhes e seleção de ingresso.
- `public/checkout.php` — cria pedido e cobrança Pix.
- `public/payment.php?pedido=...` — QR Code e código Pix.
- `public/tickets.php?pedido=...` — ingressos após pagamento confirmado.
- `public/webhook.php` — webhook AbacatePay.
- `public/admin/login.php` — login administrativo.
- `public/admin/dashboard.php` — painel com métricas reais.
- `public/admin/events.php` e `event-form.php` — eventos.
- `public/admin/tickets.php?event_id=ID` — tipos de ingresso e primeiro lote.
- `public/admin/coupons.php` — cupons.
- `public/admin/orders.php` — pedidos.
- `public/admin/participants.php` — participantes e exportação TXT/CSV.
- `public/admin/checkin.php` — validação de código de ingresso.

## Limitações conhecidas desta primeira entrega

1. O checkout agora exige o nome de cada participante selecionado e grava esses nomes antes de criar a cobrança. Ainda faltam campos opcionais/validações adicionais de participante, como CPF, caso o tipo de evento exija esses dados.
2. O checkout considera pedidos pendentes ainda dentro do prazo e libera reservas vencidas ao iniciar novas compras. Ainda é recomendável configurar uma rotina cron e realizar testes de concorrência com carga antes de grandes eventos.
3. O fluxo de lotes é básico: aceita um lote inicial por tipo de ingresso. A administração de múltiplos lotes, transição automática e preços futuros ainda precisa ser concluída.
4. Casadinhas podem ser cadastradas no banco, mas falta o formulário administrativo completo para criá-las e a UX dedicada para coletar nome individual de cada participante.
5. Os cupons têm criação e validação inicial; faltam limites por cliente, restrições completas por lote/tipo e relatório financeiro dedicado.
6. A exportação TXT/CSV, QR visual por código aleatório e check-in por código estão implementados; leitura de câmera/QR diretamente no navegador ainda não está incluída.
7. A plataforma tem estrutura inicial multi-organizador, mas faltam fluxo de cadastro de organizadores, gestão de membros e auditoria/isolamento mais amplo em todos os relatórios.
8. Reembolsos são registrados a partir de eventos de webhook, mas ainda não existe botão integrado para solicitar reembolso pela API.
9. Há verificações estáticas e testes locais de helpers, mas ainda não foram executados testes ponta a ponta com uma conta AbacatePay real/dev. A cobrança, o QR Code, o webhook e o retorno de status precisam ser confirmados com suas credenciais de desenvolvimento e uma URL HTTPS acessível.
10. A política completa de privacidade/LGPD, exclusão de dados, e-mail/WhatsApp transacional, rate limiting de login e uma rotina completa de recuperação de reserva são pendências para produção.

## Próxima etapa recomendada

Concluir os pontos de checkout de participantes, múltiplos lotes, casadinhas, expiração de reservas e testes automatizados; depois realizar testes com credenciais Dev mode e webhook HTTPS temporário antes de configurar produção.
