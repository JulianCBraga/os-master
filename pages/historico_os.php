<?php
/**
 * OS Master - Histórico de Alterações e Auditoria de OS
 * * Este ficheiro renderiza uma linha do tempo (timeline) completa e detalhada 
 * de todas as transições de status e intervenções realizadas numa determinada OS,
 * consumindo os dados da tabela 'historico_os'.
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

// Captura o ID da OS a ser auditada
$id_os = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

$osData = null;
$historicoList = [];

// Retorna o elemento HTML do badge de status para consistência visual com a Timeline
if (!function_exists('getStatusBadgeTimeline')) {
    function getStatusBadgeTimeline($status): string {
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
                return '<span class="badge" style="background-color: rgba(239, 68, 68, 0.1); color: #ef4444;">Sem Conserto</span>';
            case 'pronto_para_retirada':
                return '<span class="badge" style="background-color: rgba(16, 185, 129, 0.15); color: #10b981; font-weight: bold;">Pronto para Retirada</span>';
            case 'entregue':
                return '<span class="badge" style="background-color: rgba(16, 185, 129, 0.2); color: #10b981; font-weight: bold;">Entregue</span>';
            case 'abandonado':
                return '<span class="badge" style="background-color: rgba(239, 68, 68, 0.2); color: #ef4444; font-weight: bold;">Abandonado</span>';
            default:
                return '<span class="badge" style="background-color: rgba(148, 163, 184, 0.1); color: #94a3b8;">' . htmlspecialchars(ucfirst($status)) . '</span>';
        }
    }
}

if ($id_os) {
    try {
        // 1. Busca dados consolidados da OS para cabeçalho do relatório de auditoria
        $sqlOS = "
            SELECT o.*, pCli.nome AS cliente_nome, pCli.telefone AS cliente_telefone,
                   e.aparelho, e.marca, e.modelo, e.numero_serie,
                   pTec.nome AS tecnico_nome
            FROM os o
            INNER JOIN cliente c ON o.id_cliente = c.id_pessoa
            INNER JOIN pessoa pCli ON c.id_pessoa = pCli.id_pessoa
            INNER JOIN equipamento e ON o.id_equipamento = e.id_equipamento
            INNER JOIN funcionario f ON o.id_tecnico = f.id_pessoa
            INNER JOIN pessoa pTec ON f.id_pessoa = pTec.id_pessoa
            WHERE o.id_os = :id LIMIT 1
        ";
        $stmtOS = $pdo->prepare($sqlOS);
        $stmtOS->execute([':id' => $id_os]);
        $osData = $stmtOS->fetch();

        if ($osData) {
            // 2. Busca a lista cronológica de transições de status da tabela 'historico_os'
            $sqlHist = "
                SELECT h.*
                FROM historico_os h
                WHERE h.id_os = :id
                ORDER BY h.data_modificacao ASC
            ";
            $stmtHist = $pdo->prepare($sqlHist);
            $stmtHist->execute([':id' => $id_os]);
            $historicoList = $stmtHist->fetchAll();
        }
    } catch (PDOException $e) {
        $osData = null;
    }
}
?>
<div style="margin-bottom: 24px;">
                <a href="index.php?page=os" class="btn btn-secondary" style="display: inline-flex; align-items: center; gap: 8px;">
                    ← Voltar para Ordens de Serviço
                </a>
            </div>

            <?php if (!$osData): ?>
                <div class="card" style="border-color: var(--danger); background-color: rgba(239, 68, 68, 0.02);">
                    <h3 style="color: var(--danger); font-weight: 700; margin-bottom: 8px;">Ordem de Serviço Não Localizada</h3>
                    <p style="color: var(--text-muted); font-size: 14px;">O código de OS fornecido é inválido ou o registo foi removido do sistema.</p>
                </div>
            <?php else: ?>
                <!-- Bloco de Resumo da OS Selecionada -->
                <div class="card" style="margin-bottom: 32px; background-color: rgba(255, 255, 255, 0.01);">
                    <div style="display: flex; justify-content: space-between; align-items: start; border-bottom: 1px solid var(--border-color); padding-bottom: 16px; margin-bottom: 16px;">
                        <div>
                            <span style="font-size: 12px; font-weight: 700; color: var(--primary); text-transform: uppercase; letter-spacing: 0.5px;">Ficha de Auditoria</span>
                            <h2 style="font-size: 22px; font-weight: 800; margin-top: 4px;">Ordem de Serviço #<?php echo $osData['id_os']; ?></h2>
                        </div>
                        <div style="text-align: right;">
                            <span style="font-size: 12px; color: var(--text-muted); display: block;">Data de Abertura</span>
                            <strong style="font-size: 14.5px;"><?php echo date('d/m/Y H:i', strtotime($osData['data_abertura'])); ?></strong>
                        </div>
                    </div>

                    <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 24px; font-size: 14px; line-height: 1.6;">
                        <div>
                            <p style="color: var(--text-muted); font-size: 12px; text-transform: uppercase; font-weight: 600;">Cliente Requisitante</p>
                            <strong><?php echo htmlspecialchars($osData['cliente_nome']); ?></strong><br>
                            <span style="color: var(--text-muted);"><?php echo htmlspecialchars($osData['cliente_telefone'] ?: 'Sem telefone'); ?></span>
                        </div>
                        <div>
                            <p style="color: var(--text-muted); font-size: 12px; text-transform: uppercase; font-weight: 600;">Equipamento / Aparelho</p>
                            <strong><?php echo htmlspecialchars($osData['aparelho']); ?></strong><br>
                            <span style="color: var(--text-muted);">
                                <?php echo htmlspecialchars($osData['marca'] . ' ' . $osData['modelo']); ?> 
                                <?php if (!empty($osData['numero_serie'])): ?>
                                    • SN: <code><?php echo htmlspecialchars($osData['numero_serie']); ?></code>
                                <?php endif; ?>
                            </span>
                        </div>
                        <div>
                            <p style="color: var(--text-muted); font-size: 12px; text-transform: uppercase; font-weight: 600;">Responsável Técnico</p>
                            <strong><?php echo htmlspecialchars($osData['tecnico_nome']); ?></strong><br>
                            <span style="display: inline-block; margin-top: 4px;">
                                Estado Atual: <?php echo getStatusBadgeTimeline($osData['status']); ?>
                            </span>
                        </div>
                    </div>
                </div>

                <!-- LINHA DO TEMPO VERTICAL (Auditoria Cronológica) -->
                <div class="card">
                    <h2 class="card-title" style="margin-bottom: 32px;">Linha do Tempo de Intervenções e Transições</h2>

                    <?php if (empty($historicoList)): ?>
                        <p style="color: var(--text-muted); font-size: 14px;">Não há registo de histórico para esta Ordem de Serviço.</p>
                    <?php else: ?>
                        <!-- Container da Linha do Tempo -->
                        <div style="position: relative; padding-left: 32px; border-left: 2px solid var(--border-color); margin-left: 16px;">
                            
                            <?php foreach ($historicoList as $index => $hist): ?>
                                <div style="position: relative; margin-bottom: 40px;">
                                    
                                    <!-- Círculo Indicador Visual da Linha -->
                                    <div style="position: absolute; left: -42px; top: 2px; width: 18px; height: 18px; border-radius: 50%; background-color: var(--bg-card); border: 4px solid <?php echo ($index === count($historicoList) - 1) ? 'var(--success)' : 'var(--primary)'; ?>; box-shadow: 0 0 0 4px var(--bg-body);"></div>
                                    
                                    <!-- Cabeçalho do Evento da Linha do Tempo -->
                                    <div style="display: flex; align-items: center; gap: 12px; flex-wrap: wrap; margin-bottom: 8px;">
                                        <span style="font-size: 13.5px; font-weight: 700; color: var(--text-main);">
                                            <?php echo date('d/m/Y \à\s H:i', strtotime($hist['data_modificacao'])); ?>
                                        </span>
                                        
                                        <?php if ($hist['status_anterior'] !== null): ?>
                                            <div style="display: flex; align-items: center; gap: 6px; font-size: 12px;">
                                                <?php echo getStatusBadgeTimeline($hist['status_anterior']); ?>
                                                <span style="color: var(--text-muted);">➔</span>
                                                <?php echo getStatusBadgeTimeline($hist['status_novo']); ?>
                                            </div>
                                        <?php else: ?>
                                            <span style="font-size: 12px; color: var(--success); font-weight: 700; background-color: rgba(16, 185, 129, 0.1); padding: 2px 8px; border-radius: 4px;">Abertura Inicial</span>
                                        <?php endif; ?>
                                    </div>

                                    <!-- Corpo do Log / Descrição Técnica -->
                                    <div style="background-color: rgba(255, 255, 255, 0.01); border: 1px solid var(--border-color); border-radius: var(--radius-md); padding: 16px; font-size: 14px; line-height: 1.6; color: var(--text-main); max-width: 800px;">
                                        <?php echo nl2br(htmlspecialchars($hist['observacao_interna'])); ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                            
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
