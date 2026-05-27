<?php
/**
 * OS Master - Painel de Relatórios de Lucratividade e Desempenho
 * * Este ficheiro implementa relatórios contabilísticos estruturados e dinâmicos.
 * Consolida dados de faturação, custo de inventário, folhas salariais e gera
 * rankings de produtividade por período (mês/ano).
 * * @package OSMaster
 * @author Julian C. Braga
 * @version 1.0
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

// Restrição de Perfil (ACL): Apenas Administradores podem visualizar relatórios financeiros
if ($_SESSION['user_role'] !== 'Administrador') {
    echo "<div class='page-container'>";
    echo "<div class='card' style='border-color: var(--danger); background-color: rgba(239, 68, 68, 0.05);'>";
    echo "<h3 style='color: var(--danger); font-weight: 700; margin-bottom: 10px;'>Acesso Negado</h3>";
    echo "<p style='color: var(--text-muted);'>Lamentamos, mas não possui permissões administrativas para aceder aos Relatórios Financeiros.</p>";
    echo "<p style='margin-top: 15px;'><a href='index.php?page=dashboard' class='btn btn-secondary'>Voltar ao Dashboard</a></p>";
    echo "</div></div>";
    exit;
}

// Auxiliares locais de moeda
if (!function_exists('formatDecimalRelatorio')) {
    function formatDecimalRelatorio($value): string {
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

$message = '';
$messageType = '';

// Captura mês e ano de referência selecionados (Padrão: mês e ano atual)
$mesRef = filter_input(INPUT_GET, 'mes', FILTER_VALIDATE_INT) ?: (int)date('m');
$anoRef = filter_input(INPUT_GET, 'ano', FILTER_VALIDATE_INT) ?: (int)date('Y');

// ==========================================================================
// Processamento de Consultas Contabilísticas Consolidadas
// ==========================================================================
$faturamentoBruto = 0.00;
$totalMaoObra      = 0.00;
$totalPecasVendidas= 0.00;
$totalOSFinalizadas= 0;
$custoAquisicaoPecas= 0.00;
$comissoesGerais    = 0.00;
$tecnicosRanking   = [];

try {
    // 1. Consolida totais faturados nas Ordens de Serviço finalizadas no período
    $sqlOS = "
        SELECT 
            SUM(valor_total) as faturado_total,
            SUM(valor_pecas) as pecas_total,
            SUM(valor_mao_obra) as mao_obra_total,
            COUNT(id_os) as total_os
        FROM os
        WHERE status IN ('finalizada', 'reparo_concluido', 'pronto_para_retirada', 'entregue')
          AND MONTH(IFNULL(updated_at, data_abertura)) = :mes
          AND YEAR(IFNULL(updated_at, data_abertura)) = :ano
    ";
    $stmtOS = $pdo->prepare($sqlOS);
    $stmtOS->execute([':mes' => $mesRef, ':ano' => $anoRef]);
    $resOS = $stmtOS->fetch();

    if ($resOS) {
        $faturamentoBruto  = (float)$resOS['faturado_total'];
        $totalPecasVendidas = (float)$resOS['pecas_total'];
        $totalMaoObra       = (float)$resOS['mao_obra_total'];
        $totalOSFinalizadas = (int)$resOS['total_os'];
    }

    // 2. Calcula o preço de aquisição (custo real) das peças aplicadas nas OS finalizadas no período
    $sqlCusto = "
        SELECT SUM(i.quantidade * p.custo) as custo_total
        FROM item_os i
        INNER JOIN os o ON i.id_os = o.id_os
        INNER JOIN produto p ON i.id_produto = p.id_produto
        WHERE o.status IN ('finalizada', 'reparo_concluido', 'pronto_para_retirada', 'entregue')
          AND MONTH(IFNULL(o.updated_at, o.data_abertura)) = :mes
          AND YEAR(IFNULL(o.updated_at, o.data_abertura)) = :ano
    ";
    $stmtCusto = $pdo->prepare($sqlCusto);
    $stmtCusto->execute([':mes' => $mesRef, ':ano' => $anoRef]);
    $custoAquisicaoPecas = (float)$stmtCusto->fetchColumn();

    // 3. Obtém comissões lançadas na folha de pagamento para o período
    $sqlComissao = "
        SELECT SUM(total_comissao) as comissoes_total
        FROM folha_pagto
        WHERE mes_referencia = :mes AND ano_referencia = :ano
    ";
    $stmtComissao = $pdo->prepare($sqlComissao);
    $stmtComissao->execute([':mes' => $mesRef, ':ano' => $anoRef]);
    $comissoesGerais = (float)$stmtComissao->fetchColumn();

    // 4. Ranking de produtividade dos Técnicos (Total faturado por Técnico em OS finalizadas)
    $sqlRanking = "
        SELECT p.nome, COUNT(o.id_os) as total_os, SUM(o.valor_total) as faturado
        FROM os o
        INNER JOIN funcionario f ON o.id_tecnico = f.id_pessoa
        INNER JOIN pessoa p ON f.id_pessoa = p.id_pessoa
        WHERE o.status IN ('finalizada', 'reparo_concluido', 'pronto_para_retirada', 'entregue')
          AND MONTH(IFNULL(o.updated_at, o.data_abertura)) = :mes
          AND YEAR(IFNULL(o.updated_at, o.data_abertura)) = :ano
        GROUP BY f.id_pessoa
        ORDER BY faturado DESC
    ";
    $stmtRanking = $pdo->prepare($sqlRanking);
    $stmtRanking->execute([':mes' => $mesRef, ':ano' => $anoRef]);
    $tecnicosRanking = $stmtRanking->fetchAll();

    // 5. Lucro Bruto e Líquido Cálculos
    // Lucro de Peças = Peças Vendidas - Custo de Aquisição
    $lucroPecas = $totalPecasVendidas - $custoAquisicaoPecas;
    // Lucro Líquido Real = Faturamento Bruto - Custo Peças - Comissões Pagas
    $lucroLiquido = $faturamentoBruto - $custoAquisicaoPecas - $comissoesGerais;

} catch (PDOException $e) {
    $message = 'Erro ao processar o fechamento de faturamento: ' . $e->getMessage();
    $messageType = 'danger';
}

$mesesNomes = [
    1 => 'Janeiro', 2 => 'Fevereiro', 3 => 'Março', 4 => 'Abril',
    5 => 'Maio', 6 => 'Junho', 7 => 'Julho', 8 => 'Agosto',
    9 => 'Setembro', 10 => 'Outubro', 11 => 'Novembro', 12 => 'Dezembro'
];
?>

<?php if (!empty($message)): ?>
            <div class="alert alert-<?php echo $messageType; ?>" style="margin-bottom: 24px;">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <!-- Filtro de Período -->
        <div class="card" style="margin-bottom: 32px;">
            <h2 class="card-title">Selecionar Período de Apuração</h2>
            <form method="GET" action="index.php" style="display: flex; gap: 16px; align-items: flex-end;">
                <input type="hidden" name="page" value="relatorios">
                
                <div class="form-group" style="margin-bottom: 0; flex-grow: 1;">
                    <label for="mes" class="form-label">Mês de Apuração</label>
                    <select id="mes" name="mes" class="form-control">
                        <?php foreach ($mesesNomes as $num => $nome): ?>
                            <option value="<?php echo $num; ?>" <?php echo ($num === $mesRef) ? 'selected' : ''; ?>>
                                <?php echo $nome; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group" style="margin-bottom: 0; flex-grow: 1;">
                    <label for="ano" class="form-label">Ano de Apuração</label>
                    <select id="ano" name="ano" class="form-control">
                        <?php for ($y = (int)date('Y') - 5; $y <= (int)date('Y') + 1; $y++): ?>
                            <option value="<?php echo $y; ?>" <?php echo ($y === $anoRef) ? 'selected' : ''; ?>>
                                <?php echo $y; ?>
                            </option>
                        <?php endfor; ?>
                    </select>
                </div>

                <button type="submit" class="btn btn-primary" style="padding: 12px 24px;">Gerar Demonstrativo</button>
            </form>
        </div>

        <!-- Dashboard de KPIs Financeiros -->
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 20px; margin-bottom: 32px;">
            
            <!-- KPI: Faturamento Bruto -->
            <div class="card" style="border-left: 4px solid var(--primary); margin-bottom: 0;">
                <span style="font-size: 11.5px; font-weight: 700; color: var(--text-muted); text-transform: uppercase;">Faturação Bruta</span>
                <h3 style="font-size: 22px; font-weight: 800; color: var(--text-main); margin-top: 6px;">
                    <?php echo htmlspecialchars($currencySymbol) . ' ' . formatDecimalRelatorio($faturamentoBruto); ?>
                </h3>
                <p style="font-size: 11px; color: var(--text-muted); margin-top: 4px;"><?php echo $totalOSFinalizadas; ?> OS finalizadas no período.</p>
            </div>

            <!-- KPI: Custo de Aquisição de Peças -->
            <div class="card" style="border-left: 4px solid var(--danger); margin-bottom: 0;">
                <span style="font-size: 11.5px; font-weight: 700; color: var(--text-muted); text-transform: uppercase;">Custo de Peças</span>
                <h3 style="font-size: 22px; font-weight: 800; color: var(--danger); margin-top: 6px;">
                    <?php echo htmlspecialchars($currencySymbol) . ' ' . formatDecimalRelatorio($custoAquisicaoPecas); ?>
                </h3>
                <p style="font-size: 11px; color: var(--text-muted); margin-top: 4px;">Valor pago a fornecedores em insumos.</p>
            </div>

            <!-- KPI: Despesa com Comissões Técnicas -->
            <div class="card" style="border-left: 4px solid var(--warning); margin-bottom: 0;">
                <span style="font-size: 11.5px; font-weight: 700; color: var(--text-muted); text-transform: uppercase;">Comissões de Técnicos</span>
                <h3 style="font-size: 22px; font-weight: 800; color: var(--warning); margin-top: 6px;">
                    <?php echo htmlspecialchars($currencySymbol) . ' ' . formatDecimalRelatorio($comissoesGerais); ?>
                </h3>
                <p style="font-size: 11px; color: var(--text-muted); margin-top: 4px;">Percentagem distribuída aos responsáveis.</p>
            </div>

            <!-- KPI: Lucro Líquido Final -->
            <div class="card" style="border-left: 4px solid var(--success); margin-bottom: 0; background-color: rgba(16, 185, 129, 0.02);">
                <span style="font-size: 11.5px; font-weight: 700; color: var(--success); text-transform: uppercase;">Lucro Líquido Real</span>
                <h3 style="font-size: 22px; font-weight: 800; color: var(--success); margin-top: 6px;">
                    <?php echo htmlspecialchars($currencySymbol) . ' ' . formatDecimalRelatorio($lucroLiquido); ?>
                </h3>
                <p style="font-size: 11px; color: var(--text-muted); margin-top: 4px;">Sobras de caixa livres de taxas e custos.</p>
            </div>

        </div>

        <div style="display: grid; grid-template-columns: 3fr 2fr; gap: 32px; align-items: start;">
            
            <!-- Painel Detalhado de Distribuição -->
            <div class="card">
                <h2 class="card-title">Distribuição de Receitas e Divisão Contabilística</h2>
                <table class="table">
                    <thead>
                        <tr>
                            <th>Categoria Contabilística</th>
                            <th style="text-align: right;">Faturação Bruta</th>
                            <th style="text-align: right;">Custo Aquisitivo / Encargos</th>
                            <th style="text-align: right;">Sobras Dinâmicas (Lucro)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <!-- Divisão: Mão de Obra -->
                        <tr>
                            <td><strong>Mão de Obra / Serviços Técnicos</strong></td>
                            <td style="text-align: right;"><?php echo htmlspecialchars($currencySymbol) . ' ' . formatDecimalRelatorio($totalMaoObra); ?></td>
                            <td style="text-align: right; color: var(--warning);">- <?php echo htmlspecialchars($currencySymbol) . ' ' . formatDecimalRelatorio($comissoesGerais); ?> <small style="display:block; font-size:10px; color:var(--text-muted);">(Comissão Técnica)</small></td>
                            <td style="text-align: right; font-weight: 700; color: var(--success);"><?php echo htmlspecialchars($currencySymbol) . ' ' . formatDecimalRelatorio($totalMaoObra - $comissoesGerais); ?></td>
                        </tr>
                        <!-- Divisão: Peças -->
                        <tr>
                            <td><strong>Lançamento de Peças e Insumos</strong></td>
                            <td style="text-align: right;"><?php echo htmlspecialchars($currencySymbol) . ' ' . formatDecimalRelatorio($totalPecasVendidas); ?></td>
                            <td style="text-align: right; color: var(--danger);">- <?php echo htmlspecialchars($currencySymbol) . ' ' . formatDecimalRelatorio($custoAquisicaoPecas); ?> <small style="display:block; font-size:10px; color:var(--text-muted);">(Custo de Estoque)</small></td>
                            <td style="text-align: right; font-weight: 700; color: var(--success);"><?php echo htmlspecialchars($currencySymbol) . ' ' . formatDecimalRelatorio($lucroPecas); ?></td>
                        </tr>
                        <!-- Totais Gerais -->
                        <tr style="background-color: rgba(255, 255, 255, 0.01); border-top: 2px solid var(--border-color);">
                            <td><strong>Totais Consolidados</strong></td>
                            <td style="text-align: right; font-weight: bold;"><?php echo htmlspecialchars($currencySymbol) . ' ' . formatDecimalRelatorio($faturamentoBruto); ?></td>
                            <td style="text-align: right; font-weight: bold; color: var(--danger);">- <?php echo htmlspecialchars($currencySymbol) . ' ' . formatDecimalRelatorio($custoAquisicaoPecas + $comissoesGerais); ?></td>
                            <td style="text-align: right; font-weight: 800; color: var(--success); font-size: 14.5px;"><?php echo htmlspecialchars($currencySymbol) . ' ' . formatDecimalRelatorio($lucroLiquido); ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Painel: Ranking de Produtividade dos Técnicos -->
            <div class="card">
                <h2 class="card-title">Ranking de Produtividade Técnica</h2>
                <?php if (empty($tecnicosRanking)): ?>
                    <p style="color: var(--text-muted); font-size: 13.5px;">Nenhuma OS concluída por técnicos no período selecionado.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Responsável</th>
                                    <th style="text-align: center; width: 60px;">Qtd OS</th>
                                    <th style="text-align: right;">Total Faturado</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($tecnicosRanking as $index => $tec): ?>
                                    <tr>
                                        <td>
                                            <span style="font-weight: bold; color: var(--text-main);">
                                                <?php echo ($index + 1); ?>º • <?php echo htmlspecialchars($tec['nome']); ?>
                                            </span>
                                        </td>
                                        <td style="text-align: center;"><?php echo $tec['total_os']; ?> un.</td>
                                        <td style="text-align: right; font-weight: bold; color: var(--primary);">
                                            <?php echo htmlspecialchars($currencySymbol) . ' ' . formatDecimalRelatorio($tec['faturado']); ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
