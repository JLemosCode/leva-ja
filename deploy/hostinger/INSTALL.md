# Instalação Hostinger — Leva Já

Pacote gerado com `app/` (API fora do webroot) e `public_html/` (frontend web + API pública).

## Layout

| Destino na Hostinger | Conteúdo do pacote |
|----------------------|--------------------|
| `/home/USUARIO/app/` | pasta `app/` |
| `/home/USUARIO/public_html/` | pasta `public_html/` (sobrescreve o site) |

> Em planos com domínio em subpasta, ajuste o caminho de `public_html` conforme o hPanel.

## Passo a passo

1. **Banco MySQL** — hPanel → Bancos de dados → criar banco/usuário; no phpMyAdmin importe `database/schema.sql`.
2. **Upload** — envie `app/` para fora do `public_html` e o conteúdo de `public_html/` para o document root do site.
3. **Configuração** — em `app/`, copie `.env.example` para `.env` e preencha:
   - `DB_*` com os dados do MySQL
   - `JWT_SECRET` (`php -r "echo bin2hex(random_bytes(32));"`)
   - `ASAAS_*` (comece no sandbox)
   - `APP_ORIGIN` vazio se front e API forem no mesmo domínio
4. **PHP** — ative PHP 8.1+ com `pdo_mysql` e `curl`; ative SSL.
5. **Admin** (SSH):  
   `php /home/USUARIO/app/bin/create-admin.php voce@email.com SenhaForte123 "Seu Nome"`
6. **Cron** (a cada minuto):  
   `php /home/USUARIO/app/bin/tick.php`
7. **Webhook Asaas** — URL `https://SEUDOMINIO/api/v1/webhooks/asaas`, token = `ASAAS_WEBHOOK_TOKEN`.

## O que é o quê

- **Frontend web**: SPA em `public_html/` (React build).
- **API (`app`)**: código PHP privado; só `public_html/api/` fica exposto.
- **App mobile** (opcional, futuro): o mesmo frontend pode ser empacotado com Capacitor; a API continua a mesma.
