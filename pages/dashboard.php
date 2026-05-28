<?php
/**
 * OS Master - Painel de Controle (Dashboard) Operacional e Dinâmico
 * * Este ficheiro exibe as principais métricas de desempenho em tempo real,
 * incluindo faturamento mensal, volume de Ordens de Serviço, alertas de estoque,
 * atalhos de operações comuns e listagem das atividades mais recentes.
 * * @package OSMaster
 * @author Julian C. Braga
 * @version 1.6
 */

// Impede o acesso direto a este ficheiro fora do index.php
if (!defined('BASE_PATH')) {
    header("Location: ../index.php");
    exit;
}

// Verificação de segurança: apenas utilizadores autenticados podem aceder
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php?page=login");
    exit;
}

// Auxiliares locais de moeda para evitar conflito de redeclarações
if (!function_exists('formatMoneyDashboard')) {
    function formatMoneyDashboard($value): string {
        return number_format((float)$value, 2, ',', '.');
    }
}

// Recupera símbolo monetário dinâmico do sistema
$currencySymbol = 'R$';
try {
    $stmtCurrency = $pdo->query("SELECT * FROM config_sistema LIMIT 1");
    $sysConfig = $stmtCurrency->fetch();
    if ($sysConfig && isset($sysConfig['moeda']) && !empty($sysConfig['moeda'])) {
        $currencySymbol = $sysConfig['moeda'];
    }
} catch (PDOException $e) {
    // Fallback silencioso
}

// Inicialização de métricas padrão
$kpis = [
    'os_abertas_mes'  => 0,
    'os_concluidas'   => 0,
    'os_pendentes'    => 0,
    'faturamento_mes' => 0.00,
    'estoque_alerta'  => 0
];

$atividadesRecentes = [];
$produtosAlertaList = [];

// Definição de Meta Mensal de Faturação (Simulada para fins de KPIs visuais)
$metaFaturacaoMensal = 15000.00; 

