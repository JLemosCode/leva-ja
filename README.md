# Leva Já — plataforma de entregas (PHP 8.1+ · React · MySQL · Asaas)

## Estrutura

| Pasta | Função |
|-------|--------|
| `backend/` | API REST `/v1` em PHP puro (sem Composer), MySQL 8 |
| `frontend/` | Frontend **web** React + Vite (PWA), mapa Leaflet/OSM |
| `deploy/hostinger/` | Instruções do pacote de instalação |
| `scripts/` | Geração do instalador Hostinger |

> **App vs web:** hoje o cliente é o frontend web. A pasta `app/` no deploy Hostinger é o **backend PHP** (fora do public_html), não um app mobile. Um app nativo/híbrido (Capacitor) pode reutilizar o mesmo frontend e a mesma API depois.

## Desenvolvimento local

### Pré-requisitos

- [Docker Desktop](https://www.docker.com/products/docker-desktop/) (backend + MySQL)
- Node.js 18+ e npm (frontend)

### 1. Backend (Docker)

Na raiz do projeto:

```bash
docker compose up -d --build
```

Sobe:

- **API** em http://localhost:8000  
- **MySQL** em `localhost:3306` (`levaja` / `levaja` / senha `levaja`)  
- **tick** (dispatcher) em loop, equivalente ao cron da Hostinger  

Schema é importado na primeira subida do volume MySQL.

Criar admin (primeira vez):

```bash
docker compose exec api php bin/create-admin.php voce@email.com SenhaForte123 "Seu Nome"
```

Asaas (opcional no `.env` da raiz ou variáveis do shell):

```bash
# Windows PowerShell
$env:ASAAS_API_KEY="sua_chave"
$env:ASAAS_WEBHOOK_TOKEN="seu_token"
docker compose up -d
```

Parar:

```bash
docker compose down
```

### 2. Frontend web (npm)

```bash
cd frontend
npm install
npm run dev
```

Abra http://localhost:5173 — o Vite faz proxy de `/api` → `http://localhost:8000`.

Build de produção:

```bash
cd frontend
npm run build
```

## Instalador Hostinger (`app` + frontend web)

Gera um zip com a árvore pronta para upload:

```
dist/hostinger/
├── INSTALL.md
├── database/schema.sql
├── app/                 → /home/USUARIO/app/
│   ├── src/
│   ├── bin/
│   └── .env.example
└── public_html/         → document root do site
    ├── index.html …     (frontend web)
    └── api/             (index.php + .htaccess)
```

**Windows (PowerShell):**

```powershell
.\scripts\pack-hostinger.ps1
```

**Linux / macOS:**

```bash
chmod +x scripts/pack-hostinger.sh
./scripts/pack-hostinger.sh
```

Saída: `dist/leva-ja-hostinger.zip` e pasta `dist/hostinger/`.

### Deploy na Hostinger

1. Importe `database/schema.sql` no MySQL (phpMyAdmin).
2. Envie `app/` para `/home/USUARIO/app/` e o conteúdo de `public_html/` para o document root.
3. Copie `app/.env.example` → `app/.env` e preencha (`JWT_SECRET`, `DB_*`, Asaas).
4. PHP 8.1+ com `pdo_mysql` e `curl`; ative SSL.
5. Admin (SSH): `php /home/USUARIO/app/bin/create-admin.php voce@email.com SenhaForte123 "Seu Nome"`
6. Cron (a cada minuto): `php /home/USUARIO/app/bin/tick.php`
7. Webhook Asaas: `https://SEUDOMINIO/api/v1/webhooks/asaas` (token = `ASAAS_WEBHOOK_TOKEN`).

Detalhes: `deploy/hostinger/INSTALL.md` (também vai dentro do zip).

## Pontos importantes

- **Taxa**: Painel admin → Configurações → "Taxa da plataforma" (padrão 100 centavos = R$ 1,00).
- **Cartão**: CVV pedido a cada compra; nada é gravado. O servidor ainda repassa os dados ao Asaas (escopo PCI); para reduzir, use o checkout hospedado do Asaas.
- **Débito**: não implementado (a API do Asaas é para crédito/Pix/boleto).
- **Mapas/endereços**: OSM/Nominatim públicos não são para produção comercial; configure `VITE_TILE_URL` e `NOMINATIM_URL`.
- **GPS em segundo plano**: o navegador não garante envio a cada 5 s; para isso, empacote o PWA (Capacitor) como app.
- **API para parceiros**: Conta → "Gerar chave de API" → cabeçalho `X-Api-Key`.
