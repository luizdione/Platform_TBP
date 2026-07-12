<?php
// Pagina inicial (Home)
require_once __DIR__ . '/../../templates/auth_central.php';
exigirArea('diagnostico');
require_once 'config.php';
setSecurityHeaders();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>App Diagnostico - Home</title>
    <style>
        :root { --bg1:#38a169; --bg2:#2f855a; --card:#ffffff; --text:#2d3748; --muted:#718096; --primary:#2f855a; }
        * { box-sizing: border-box; margin:0; padding:0; outline:none; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background:#2f855a; min-height:100vh; display:flex; align-items:center; justify-content:center; padding:12px; font-size:1.56em; }
        .container { width:100%; max-width:1100px; }
        .card { background:var(--card); color:var(--text); border-radius:12px; box-shadow:0 10px 30px rgba(0,0,0,0.12); overflow:hidden; }
        .hero { padding:20px 24px; text-align:center; position:relative; }
        .hero h1 { margin:0 0 6px; font-size:2.5rem; color:#1a202c; }
        .hero p { margin:0; color:var(--muted); font-size:1.4rem; }
        .grid { display:grid; grid-template-columns:repeat(3,1fr); gap:14px; padding:18px; }
        .tile { background:#f7fafc; border:2px solid #e2e8f0; border-radius:10px; padding:14px; text-align:center; transition:all .2s; text-decoration:none; color:inherit; display:flex; flex-direction:column; align-items:center; justify-content:center; min-height:110px; position:relative; }
        .tile:hover { transform:translateY(-2px); border-color:#cbd5e0; background:#fff; box-shadow:0 4px 12px rgba(0,0,0,.08); }
        .tile-dev { background:#fffbeb; border-color:#fde68a; }
        .tile-dev:hover { background:#fff8d6; border-color:#facc15; }
        .tile-dev::before { content:"DEV"; position:absolute; top:6px; right:8px; background:#facc15; color:#78350f; font-size:.65rem; font-weight:700; padding:2px 6px; border-radius:3px; letter-spacing:.05em; }
        .tile h3 { font-size:1.49rem; margin:4px 0; color:#2d3748; }
        .tile p { font-size:1.18rem; color:var(--muted); margin:0; line-height:1.3; }
        .btn { display:inline-flex; align-items:center; gap:6px; padding:8px 14px; border-radius:6px; border:none; cursor:pointer; text-decoration:none; font-weight:600; font-size:1.4rem; }
        .btn-secondary { background:#718096; color:#fff; }
        .btn-logout { position:absolute; top:20px; right:24px; padding:8px 16px; font-size:1.2rem; }
        .hero-logo { position:absolute; top:14px; left:20px; height:72px; width:auto; background:#fff; padding:4px 8px; border-radius:8px; box-shadow:0 2px 6px rgba(0,0,0,0.10); }
        footer { text-align:center; color:#fff; font-size:18px; margin-top:20px; line-height:1.6; }
        @media (max-width:900px){ .grid{ grid-template-columns:repeat(2,1fr); } }
        @media (max-width:600px){ .grid{ grid-template-columns:1fr; } .hero h1{ font-size:1.4rem; } }
    </style>
</head>
<body>
    <div class="container">
        <div class="card">
            <div class="hero">
                <img src="static/img/logo-ifrj.png" alt="IFRJ" class="hero-logo">
                <div class="btn-logout" style="display:flex; gap:8px;">
                    <a class="btn btn-secondary" href="../../templates/login.php">&#8592; Área Interna</a>
                    <a class="btn btn-secondary" href="?logout=1">Sair</a>
                </div>
                <h1>Vigilancia Parasitaria Bovina</h1>
                <p>Bem-vindo! Escolha uma opcao para comecar.</p>
            </div>
            <div class="grid">
                <?php if (!isSomenteLeitura()): ?>
                <a class="tile" href="forms.php">
                    <span style="font-size:24px;">&#x1F52C;</span>
                    <h3>Entrada de Dados</h3>
                    <p>Cadastro de locais, animais, coletas, resultados e dados climaticos</p>
                </a>
                <?php endif; ?>
                <a class="tile" href="dashboard.php">
                    <span style="font-size:24px;">&#x1F4C8;</span>
                    <h3>Analises</h3>
                    <p>Visualizacao de dados, graficos, estatisticas e laudos</p>
                </a>
                <a class="tile" href="variavel_clima.php">
                    <span style="font-size:24px;">&#x1F326;</span>
                    <h3>Variaveis Climaticas</h3>
                    <p>Heatmaps de temperatura, chuva e umidade</p>
                </a>
                <a class="tile" href="pesquisa_animal.php">
                    <span style="font-size:24px;">&#x1F50D;</span>
                    <h3>Pesquisa por Animais</h3>
                    <p>Historico clinico e graficos temporais</p>
                </a>
                <a class="tile" href="relatorio_cientifico.php">
                    <span style="font-size:24px;">&#x1F4CA;</span>
                    <h3>Relatorio Cientifico</h3>
                    <p>Tabela 1 e prevalencia IC95% Wilson para publicacao</p>
                </a>
                <?php if (!isSomenteLeitura()): ?>
                <a class="tile" href="manutencao.php">
                    <span style="font-size:24px;">&#x1F527;</span>
                    <h3>Manutencoes</h3>
                    <p>Testes, analises, backups e ferramentas</p>
                </a>
                <a class="tile" href="gerenciar_locais.php">
                    <span style="font-size:24px;">&#x1F4CD;</span>
                    <h3>Fazendas / Locais</h3>
                    <p>Cadastro e edicao de pontos de coleta</p>
                </a>
                <?php endif; ?>

                <?php
                $devOn = function_exists('isDevAmbiente') && isDevAmbiente();
                if ($devOn): ?>
                <a class="tile tile-dev" href="http://localhost:8081/" target="_blank">
                    <span style="font-size:24px;">&#x1F5C4;</span>
                    <h3>phpMyAdmin local</h3>
                    <p>Banco MariaDB local (porta 3307) via phpMyAdmin web</p>
                </a>
                <a class="tile tile-dev" href="manutencao.php#backup">
                    <span style="font-size:24px;">&#x1F4BE;</span>
                    <h3>Backup / Sync</h3>
                    <p>Download SQL automatizado e manual; integracao GitHub</p>
                </a>
                <a class="tile tile-dev" href="https://lgmifrj.com.br/aplicativos/diagnostico_tpb/" target="_blank">
                    <span style="font-size:24px;">&#x2601;</span>
                    <h3>Sistema Online</h3>
                    <p>Plataforma de producao no Hostinger</p>
                </a>
                <a class="tile tile-dev" href="https://github.com/luizdione/app_tpb" target="_blank">
                    <span style="font-size:24px;">&#x1F419;</span>
                    <h3>Repositorio GitHub</h3>
                    <p>Codigo-fonte publico em luizdione/app_tpb</p>
                </a>
                <?php endif; ?>
            </div>
        </div>
        <footer>
            <div>De-melo, Luiz Dione Barbosa</div>
            <div>App Diagnostico TPB versao 1</div>
            <div>&copy; <?php echo date('Y'); ?> - Copyright</div>
        </footer>
    </div>

    <!-- ===================================================================== -->
    <!-- JANELA DE PENDÊNCIAS (fundo preto, texto vermelho)                     -->
    <!-- ===================================================================== -->
    <div id="overlay-pendencias" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.85); z-index:99999; align-items:center; justify-content:center; padding:20px;">
        <div style="background:#000; border:3px solid #ff1a1a; border-radius:10px; box-shadow:0 0 40px rgba(255,0,0,0.6); max-width:820px; width:100%; max-height:85vh; display:flex; flex-direction:column; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;">
            <div style="padding:16px 22px; border-bottom:2px solid #ff1a1a; display:flex; align-items:center; justify-content:space-between;">
                <h2 style="margin:0; color:#ff1a1a; font-size:1.4em; text-shadow:0 0 8px rgba(255,0,0,0.5);">⚠️ PENDÊNCIAS DE LANÇAMENTO DE DADOS</h2>
                <div style="display:flex; gap:8px;">
                    <button onclick="atualizarPendencias()" style="background:#000; color:#ff1a1a; border:1px solid #ff1a1a; border-radius:6px; padding:8px 14px; font-weight:bold; cursor:pointer; font-size:14px;">🔄 Atualizar</button>
                    <button onclick="fecharPendencias()" style="background:#ff1a1a; color:#000; border:none; border-radius:6px; padding:8px 14px; font-weight:bold; cursor:pointer; font-size:14px;">✖ Fechar</button>
                </div>
            </div>
            <div id="pendencias-resumo" style="padding:14px 22px; color:#ff5555; font-size:14px; line-height:1.6; border-bottom:1px dashed #ff1a1a;"></div>
            <div id="pendencias-lista" style="padding:10px 22px 18px; overflow-y:auto; color:#ff4d4d; font-size:13px; line-height:1.5;"></div>
            <div style="padding:12px 22px; border-top:2px solid #ff1a1a; text-align:center;">
                <span style="color:#ff8888; font-size:12px;">Lance os dados pendentes em
                    <a href="forms.php" style="color:#ff1a1a; font-weight:bold;">Formulários</a>,
                    <a href="pesquisa_animal.php" style="color:#ff1a1a; font-weight:bold;">Pesquisa por Animais</a> e
                    <a href="variavel_clima.php" style="color:#ff1a1a; font-weight:bold;">Variáveis Climáticas</a>.
                </span>
            </div>
        </div>
    </div>

    <script>
        (function () {
            function esc(s){ return String(s == null ? '' : s).replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c])); }

            let usuarioFechou = false;  // o usuário fechou manualmente o aviso
            let ultimoTotal   = null;   // nº de coletas pendentes na última checagem
            let carregando    = false;  // evita requisições sobrepostas
            let ultimaBusca   = 0;      // throttle das atualizações automáticas

            function montarConteudo(d){
                const c = d.contadores || {};
                const linhasResumo = [];
                if (c.sem_data)  linhasResumo.push('🗓️ <strong>' + c.sem_data + '</strong> coleta(s) SEM data informada');
                if (c.clima)     linhasResumo.push('🌦️ <strong>' + c.clima + '</strong> coleta(s) com dados climáticos a gravar');
                if (c.pcr_cat)   linhasResumo.push('🧬 <strong>' + c.pcr_cat + '</strong> resultado(s) de PCR categórico a definir');
                if (c.pcr_tt)    linhasResumo.push('⏱️ <strong>' + c.pcr_tt + '</strong> PCR positivo(s) SEM Tt (tempo de detecção)');
                if (c.lamp_cat)  linhasResumo.push('🧪 <strong>' + c.lamp_cat + '</strong> resultado(s) de PCR-LAMP categórico a definir');
                if (c.lamp_tt)   linhasResumo.push('⏱️ <strong>' + c.lamp_tt + '</strong> PCR-LAMP positivo(s) SEM Tt (tempo de detecção)');
                if (!d.tt_pcr_habilitado) linhasResumo.push('⚠️ Colunas de Tt da PCR ainda NÃO criadas no banco (rodar sql/08_pcr_tempo_deteccao.sql).');

                document.getElementById('pendencias-resumo').innerHTML =
                    '<div style="color:#ff1a1a; font-weight:bold; margin-bottom:6px;">' +
                    d.coletas_pendentes + ' de ' + d.total_coletas + ' coleta(s) com pendências:</div>' +
                    linhasResumo.map(x => '• ' + x).join('<br>');

                let html = '<table style="width:100%; border-collapse:collapse;">';
                html += '<tr style="color:#ff1a1a; border-bottom:1px solid #ff1a1a;">' +
                        '<th style="text-align:left; padding:5px;">Animal</th>' +
                        '<th style="text-align:left; padding:5px;">Fazenda</th>' +
                        '<th style="text-align:left; padding:5px;">Data</th>' +
                        '<th style="text-align:left; padding:5px;">Pendências</th></tr>';
                d.itens.forEach(it => {
                    html += '<tr style="border-bottom:1px solid #330000;">' +
                        '<td style="padding:5px; vertical-align:top; color:#ff8888;">' + esc(it.animal) + '</td>' +
                        '<td style="padding:5px; vertical-align:top; color:#ff8888;">' + esc(it.fazenda) + '</td>' +
                        '<td style="padding:5px; vertical-align:top; color:#ff8888; white-space:nowrap;">' + esc(it.data) + '</td>' +
                        '<td style="padding:5px; vertical-align:top;">' + it.pendencias.map(p => '• ' + esc(p)).join('<br>') + '</td>' +
                        '</tr>';
                });
                html += '</table>';
                document.getElementById('pendencias-lista').innerHTML = html;
            }

            // Busca o estado atual no banco (sem cache) e (re)desenha o aviso.
            // autoAbrir=true tenta abrir; se já estiver aberto, sempre atualiza.
            // Quando as pendências zeram, fecha sozinho.
            function carregarPendencias(autoAbrir, forcar){
                const agora = Date.now();
                if (carregando) return;
                if (!forcar && (agora - ultimaBusca) < 2000) return; // evita rajadas
                carregando = true;
                ultimaBusca = agora;

                fetch('api_pendencias.php?_=' + agora, { cache: 'no-store' })
                    .then(r => r.json())
                    .then(d => {
                        if (!d || !d.sucesso) return;
                        const overlay  = document.getElementById('overlay-pendencias');
                        const total    = d.coletas_pendentes || 0;
                        const jaAberto = overlay.style.display === 'flex';

                        if (total === 0) {
                            // tudo lançado: fecha o aviso e reseta o estado
                            overlay.style.display = 'none';
                            usuarioFechou = false;
                            ultimoTotal = 0;
                            return;
                        }

                        montarConteudo(d);

                        // se surgiram NOVAS pendências, volta a avisar mesmo que tenha sido fechado
                        if (ultimoTotal !== null && total > ultimoTotal) usuarioFechou = false;
                        ultimoTotal = total;

                        if (jaAberto || (autoAbrir && !usuarioFechou)) {
                            overlay.style.display = 'flex';
                        }
                    })
                    .catch(() => { /* silencioso: não atrapalha a página */ })
                    .finally(() => { carregando = false; });
            }

            // Carga inicial
            carregarPendencias(true, true);

            // Atualiza ao voltar para a aba/página (ex.: após salvar em Formulários,
            // Pesquisa por Animais ou Variáveis Climáticas em outra aba)
            document.addEventListener('visibilitychange', function(){
                if (document.visibilityState === 'visible') carregarPendencias(true, false);
            });
            window.addEventListener('focus',   function(){ carregarPendencias(true, false); });
            window.addEventListener('pageshow', function(){ carregarPendencias(true, false); });

            // Atualização automática periódica enquanto a aba está aberta
            setInterval(function(){
                if (document.visibilityState === 'visible') carregarPendencias(false, false);
            }, 30000);

            // Expostas para os botões do aviso (onclick inline)
            window.atualizarPendencias = function(){ carregarPendencias(true, true); };
            window.fecharPendencias = function(){
                document.getElementById('overlay-pendencias').style.display = 'none';
                usuarioFechou = true;
            };
        })();
    </script>
</body>
</html>