try {
    // 1. KPI: Total de Ordens de Serviço abertas no mês vigente
    $stmtOSMes = $pdo->query("
        SELECT COUNT(*) FROM os 
        WHERE MONTH(data_abertura) = MONTH(CURRENT_DATE()) 
          AND YEAR(data_abertura) = YEAR(CURRENT_DATE())
    ");
    $kpis['os_abertas_mes'] = (int)$stmtOSMes->fetchColumn();

    // 2. KPI: Ordens de Serviço concluídas e faturadas no mês vigente
    $stmtOSConcluidas = $pdo->query("
        SELECT COUNT(*) FROM os 
        WHERE status IN ('finalizada', 'reparo_concluido', 'pronto_para_retirada', 'entregue')
          AND MONTH(IFNULL(updated_at, data_abertura)) = MONTH(CURRENT_DATE())
          AND YEAR(IFNULL(updated_at, data_abertura)) = YEAR(CURRENT_DATE())
    ");
    $kpis['os_concluidas'] = (int)$stmtOSConcluidas->fetchColumn();

    // 3. KPI: Ordens de Serviço ativas / pendentes de resolução técnica
    $stmtOSPendentes = $pdo->query("
        SELECT COUNT(*) FROM os 
        WHERE status IN ('aguardando_analise', 'em_diagnostico', 'aguardando_aprovacao', 'aguardando_peca', 'em_reparo')
    ");
    $kpis['os_pendentes'] = (int)$stmtOSPendentes->fetchColumn();

    // 4. KPI: Faturamento Bruto total acumulado no mês vigente
    $stmtFaturamento = $pdo->query("
        SELECT SUM(valor_total) FROM os 
        WHERE status IN ('finalizada', 'reparo_concluido', 'pronto_para_retirada', 'entregue')
          AND MONTH(IFNULL(updated_at, data_abertura)) = MONTH(CURRENT_DATE())
          AND YEAR(IFNULL(updated_at, data_abertura)) = YEAR(CURRENT_DATE())
    ");
    $kpis['faturamento_mes'] = (float)$stmtFaturamento->fetchColumn();

    // 5. Alerta de Estoque Crítico (Produtos com estoque <= 3 unidades)
    $stmtEstoque = $pdo->query("SELECT COUNT(*) FROM produto WHERE estoque <= 3");
    $kpis['estoque_alerta'] = (int)$stmtEstoque->fetchColumn();

    // 6. Lista os produtos em situação de estoque crítico
    if ($kpis['estoque_alerta'] > 0) {
        $produtosAlertaList = $pdo->query("SELECT id_produto, descricao, estoque FROM produto WHERE estoque <= 3 ORDER BY estoque ASC LIMIT 5")->fetchAll();
    }

    // 7. Lista as últimas 5 Ordens de Serviço movimentadas para o bloco de Atividades Recentes
    $sqlAtividades = "
        SELECT o.id_os, o.data_abertura, o.status, o.valor_total,
               pCli.nome AS cliente_nome,
               e.aparelho, e.marca
        FROM os o
        INNER JOIN cliente c ON o.id_cliente = c.id_pessoa
        INNER JOIN pessoa pCli ON c.id_pessoa = pCli.id_pessoa
        INNER JOIN equipamento e ON o.id_equipamento = e.id_equipamento
        ORDER BY o.id_os DESC LIMIT 5
    ";
    $atividadesRecentes = $pdo->query($sqlAtividades)->fetchAll();

} catch (PDOException $e) {
    // Tratamento silencioso para evitar quebras em atualizações estruturais
}

// Método local para renderizar badges de status consistentes
if (!function_exists('getDashboardStatusBadge')) {
    function getDashboardStatusBadge($status): string {
        switch ($status) {
            case 'aguardando_analise':
                return '<span class="badge" style="background-color: rgba(148, 163, 184, 0.1); color: #94a3b8;">Aguardando Análise</span>';
            case 'em_diagnostico':
                return '<span class="badge" style="background-color: rgba(59, 130, 246, 0.1); color: #3b82f6;">Em Diagnóstico</span>';
            case 'aguardando_aprovacao':
                return '<span class="badge" style="background-color: rgba(245, 158, 11, 0.1); color: #f59e0b;">Aguardando Aprovação</span>';
            case 'aguardando_peca':
                return '<span class="badge" style="background-color: rgba(139, 92, 246, 0.1); color: #8b5cf6;">Aguardando Peça</span>';
            case 'em_reparo':
                return '<span class="badge" style="background-color: rgba(249, 115, 22, 0.1); color: #f97316;">Em Reparo</span>';
            case 'reparo_concluido':
                return '<span class="badge" style="background-color: rgba(16, 185, 129, 0.1); color: #10b981;">Reparo Concluído</span>';
            case 'sem_conserto':
                return '<span class="badge" style="background-color: rgba(239, 68, 68, 0.1); color: #ef4444;">Sem conserto retirar</span>';
            case 'pronto_para_retirada':
                return '<span class="badge" style="background-color: rgba(16, 185, 129, 0.15); color: #10b981; font-weight: bold;">Pronto para Retirada</span>';
            case 'entregue':
                return '<span class="badge" style="background-color: rgba(16, 185, 129, 0.2); color: #10b981; font-weight: bold;">Entregue</span>';
            case 'abandonado':
                return '<span class="badge" style="background-color: rgba(239, 68, 68, 0.2); color: #ef4444; font-weight: bold;">Abandonado</span>';
            case 'descarte':
                return '<span class="badge" style="background-color: rgba(239, 68, 68, 0.2); color: #ef4444; font-weight: bold;">Descarte</span>';
            default:
                return '<span class="badge" style="background-color: rgba(148, 163, 184, 0.1); color: #94a3b8;">' . htmlspecialchars(ucfirst($status)) . '</span>';
        }
    }
}

// Calcula percentagem de atingimento de meta de faturação
$percentagemMeta = $metaFaturacaoMensal > 0 ? min(100, round(($kpis['faturamento_mes'] / $metaFaturacaoMensal) * 100)) : 0;
?>

<!-- Saudação Dinâmica e Barra de Atalhos Rápidos -->
        <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 20px; margin-bottom: 30px;">
            <div>
                <h1 style="font-size: 24px; font-weight: 800; color: var(--text-main); margin-bottom: 6px;">Bem-vindo ao OS Master</h1>
                <p style="color: var(--text-muted); font-size: 14.5px;">Resumo operacional e de faturamento do período contabilístico atual.</p>
            </div>
            <!-- ATALHOS RÁPIDOS OPERACIONAIS -->
            <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                <a href="index.php?page=os&action=create" class="btn btn-primary" style="padding: 10px 16px; font-size: 12.5px; text-decoration: none; font-weight: bold; border-radius: var(--radius);">
                    + Abrir OS
                </a>
                <a href="index.php?page=clientes&action=create" class="btn btn-secondary" style="padding: 10px 16px; font-size: 12.5px; text-decoration: none; font-weight: bold; border-radius: var(--radius); background-color: var(--bg-card); border: 1px solid var(--border);">
                    + Novo Cliente
                </a>
                <?php if ($_SESSION['user_role'] === 'Administrador'): ?>
                    <a href="index.php?page=produtos&action=create" class="btn btn-secondary" style="padding: 10px 16px; font-size: 12.5px; text-decoration: none; font-weight: bold; border-radius: var(--radius); background-color: var(--bg-card); border: 1px solid var(--border);">
                        + Cadastrar Peça
                    </a>
                <?php endif; ?>
            </div>
        </div>

        <!-- Dashboard de KPIs Rápidos -->
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 32px;">
            
            <!-- KPI: Faturamento Bruto Mensal -->
            <div class="card" style="border-left: 4px solid #10b981; margin-bottom: 0;">
                <span style="font-size: 11.5px; font-weight: 700; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px;">Faturamento Bruto</span>
                <h3 style="font-size: 22px; font-weight: 800; color: #10b981; margin-top: 8px;">
                    <?php echo htmlspecialchars($currencySymbol) . ' ' . formatMoneyDashboard($kpis['faturamento_mes']); ?>
                </h3>
                <p style="font-size: 11.5px; color: var(--text-muted); margin-top: 4px;"><?php echo $kpis['os_concluidas']; ?> OS faturadas este mês.</p>
            </div>

            <!-- KPI: Ordens de Serviço Novas no Mês -->
            <div class="card" style="border-left: 4px solid var(--primary); margin-bottom: 0;">
                <span style="font-size: 11.5px; font-weight: 700; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px;">Chamados no Mês</span>
                <h3 style="font-size: 22px; font-weight: 800; color: var(--primary); margin-top: 8px;">
                    <?php echo $kpis['os_abertas_mes']; ?> <span style="font-size: 14px; font-weight: 500; color: var(--text-muted);">OS</span>
                </h3>
                <p style="font-size: 11.5px; color: var(--text-muted); margin-top: 4px;">Total de novos chamados abertos.</p>
            </div>

            <!-- KPI: Ordens de Serviço Pendentes de Resolução -->
            <div class="card" style="border-left: 4px solid var(--warning); margin-bottom: 0;">
                <span style="font-size: 11.5px; font-weight: 700; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px;">OS Pendentes</span>
                <h3 style="font-size: 22px; font-weight: 800; color: var(--warning); margin-top: 8px;">
                    <?php echo $kpis['os_pendentes']; ?> <span style="font-size: 14px; font-weight: 500; color: var(--text-muted);">Ativas</span>
                </h3>
                <p style="font-size: 11.5px; color: var(--text-muted); margin-top: 4px;">Aguardando laudo ou reparação.</p>
            </div>

            <!-- KPI: Alertas de Inventário / Estoque Baixo -->
            <div class="card" style="border-left: 4px solid #ef4444; margin-bottom: 0; <?php echo ($kpis['estoque_alerta'] > 0) ? 'background-color: rgba(239, 68, 68, 0.02);' : ''; ?>">
                <span style="font-size: 11.5px; font-weight: 700; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px;">Estoque Alerta</span>
                <h3 style="font-size: 22px; font-weight: 800; color: #ef4444; margin-top: 8px;">
                    <?php echo $kpis['estoque_alerta']; ?> <span style="font-size: 14px; font-weight: 500; color: var(--text-muted);">Itens</span>
                </h3>
                <p style="font-size: 11.5px; color: var(--text-muted); margin-top: 4px;">Produtos com 3 ou menos unidades.</p>
            </div>

        </div>

        <!-- Meta Mensal de Faturamento Visual Card -->
        <div class="card" style="margin-bottom: 32px; background: linear-gradient(135deg, rgba(37, 99, 235, 0.02) 0%, rgba(16, 185, 129, 0.02) 100%);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; flex-wrap: wrap; gap: 10px;">
                <div>
                    <h4 style="font-size: 14px; font-weight: 700; color: var(--text-main); text-transform: uppercase; margin: 0;">Acompanhamento da Meta Mensal</h4>
                    <span style="font-size: 12px; color: var(--text-muted);">Meta estipulada para o mês vigente: <strong><?php echo htmlspecialchars($currencySymbol) . ' ' . formatMoneyDashboard($metaFaturacaoMensal); ?></strong></span>
                </div>
                <span style="font-size: 16px; font-weight: 800; color: #10b981;"><?php echo $percentagemMeta; ?>% Atingido</span>
            </div>
            <!-- Barra de Progresso HTML5 com Tailwind inline-styles para compatibilidade -->
            <div style="width: 100%; height: 12px; background-color: var(--border); border-radius: 999px; overflow: hidden; display: flex;">
                <div style="width: <?php echo $percentagemMeta; ?>%; height: 100%; background: linear-gradient(90deg, var(--primary) 0%, #10b981 100%); transition: width 0.5s ease-in-out; border-radius: 999px;"></div>
            </div>
        </div>

        <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 32px; align-items: start;">
            
            <!-- Bloco: Atividades Recentes e Últimas Movimentações -->
            <div class="card">
                <h2 class="card-title">Últimas Movimentações de Ordens de Serviço</h2>
                
                <?php if (empty($atividadesRecentes)): ?>
                    <p style="color: var(--text-muted); font-size: 14px; padding: 12px 0;">Nenhuma Ordem de Serviço lançada na base de dados.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table" style="font-size: 13.5px;">
                            <thead>
                                <tr>
                                    <th style="width: 70px;">ID</th>
                                    <th>Cliente</th>
                                    <th>Equipamento</th>
                                    <th>Estado</th>
                                    <th style="text-align: right;">Total</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($atividadesRecentes as $os): ?>
                                    <tr>
                                        <td><code>#<?php echo $os['id_os']; ?></code></td>
                                        <td><strong><?php echo htmlspecialchars($os['cliente_nome']); ?></strong></td>
                                        <td><?php echo htmlspecialchars($os['aparelho'] . ' (' . $os['marca'] . ')'); ?></td>
                                        <td><?php echo getDashboardStatusBadge($os['status']); ?></td>
                                        <td style="text-align: right; font-weight: 700; color: var(--text-main);">
                                            <?php echo htmlspecialchars($currencySymbol) . ' ' . formatMoneyDashboard($os['valor_total']); ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div style="text-align: right; margin-top: 16px;">
                        <a href="index.php?page=os" style="font-size: 12.5px; font-weight: 700; color: var(--primary); text-decoration: none;">Ver todas as Ordens de Serviço →</a>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Bloco: Detalhamento de Alertas de Estoque Crítico -->
            <div class="card">
                <h2 class="card-title" style="color: #ef4444; border-bottom: 1px solid var(--border-color); padding-bottom: 12px; margin-bottom: 12px;">Alertas de Reposição</h2>
                
                <?php if (empty($produtosAlertaList)): ?>
                    <div style="text-align: center; padding: 24px 0;">
                        <span style="font-size: 32px; display: block; margin-bottom: 8px;">✔️</span>
                        <span style="font-size: 13px; color: var(--text-muted); font-weight: 600; display: block;">Estoque em dia!</span>
                        <span style="font-size: 11.5px; color: var(--text-muted);">Nenhum produto em nível crítico de reposição.</span>
                    </div>
                <?php else: ?>
                    <p style="font-size: 12px; color: var(--text-muted); margin-bottom: 16px; line-height: 1.4;">Os seguintes produtos do seu inventário atingiram o limite mínimo de estoque e requerem atenção imediata de compras:</p>
                    <div style="display: flex; flex-direction: column; gap: 12px;">
                        <?php foreach ($produtosAlertaList as $p): ?>
                            <div style="display: flex; justify-content: space-between; align-items: center; background-color: rgba(239, 68, 68, 0.03); border: 1px solid rgba(239, 68, 68, 0.1); border-radius: var(--radius-sm); padding: 12px 16px;">
                                <div style="max-width: 70%;">
                                    <strong style="font-size: 13px; color: var(--text-main); display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;"><?php echo htmlspecialchars($p['descricao']); ?></strong>
                                    <span style="font-size: 11px; color: var(--text-muted);">Código: #<?php echo $p['id_produto']; ?></span>
                                </div>
                                <div style="text-align: right;">
                                    <span style="font-size: 11px; color: #ef4444; font-weight: 700; text-transform: uppercase; display: block;">Qtd. Atual</span>
                                    <strong style="font-size: 16px; color: #ef4444;"><?php echo $p['estoque']; ?> un.</strong>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div style="text-align: right; margin-top: 16px; border-top: 1px solid var(--border-color); padding-top: 12px;">
                        <a href="index.php?page=produtos" style="font-size: 12.5px; font-weight: 700; color: var(--primary); text-decoration: none;">Gerenciar Inventário →</a>
                    </div>
                <?php endif; ?>
