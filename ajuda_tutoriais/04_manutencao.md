# 🔧 Painel de Manutenção

A tela `manutencao.php` reúne ferramentas administrativas em abas. Acesso restrito a
usuários **admin** (leitores são bloqueados automaticamente).

## Abas disponíveis

### 📊 Visão Geral
Indicadores globais do sistema: totais de locais, animais, coletas, resultados PCR/LAMP,
dados climáticos. Inclui status da conexão com o banco.

### 🗄️ Estrutura
Inventário de tabelas, contagem de linhas, definição de colunas e índices. Útil
para auditoria rápida do schema.

### 📈 Dados
Resumo de qualidade: animais sem coleta, coletas sem resultado, locais sem coordenadas.

### 🌤️ Clima
Cobertura de dados climáticos por local e operações de carga NASA POWER (90 dias
antes da coleta) e grade fina (pixel cache).

### ✅ Integridade
Roda o **autodiagnóstico** (`api_autodiagnostico.php`) e o **teste de fluxo
climático** (`api_teste_fluxo_clima.php`).

### 💾 Backup
Exportação do banco (SQL/JSON/CSV) e seção avançada de operações DEV (download/upload
Hostinger, push GitHub, carga NASA POWER). Esta seção exige senha adicional em
ambiente local.

### ⚙️ Funções
Lista das funções do `config.php` (`getDBConnection`, `setSecurityHeaders`,
`validarMetodo`, `jsonResponse`, `logErro`, `sanitizeInput`, etc.) e seu status.

### 📋 Tabelas
Estrutura detalhada (DESCRIBE) de cada tabela e índices secundários.

### ❓ Ajuda
Esta seção — tutoriais e referências consolidados.

## Operações sensíveis

- **Backup completo** — gera dump `.sql` para download. Não bloqueia o banco.
- **Migrações SQL** — aplicar somente via `aplicar_migracoes.bat` ou pelo
  phpMyAdmin do Hostinger, nunca por aqui.
- **Limpeza de arquivos** — use `limpar_diretorio.bat` no PowerShell do Windows.

## Quando algo falha

1. Veja **Visão Geral** — a conexão com o banco está OK?
2. Veja **Integridade → Autodiagnóstico** — quais tabelas têm problemas?
3. Consulte os **Logs** (em `logs/`) — erros recentes ficam ali.
4. Como último recurso, restaure backup pelo `sincronizar_hostinger.bat use-backup ...`.
