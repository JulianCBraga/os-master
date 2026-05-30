<?php
/**
 * OS Master - Gestão e Abertura de Ordens de Serviço (OS)
 * * Este ficheiro implementa a lógica Mestre-Detalhe completa para gerir OS,
 * permitir associar clientes, equipamentos, técnicos, lançar peças em tempo real
 * com cálculo de totais automatizado e gerir o histórico de modificações.
 * * @package OSMaster
 * @author Julian C. Braga
 * @version 1.4
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
if (!function_exists('parseDecimalOS')) {
    function parseDecimalOS($value): float {
        if (empty($value)) return 0.00;
        $clean = str_replace('.', '', $value);
        $clean = str_replace(',', '.', $clean);
        return (float)$clean;
    }
}

if (!function_exists('formatDecimalOS')) {
    function formatDecimalOS($value): string {
        return number_format((float)$value, 2, ',', '.');
    }
}

if (!function_exists('getOSStatusOptions')) {
    function getOSStatusOptions(): array {
        return [
            'aguardando_analise' => 'Aguardando Análise',
            'em_diagnostico' => 'Em Diagnóstico',
            'aguardando_aprovacao' => 'Aguardando Aprovação',
            'aguardando_peca' => 'Aguardando Peça',
            'em_reparo' => 'Em Reparo',
            'pronto_para_retirada' => 'Pronto para Retirada',
            'sem_conserto' => 'Sem conserto retirar',
            'abandonado' => 'Abandonado',
            'descarte' => 'Descarte',
            'entregue' => 'Entregue',
        ];
    }
}

if (!function_exists('getOSStatusLabel')) {
    function getOSStatusLabel(string $status): string {
        $labels = getOSStatusOptions();
        $legacyLabels = [
            'reparo_concluido' => 'Reparo concluído',
        ];

        return $labels[$status] ?? $legacyLabels[$status] ?? ucfirst(str_replace('_', ' ', $status));
    }
}

if (!function_exists('isStockImpactStatus')) {
    function isStockImpactStatus(string $status): bool {
        return in_array($status, ['em_reparo', 'reparo_concluido', 'pronto_para_retirada', 'entregue', 'abandonado', 'descarte'], true);
    }
}

if (!function_exists('isWithdrawalStatus')) {
    function isWithdrawalStatus(string $status): bool {
        return in_array($status, ['reparo_concluido', 'pronto_para_retirada', 'sem_conserto', 'abandonado', 'descarte', 'entregue'], true);
    }
}

// Retorna o elemento HTML do badge estilizado de acordo com o novo fluxo de progresso
if (!function_exists('getStatusBadge')) {
    function getStatusBadge($status): string {
        switch ($status) {
            case 'aguardando_analise':
            case 'em_diagnostico':
                return '<span class="badge badge-aberta">' . htmlspecialchars(getOSStatusLabel($status)) . '</span>';
            case 'aguardando_aprovacao':
            case 'aguardando_peca':
            case 'em_reparo':
                return '<span class="badge badge-em-andamento">' . htmlspecialchars(getOSStatusLabel($status)) . '</span>';
            case 'reparo_concluido':
            case 'pronto_para_retirada':
            case 'entregue':
                return '<span class="badge badge-finalizada">' . htmlspecialchars(getOSStatusLabel($status)) . '</span>';
            case 'sem_conserto':
            case 'abandonado':
            case 'descarte':
                return '<span class="badge badge-cancelada">' . htmlspecialchars(getOSStatusLabel($status)) . '</span>';
            default:
                return '<span class="badge badge-aberta">' . htmlspecialchars(getOSStatusLabel($status)) . '</span>';
        }
    }
}

// Recupera símbolo monetário
$currencySymbol = 'R$';
$prazoMaximoRetirada = 90;
try {
    $stmtCurrency = $pdo->query("SELECT * FROM config_sistema LIMIT 1");
    $sysConfig = $stmtCurrency->fetch();
    if ($sysConfig && isset($sysConfig['moeda']) && !empty($sysConfig['moeda'])) {
        $currencySymbol = $sysConfig['moeda'];
    }
    if ($sysConfig && isset($sysConfig['prazo_maximo_retirada'])) {
        $prazoMaximoRetirada = (int)$sysConfig['prazo_maximo_retirada'];
    }
} catch (PDOException $e) {
    // Fallback
}

$message = '';
$messageType = '';

$action = $_GET['action'] ?? 'list';
$editId = $_GET['id'] ?? null;

// Instância de dados padrão da OS (Status inicial atualizado)
$editData = [
    'id_cliente'              => '',
    'id_equipamento'          => '',
    'id_tecnico'              => '',
    'descricao_problema'      => '',
    'estado_aparelho_entrada' => '',
    'diagnostico'             => '',
    'valor_mao_obra'          => '0,00',
    'valor_pecas'             => '0,00',
    'valor_total'             => '0,00',
    'status'                  => 'aguardando_analise'
];
$osItems = []; // Itens / peças associadas à OS em edição

$preselectCliente = filter_input(INPUT_GET, 'id_cliente', FILTER_VALIDATE_INT);
$preselectEquipamento = filter_input(INPUT_GET, 'id_equipamento', FILTER_VALIDATE_INT);
if ($action === 'create') {
    if ($preselectCliente) {
        $editData['id_cliente'] = $preselectCliente;
    }
    if ($preselectEquipamento) {
        $editData['id_equipamento'] = $preselectEquipamento;
    }
}

// ==========================================================================
// Processamento de Ações do Formulário (POST)
// ==========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formAction = $_POST['form_action'] ?? '';

    // Coleta dos dados gerais da OS
    $id_cliente              = filter_input(INPUT_POST, 'id_cliente', FILTER_VALIDATE_INT);
    $id_equipamento          = filter_input(INPUT_POST, 'id_equipamento', FILTER_VALIDATE_INT);
    $id_tecnico              = filter_input(INPUT_POST, 'id_tecnico', FILTER_VALIDATE_INT);
    $descricao_problema      = trim(filter_input(INPUT_POST, 'descricao_problema', FILTER_DEFAULT));
    $estado_aparelho_entrada = trim(filter_input(INPUT_POST, 'estado_aparelho_entrada', FILTER_DEFAULT));
    $diagnostico             = trim(filter_input(INPUT_POST, 'diagnostico', FILTER_DEFAULT));
    $valor_mao_obra          = parseDecimalOS($_POST['valor_mao_obra'] ?? '0,00');
    $status                  = trim(filter_input(INPUT_POST, 'status', FILTER_DEFAULT));
    if ($formAction === 'create') {
        $status = 'aguardando_analise';
    }

    $editData = array_merge($editData, [
        'id_cliente'              => $id_cliente ?: '',
        'id_equipamento'          => $id_equipamento ?: '',
        'id_tecnico'              => $id_tecnico ?: '',
        'descricao_problema'      => $descricao_problema,
        'estado_aparelho_entrada' => $estado_aparelho_entrada,
        'diagnostico'             => $diagnostico,
        'valor_mao_obra'          => formatDecimalOS($valor_mao_obra),
        'status'                  => $status,
    ]);

    // Coleta de arrays de peças dinâmicas vindas do formulário
    $postProdutos    = $_POST['item_produto_id'] ?? [];
    $postQuantidades = $_POST['item_quantidade'] ?? [];

    $statusPermitidos = array_merge(array_keys(getOSStatusOptions()), ['reparo_concluido']);
    if (!$id_cliente) {
        $message = 'Selecione ou cadastre um cliente antes de salvar a OS.';
        $messageType = 'danger';
    } elseif (!$id_equipamento) {
        $message = 'Selecione ou cadastre um equipamento para este cliente antes de salvar a OS.';
        $messageType = 'danger';
    } elseif (!$id_tecnico) {
        $message = 'Selecione o técnico responsável antes de salvar a OS.';
        $messageType = 'danger';
    } elseif (empty($status) || !in_array($status, $statusPermitidos, true)) {
        $message = 'Selecione um status válido para a OS.';
        $messageType = 'danger';
    } else {
        try {
            $pdo->beginTransaction();

            // 1. PROCESSAR INSERÇÃO (Nova OS)
            if ($formAction === 'create') {
                $sqlInsert = "INSERT INTO os (
                                data_abertura, id_cliente, id_equipamento, id_tecnico, 
                                descricao_problema, estado_aparelho_entrada, diagnostico, 
                                valor_pecas, valor_mao_obra, valor_total, status, termo_compromisso_gerado
                              ) VALUES (
                                NOW(), :id_cliente, :id_equipamento, :id_tecnico, 
                                :descricao_problema, :estado_aparelho_entrada, :diagnostico, 
                                0.00, :valor_mao_obra, 0.00, :status, 0
                              )";
                $stmtInsert = $pdo->prepare($sqlInsert);
                $stmtInsert->execute([
                    ':id_cliente'              => $id_cliente,
                    ':id_equipamento'          => $id_equipamento,
                    ':id_tecnico'              => $id_tecnico,
                    ':descricao_problema'      => $descricao_problema,
                    ':estado_aparelho_entrada' => $estado_aparelho_entrada,
                    ':diagnostico'             => $diagnostico,
                    ':valor_mao_obra'          => $valor_mao_obra,
                    ':status'                  => $status
                ]);
                $id_os = $pdo->lastInsertId();

                // Lança registro de inicialização no Histórico de OS
                $sqlHist = "INSERT INTO historico_os (id_os, data_modificacao, status_anterior, status_novo, observacao_interna) 
                            VALUES (:id_os, NOW(), NULL, :status_novo, 'Abertura inicial da Ordem de Serviço.')";
                $stmtHist = $pdo->prepare($sqlHist);
                $stmtHist->execute([':id_os' => $id_os, ':status_novo' => $status]);

            // 2. PROCESSAR ATUALIZAÇÃO (OS Existente)
            } elseif ($formAction === 'update' && $editId) {
                $id_os = $editId;

                // Captura status anterior para auditoria/histórico
                $stmtPrev = $pdo->prepare("SELECT status FROM os WHERE id_os = :id LIMIT 1");
                $stmtPrev->execute([':id' => $id_os]);
                $statusAnterior = $stmtPrev->fetchColumn();

                $sqlUpdate = "UPDATE os SET 
                                id_cliente = :id_cliente, 
                                id_equipamento = :id_equipamento, 
                                id_tecnico = :id_tecnico, 
                                descricao_problema = :descricao_problema, 
                                estado_aparelho_entrada = :estado_aparelho_entrada, 
                                diagnostico = :diagnostico, 
                                valor_mao_obra = :valor_mao_obra, 
                                status = :status 
                              WHERE id_os = :id";
                $stmtUpdate = $pdo->prepare($sqlUpdate);
                $stmtUpdate->execute([
                    ':id_cliente'              => $id_cliente,
                    ':id_equipamento'          => $id_equipamento,
                    ':id_tecnico'              => $id_tecnico,
                    ':descricao_problema'      => $descricao_problema,
                    ':estado_aparelho_entrada' => $estado_aparelho_entrada,
                    ':diagnostico'             => $diagnostico,
                    ':valor_mao_obra'          => $valor_mao_obra,
                    ':status'                  => $status,
                    ':id'                      => $id_os
                ]);

                // Se o status mudou, gera histórico de auditoria
                if ($statusAnterior !== $status) {
                    $sqlHist = "INSERT INTO historico_os (id_os, data_modificacao, status_anterior, status_novo, observacao_interna) 
                                VALUES (:id_os, NOW(), :status_ant, :status_novo, 'Atualização de status via painel de controle.')";
                    $stmtHist = $pdo->prepare($sqlHist);
                    $stmtHist->execute([
                        ':id_os'      => $id_os,
                        ':status_ant' => $statusAnterior,
                        ':status_novo'=> $status
                    ]);
                }

                if (isStockImpactStatus((string)$statusAnterior)) {
                    $stmtItensAntigos = $pdo->prepare("SELECT id_produto, quantidade FROM item_os WHERE id_os = :id_os");
                    $stmtItensAntigos->execute([':id_os' => $id_os]);
                    $stmtRestauraEstoque = $pdo->prepare("UPDATE produto SET estoque = estoque + :qty WHERE id_produto = :id");

                    foreach ($stmtItensAntigos->fetchAll() as $itemAntigo) {
                        $stmtRestauraEstoque->execute([
                            ':qty' => (int)$itemAntigo['quantidade'],
                            ':id' => (int)$itemAntigo['id_produto'],
                        ]);
                    }
                }

                // Limpa itens antigos da OS antes do relançamento seguro (Master-Detail Puro)
                $pdo->prepare("DELETE FROM item_os WHERE id_os = :id_os")->execute([':id_os' => $id_os]);
            }

            // 3. PROCESSAMENTO DE ITENS / PEÇAS LANÇADAS
            $totalPecas = 0.00;
            if (!empty($postProdutos)) {
                $stmtProdVal = $pdo->prepare("SELECT valor, estoque, descricao FROM produto WHERE id_produto = :id LIMIT 1");
                $stmtInsertItem = $pdo->prepare("INSERT INTO item_os (id_os, id_produto, quantidade, valor_unitario, subtotal) VALUES (:id_os, :id_prod, :qty, :v_unit, :sub)");
                
                foreach ($postProdutos as $index => $prodId) {
                    $qty = (int)$postQuantidades[$index];
                    if ($prodId && $qty > 0) {
                        $stmtProdVal->execute([':id' => $prodId]);
                        $product = $stmtProdVal->fetch();

                        if ($product) {
                            // Validação estrita de estoque somente quando a fase consome peça.
                            if (isStockImpactStatus($status) && $product['estoque'] < $qty) {
                                throw new Exception("Estoque insuficiente para o produto '" . $product['descricao'] . "'. Quantidade disponível: " . $product['estoque']);
                            }

                            $vUnitario = (float)$product['valor'];
                            $subtotal = $qty * $vUnitario;
                            $totalPecas += $subtotal;

                            // Insere o item na tabela item_os
                            $stmtInsertItem->execute([
                                ':id_os'   => $id_os,
                                ':id_prod' => $prodId,
                                ':qty'     => $qty,
                                ':v_unit'  => $vUnitario,
                                ':sub'     => $subtotal
                            ]);

                            // Abate estoque apenas quando a OS entra em fase de reparo/saída.
                            if (isStockImpactStatus($status)) {
                                $stmtBaixaEstoque = $pdo->prepare("UPDATE produto SET estoque = estoque - :qty WHERE id_produto = :id");
                                $stmtBaixaEstoque->execute([':qty' => $qty, ':id' => $prodId]);
                            }
                        }
                    }
                }
            }

            // 4. ATUALIZAR TOTAIS DA OS DE FORMA ATÔMICA E SEGURA NO BANCO
            $totalOS = $valor_mao_obra + $totalPecas;
            $stmtUpdateTotals = $pdo->prepare("UPDATE os SET valor_pecas = :v_pecas, valor_total = :v_total WHERE id_os = :id");
            $stmtUpdateTotals->execute([
                ':v_pecas' => $totalPecas,
                ':v_total' => $totalOS,
                ':id'      => $id_os
            ]);

            $pdo->commit();
            $message = 'Ordem de Serviço guardada com sucesso!';
            $messageType = 'success';
            $action = 'list';
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $message = 'Erro de gravação: ' . $e->getMessage();
            $messageType = 'danger';
        }
    }
}

// ==========================================================================
// Processamento de Ações de URL (GET)
// ==========================================================================
// CARREGAR DADOS PARA EDIÇÃO E LISTAGEM DE ITENS
if ($action === 'edit' && $editId) {
    try {
        $stmtEdit = $pdo->prepare("SELECT * FROM os WHERE id_os = :id LIMIT 1");
        $stmtEdit->execute([':id' => $editId]);
        $data = $stmtEdit->fetch();
        if ($data) {
            $editData = $data;
            $editData['valor_mao_obra'] = formatDecimalOS($data['valor_mao_obra']);
            $editData['valor_pecas']    = formatDecimalOS($data['valor_pecas']);
            $editData['valor_total']    = formatDecimalOS($data['valor_total']);

            // Recupera as peças associadas a esta OS
            $stmtItems = $pdo->prepare("
                SELECT i.*, p.descricao 
                FROM item_os i 
                INNER JOIN produto p ON i.id_produto = p.id_produto 
                WHERE i.id_os = :id
            ");
            $stmtItems->execute([':id' => $editId]);
            $osItems = $stmtItems->fetchAll();
        } else {
            $message = 'Ordem de Serviço não encontrada.';
            $messageType = 'danger';
            $action = 'list';
        }
    } catch (PDOException $e) {
        $message = 'Erro ao carregar dados da Ordem de Serviço.';
        $messageType = 'danger';
    }
}

// EXECUTAR EXCLUSÃO DE OS
if ($action === 'delete' && $editId) {
    try {
        $pdo->beginTransaction();

        $stmtStatusDel = $pdo->prepare("SELECT status FROM os WHERE id_os = :id LIMIT 1");
        $stmtStatusDel->execute([':id' => $editId]);
        $statusExcluido = (string)$stmtStatusDel->fetchColumn();

        if (isStockImpactStatus($statusExcluido)) {
            $stmtItensDel = $pdo->prepare("SELECT id_produto, quantidade FROM item_os WHERE id_os = :id_os");
            $stmtItensDel->execute([':id_os' => $editId]);
            $stmtRestauraDel = $pdo->prepare("UPDATE produto SET estoque = estoque + :qty WHERE id_produto = :id");

            foreach ($stmtItensDel->fetchAll() as $itemDel) {
                $stmtRestauraDel->execute([
                    ':qty' => (int)$itemDel['quantidade'],
                    ':id' => (int)$itemDel['id_produto'],
                ]);
            }
        }

        $stmtDel = $pdo->prepare("DELETE FROM os WHERE id_os = :id");
        $stmtDel->execute([':id' => $editId]);
        $pdo->commit();
        $message = 'Ordem de Serviço removida com sucesso do sistema.';
        $messageType = 'success';
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $message = 'Não é possível remover a OS selecionada devido a dependências ativas.';
        $messageType = 'danger';
    }
    $action = 'list';
}

// ==========================================================================
// Recuperação das Listas de Suporte
// ==========================================================================
$clientesList = [];
$equipamentosList = [];
$tecnicosList = [];
$produtosList = [];
$osList = [];

try {
    // 1. Clientes com documento tratado (Evita parênteses vazios se sem documento!)
    $clientesList = $pdo->query("
        SELECT p.id_pessoa, p.nome, p.cpf_cnpj 
        FROM cliente c 
        INNER JOIN pessoa p ON c.id_pessoa = p.id_pessoa 
        WHERE p.status = 1 AND c.status = 1
        ORDER BY p.nome ASC
    ")->fetchAll();

    // 2. Equipamentos mapeados para uso em filtro JS
    $equipamentosList = $pdo->query("
        SELECT id_equipamento, aparelho, marca, modelo, id_cliente 
        FROM equipamento 
        ORDER BY aparelho ASC
    ")->fetchAll();

    // 3. Técnicos/Funcionários ativos no sistema
    $tecnicosList = $pdo->query("
        SELECT p.id_pessoa, p.nome, f.cargo, f.perfil_acesso
        FROM funcionario f 
        INNER JOIN pessoa p ON f.id_pessoa = p.id_pessoa 
        WHERE p.status = 1 AND f.status = 1 AND (f.cargo IN ('Técnico', 'Administrador') OR f.perfil_acesso IN ('Técnico', 'Administrador'))
        ORDER BY p.nome ASC
    ")->fetchAll();

    // 4. Produtos em estoque para listagem de peças
    $produtosList = $pdo->query("SELECT id_produto, descricao, valor, estoque FROM produto ORDER BY descricao ASC")->fetchAll();

    // 5. Listagem Geral de Ordens de Serviço (Junções Completas)
    $sqlOS = "
        SELECT o.id_os, o.data_abertura, o.updated_at, o.status, o.valor_total,
               pCli.nome AS cliente_nome, 
               e.aparelho, e.marca, 
               pTec.nome AS tecnico_nome 
        FROM os o 
        INNER JOIN cliente c ON o.id_cliente = c.id_pessoa
        INNER JOIN pessoa pCli ON c.id_pessoa = pCli.id_pessoa
        INNER JOIN equipamento e ON o.id_equipamento = e.id_equipamento
        INNER JOIN funcionario f ON o.id_tecnico = f.id_pessoa
        INNER JOIN pessoa pTec ON f.id_pessoa = pTec.id_pessoa
        ORDER BY o.id_os DESC
    ";
    $osList = $pdo->query($sqlOS)->fetchAll();
} catch (PDOException $e) {
    // Tratamento de segurança
}
?>

<!-- Feedback de Alertas do Sistema -->
        <?php if (!empty($message)): ?>
            <div class="alert alert-<?php echo $messageType; ?>" style="margin-bottom: 24px;">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <!-- Container de Alertas Assíncronos -->
        <div id="custom-alert-container" style="position: fixed; top: 20px; right: 20px; z-index: 9999; display: flex; flex-direction: column; gap: 10px;"></div>

        <!-- Modal de Confirmação Customizado -->
        <div id="custom-confirm-modal" style="display: none; position: fixed; inset: 0; background-color: rgba(15, 23, 42, 0.75); align-items: center; justify-content: center; z-index: 9999; padding: 20px;">
            <div style="background-color: var(--bg-card); border: 1px solid var(--border-color); border-radius: var(--radius-lg); max-width: 440px; width: 100%; padding: 24px; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.5);">
                <h3 style="font-size: 18px; font-weight: 700; margin-bottom: 12px; color: var(--text-main);">Confirmar Ação</h3>
                <p id="confirm-modal-text" style="font-size: 14.5px; color: var(--text-muted); margin-bottom: 24px; line-height: 1.5;"></p>
                <div style="display: flex; justify-content: flex-end; gap: 12px;">
                    <button id="confirm-modal-cancel" class="btn btn-secondary" style="padding: 8px 16px;">Cancelar</button>
                    <a id="confirm-modal-ok" class="btn btn-danger" style="padding: 8px 16px;">Confirmar</a>
                </div>
            </div>
        </div>

        <?php if ($action === 'create' || $action === 'edit'): ?>
            <!-- FORMULÁRIO COMPLETO MESTRE-DETALHE (Criar / Editar) -->
            <div class="card">
                <h2 class="card-title" style="<?php echo ($action === 'edit') ? 'color: var(--warning);' : ''; ?>">
                    <?php echo ($action === 'edit') ? 'Editar Ordem de Serviço #' . $editId : 'Nova Ordem de Serviço'; ?>
                </h2>

                <form id="formOS" action="index.php?page=os<?php echo ($action === 'edit') ? '&action=edit&id=' . $editId : ''; ?>" method="POST" autocomplete="off">
                    <input type="hidden" name="form_action" value="<?php echo ($action === 'edit') ? 'update' : 'create'; ?>">

                    <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 32px; align-items: start;">
                        
                        <!-- Lado Esquerdo: Dados Gerais da OS -->
                        <div>
                            <h3 style="font-size: 14px; text-transform: uppercase; color: var(--primary); margin-bottom: 16px;">1. Dados da Abertura</h3>
                            
                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                                <div class="form-group">
                                    <label for="id_cliente" class="form-label">Cliente Requisitante</label>
                                    <div style="display: flex; gap: 8px;">
                                        <select id="id_cliente" name="id_cliente" class="form-control" required style="flex-grow: 1;">
                                            <option value="">Selecione o cliente...</option>
                                            <?php foreach ($clientesList as $cli): ?>
                                                <?php $docStr = !empty($cli['cpf_cnpj']) ? ' (' . htmlspecialchars($cli['cpf_cnpj']) . ')' : ''; ?>
                                                <option value="<?php echo $cli['id_pessoa']; ?>" <?php echo ($cli['id_pessoa'] == $editData['id_cliente']) ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($cli['nome']) . $docStr; ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <a href="index.php?page=clientes&return=os" id="btn-escolher-cliente" class="btn btn-secondary" style="padding: 10px 14px; font-weight: 700; font-size: 13px; white-space: nowrap;" title="Selecionar ou cadastrar cliente no módulo principal">Abrir</a>
                                    </div>
                                </div>

                                <div class="form-group">
                                    <label for="id_equipamento" class="form-label">Equipamento Associado</label>
                                    <div style="display: flex; gap: 8px;">
                                        <select id="id_equipamento" name="id_equipamento" class="form-control" required disabled style="flex-grow: 1;">
                                            <option value="">Selecione o cliente primeiro...</option>
                                        </select>
                                        <a href="#" id="btn-escolher-equipamento" class="btn btn-secondary" style="padding: 10px 14px; font-weight: 700; font-size: 13px; white-space: nowrap; pointer-events: none; opacity: 0.55;" title="Selecionar ou cadastrar equipamento no módulo principal">Abrir</a>
                                    </div>
                                </div>
                            </div>

                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                                <div class="form-group">
                                    <label for="id_tecnico" class="form-label">Técnico Responsável</label>
                                    <select id="id_tecnico" name="id_tecnico" class="form-control" required>
                                        <option value="">Selecione o técnico...</option>
                                        <?php foreach ($tecnicosList as $tec): ?>
                                            <option value="<?php echo $tec['id_pessoa']; ?>" <?php echo ($tec['id_pessoa'] == $editData['id_tecnico']) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($tec['nome']) . ' (' . htmlspecialchars($tec['cargo']) . ')'; ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="form-group">
                                    <label for="status" class="form-label">Estado de Progresso (Status)</label>
                                    <select id="status" name="status" class="form-control" required <?php echo ($action === 'create') ? 'disabled' : ''; ?>>
                                        <?php foreach (getOSStatusOptions() as $statusValue => $statusLabel): ?>
                                            <option value="<?php echo htmlspecialchars($statusValue); ?>" <?php echo ($editData['status'] === $statusValue) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($statusLabel); ?>
                                            </option>
                                        <?php endforeach; ?>
                                        <?php if ($editData['status'] === 'reparo_concluido'): ?>
                                            <option value="reparo_concluido" selected>Reparo concluído (legado)</option>
                                        <?php endif; ?>
                                    </select>
                                    <?php if ($action === 'create'): ?>
                                        <small class="form-label" style="margin-top: 6px; font-weight: normal; text-transform: none; letter-spacing: 0;">Toda nova OS inicia em Aguardando Análise.</small>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="form-group">
                                <label for="descricao_problema" class="form-label">Descrição do Problema Relatado</label>
                                <textarea id="descricao_problema" name="descricao_problema" class="form-control" placeholder="Descreva os defeitos relatados pelo cliente..." style="height: 100px; resize: none;"><?php echo htmlspecialchars($editData['descricao_problema']); ?></textarea>
                            </div>

                            <div class="form-group">
                                <label for="estado_aparelho_entrada" class="form-label">Estado Físico do Aparelho (Entrada)</label>
                                <textarea id="estado_aparelho_entrada" name="estado_aparelho_entrada" class="form-control" placeholder="Riscos, peças soltas, sem bateria, etc..." style="height: 60px; resize: none;"><?php echo htmlspecialchars($editData['estado_aparelho_entrada']); ?></textarea>
                            </div>

                            <div class="form-group">
                                <label for="diagnostico" class="form-label">Laudo Técnico / Diagnóstico</label>
                                <textarea id="diagnostico" name="diagnostico" class="form-control" placeholder="Insira o laudo, testes realizados e solução técnica..." style="height: 100px; resize: none;"><?php echo htmlspecialchars($editData['diagnostico']); ?></textarea>
                            </div>
                        </div>

                        <!-- Lado Direito: Adicionar Peças e Orçamento Financeiro -->
                        <div style="position: sticky; top: 90px;">
                            <h3 style="font-size: 14px; text-transform: uppercase; color: var(--primary); margin-bottom: 16px;">2. Peças e Insumos</h3>
                            
                            <div style="background-color: rgba(255,255,255,0.01); padding: 18px; border-radius: var(--radius-md); border: 1px solid var(--border-color); margin-bottom: 20px;">
                                <div class="form-group" style="margin-bottom: 12px;">
                                    <label for="select_produto" class="form-label">Escolher Peça do Estoque</label>
                                    <select id="select_produto" class="form-control">
                                        <option value="">Selecione um item...</option>
                                        <?php foreach ($produtosList as $prod): ?>
                                            <?php
                                            $estoqueDisponivel = (int)$prod['estoque'];
                                            if ($action === 'edit' && isStockImpactStatus((string)$editData['status'])) {
                                                foreach ($osItems as $itemExistente) {
                                                    if ((int)$itemExistente['id_produto'] === (int)$prod['id_produto']) {
                                                        $estoqueDisponivel += (int)$itemExistente['quantidade'];
                                                    }
                                                }
                                            }
                                            ?>
                                            <option value="<?php echo $prod['id_produto']; ?>" data-preco="<?php echo $prod['valor']; ?>" data-estoque="<?php echo $estoqueDisponivel; ?>">
                                                <?php echo htmlspecialchars($prod['descricao']) . ' (' . htmlspecialchars($currencySymbol) . ' ' . formatDecimalOS($prod['valor']) . ') [Disponível: ' . $estoqueDisponivel . ']'; ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div style="display: flex; gap: 10px;">
                                    <div style="flex-grow: 1;">
                                        <input type="number" id="peca_quantidade" class="form-control" placeholder="Quant." min="1" value="1">
                                    </div>
                                    <button type="button" id="btn_add_peca" class="btn btn-primary" style="padding: 10px 16px;">+ Lançar</button>
                                </div>
                            </div>

                            <div style="max-height: 180px; overflow-y: auto; margin-bottom: 24px; border: 1px solid var(--border-color); border-radius: var(--radius-sm);">
                                <table class="table" style="font-size: 12.5px;">
                                    <thead>
                                        <tr>
                                            <th>Item</th>
                                            <th style="text-align: center; width: 60px;">Qtd</th>
                                            <th style="text-align: right; width: 80px;">Subtotal</th>
                                            <th style="width: 40px;"></th>
                                        </tr>
                                    </thead>
                                    <tbody id="tbl_pecas_lancadas">
                                        <!-- Inserido dinamicamente via JS -->
                                    </tbody>
                                </table>
                            </div>

                            <h3 style="font-size: 14px; text-transform: uppercase; color: var(--primary); margin-bottom: 16px;">3. Valores da OS</h3>

                            <div style="background-color: rgba(255,255,255,0.02); padding: 20px; border-radius: var(--radius-md); border: 1px solid var(--border-color); display: flex; flex-direction: column; gap: 12px;">
                                <div style="display: flex; justify-content: space-between; align-items: center;">
                                    <span class="form-label" style="margin-bottom: 0;">Mão de Obra (<?php echo htmlspecialchars($currencySymbol); ?>)</span>
                                    <input type="text" id="valor_mao_obra" name="valor_mao_obra" class="form-control" value="<?php echo htmlspecialchars($editData['valor_mao_obra']); ?>" style="width: 120px; text-align: right;" required>
                                </div>
                                <div style="display: flex; justify-content: space-between; align-items: center;">
                                    <span class="form-label" style="margin-bottom: 0;">Total de Peças</span>
                                    <strong id="display_pecas_total" style="font-size: 15px; color: var(--text-muted);"><?php echo htmlspecialchars($currencySymbol) . ' ' . htmlspecialchars($editData['valor_pecas']); ?></strong>
                                </div>
                                <hr style="border-color: var(--border-color); margin: 6px 0;">
                                <div style="display: flex; justify-content: space-between; align-items: center;">
                                    <span style="font-weight: 700; color: var(--success); font-size: 15px;">VALOR TOTAL OS</span>
                                    <strong id="display_os_total" style="font-size: 20px; color: var(--success);"><?php echo htmlspecialchars($currencySymbol) . ' ' . htmlspecialchars($editData['valor_total']); ?></strong>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div style="display: flex; gap: 12px; margin-top: 32px; justify-content: flex-end; border-top: 1px solid var(--border-color); padding-top: 24px;">
                        <a href="index.php?page=os" class="btn btn-secondary">Cancelar Operação</a>
                        <button type="submit" class="btn btn-primary" style="padding: 12px 24px;">Salvar e Registrar OS</button>
                    </div>
                </form>
            </div>

            <!-- MOTOR DE LOGICA DE CONTROLE (ViaCEP, Filtros de Equipamento, Cálculos Dinâmicos) -->
            <script>
            document.addEventListener('DOMContentLoaded', function() {
                const form = document.getElementById('formOS');
                const selectCliente = document.getElementById('id_cliente');
                const selectEquipamento = document.getElementById('id_equipamento');
                const btnEscolherEquipamento = document.getElementById('btn-escolher-equipamento');
                
                // Arrays predefinidos de equipamentos vindos do PHP
                let equipamentos = <?php echo json_encode($equipamentosList); ?>;
                const selectedEquipamentoId = "<?php echo $editData['id_equipamento']; ?>";

                // 1. FILTRAGEM DINÂMICA DE EQUIPAMENTOS POR CLIENTE
                function filtrarEquipamentos() {
                    const clienteId = selectCliente.value;
                    selectEquipamento.innerHTML = '<option value="">Selecione o equipamento...</option>';
                    
                    if (clienteId === '') {
                        selectEquipamento.disabled = true;
                        if (btnEscolherEquipamento) {
                            btnEscolherEquipamento.href = '#';
                            btnEscolherEquipamento.style.pointerEvents = 'none';
                            btnEscolherEquipamento.style.opacity = '0.55';
                        }
                        return;
                    }

                    const filtrados = equipamentos.filter(eq => eq.id_cliente == clienteId);
                    
                    if (filtrados.length === 0) {
                        selectEquipamento.innerHTML = '<option value="">Nenhum equipamento cadastrado para este cliente.</option>';
                        selectEquipamento.disabled = true;
                    } else {
                        filtrados.forEach(eq => {
                            const option = document.createElement('option');
                            option.value = eq.id_equipamento;
                            option.text = `${eq.aparelho} ${eq.marca ? `(${eq.marca})` : ''} ${eq.modelo ? `[${eq.modelo}]` : ''}`;
                            if (eq.id_equipamento == selectedEquipamentoId) {
                                option.selected = true;
                            }
                            selectEquipamento.appendChild(option);
                        });
                        selectEquipamento.disabled = false;
                    }
                    if (btnEscolherEquipamento) {
                        btnEscolherEquipamento.href = `index.php?page=equipamentos&return=os&action=create&id_cliente=${encodeURIComponent(clienteId)}`;
                        btnEscolherEquipamento.style.pointerEvents = '';
                        btnEscolherEquipamento.style.opacity = '';
                    }
                }

                if (selectCliente) {
                    selectCliente.addEventListener('change', filtrarEquipamentos);
                    if (selectCliente.value !== '') {
                        filtrarEquipamentos();
                    }
                }

                // 2. IMPEDE A SUBMISSÃO DO FORMULÁRIO AO TECLAR ENTER
                if (form) {
                    form.addEventListener('keydown', function(e) {
                        if (e.key === 'Enter' && e.target.tagName === 'INPUT') {
                            e.preventDefault();
                            return false;
                        }
                    });

                    form.addEventListener('submit', function(e) {
                        if (!selectCliente || selectCliente.value === '') {
                            e.preventDefault();
                            mostrarNotificacaoErro('Selecione ou cadastre um cliente antes de salvar a OS.');
                            selectCliente.focus();
                            return false;
                        }

                        if (!selectEquipamento || selectEquipamento.disabled || selectEquipamento.value === '') {
                            e.preventDefault();
                            mostrarNotificacaoErro('Selecione ou cadastre um equipamento para este cliente antes de salvar a OS.');
                            if (btnEscolherEquipamento && btnEscolherEquipamento.href && btnEscolherEquipamento.href !== '#') {
                                btnEscolherEquipamento.focus();
                            }
                            return false;
                        }
                    });
                }

                // 4. ADIÇÃO DINÂMICA DE PEÇAS E MATERIAIS (Master-Detail Front-end)
                const selectProduto = document.getElementById('select_produto');
                const pecaQuantidade = document.getElementById('peca_quantidade');
                const btnAddPeca = document.getElementById('btn_add_peca');
                const tblPecas = document.getElementById('tbl_pecas_lancadas');
                const inputMaoObra = document.getElementById('valor_mao_obra');

                const displayPecasTotal = document.getElementById('display_pecas_total');
                const displayOSTotal = document.getElementById('display_os_total');

                // Array em memória para controle de peças inseridas
                let pecasLancadas = [];

                // Carrega peças existentes se for modo de edição
                const itensExistentes = <?php echo json_encode($osItems); ?>;
                if (itensExistentes && itensExistentes.length > 0) {
                    itensExistentes.forEach(it => {
                        pecasLancadas.push({
                            id_produto: it.id_produto,
                            descricao: it.descricao,
                            quantidade: parseInt(it.quantidade),
                            valor_unitario: parseFloat(it.valor_unitario),
                            subtotal: parseFloat(it.subtotal)
                        });
                    });
                    renderizarPecas();
                }

                function stringToFloat(value) {
                    if (!value) return 0.00;
                    let clean = value.replace(/\D/g, '');
                    return parseFloat(clean / 100);
                }

                function floatToString(value) {
                    return value.toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                }

                // Máscara campo de Mão de Obra
                if (inputMaoObra) {
                    inputMaoObra.addEventListener('input', function() {
                        let v = this.value.replace(/\D/g, '');
                        if (v === '') {
                            this.value = '0,00';
                            return;
                        }
                        v = (v / 100).toFixed(2) + '';
                        v = v.replace('.', ',');
                        v = v.replace(/(\d)(?=(\d{3})+(?!\d))/g, '$1.');
                        this.value = v;
                        calcularTotaisFinais();
                    });
                }

                if (btnAddPeca) {
                    btnAddPeca.addEventListener('click', function() {
                        const option = selectProduto.options[selectProduto.selectedIndex];
                        const prodId = selectProduto.value;
                        const qty = parseInt(pecaQuantidade.value);

                        if (!prodId || qty <= 0) {
                            mostrarNotificacaoErro('Selecione uma peça válida e uma quantidade maior que zero.');
                            return;
                        }

                        const maxEstoque = parseInt(option.getAttribute('data-estoque'));
                        const preco = parseFloat(option.getAttribute('data-preco'));
                        const descricao = option.text.split(' (')[0];

                        // Verifica estoque disponível
                        const jaAdicionado = pecasLancadas.find(p => p.id_produto == prodId);
                        const totalRequisitado = (jaAdicionado ? jaAdicionado.quantidade : 0) + qty;

                        if (totalRequisitado > maxEstoque) {
                            mostrarNotificacaoErro(`Estoque insuficiente. Estoque disponível: ${maxEstoque}.`);
                            return;
                        }

                        if (jaAdicionado) {
                            jaAdicionado.quantidade = totalRequisitado;
                            jaAdicionado.subtotal = jaAdicionado.quantidade * jaAdicionado.valor_unitario;
                        } else {
                            pecasLancadas.push({
                                id_produto: prodId,
                                descricao: descricao,
                                quantidade: qty,
                                valor_unitario: preco,
                                subtotal: qty * preco
                            });
                        }

                        // Limpa seleção
                        selectProduto.value = '';
                        pecaQuantidade.value = 1;

                        renderizarPecas();
                    });
                }

                function removerPeca(index) {
                    pecasLancadas.splice(index, 1);
                    renderizarPecas();
                }

                // Exibe a tabela de peças dinâmica
                function renderizarPecas() {
                    tblPecas.innerHTML = '';
                    pecasLancadas.forEach((peca, idx) => {
                        const tr = document.createElement('tr');
                        tr.innerHTML = `
                            <td>
                                <strong>${peca.descricao}</strong>
                                <input type="hidden" name="item_produto_id[]" value="${peca.id_produto}">
                            </td>
                            <td style="text-align: center;">
                                ${peca.quantidade}
                                <input type="hidden" name="item_quantidade[]" value="${peca.quantidade}">
                            </td>
                            <td style="text-align: right;"><?php echo htmlspecialchars($currencySymbol); ?> ${floatToString(peca.subtotal)}</td>
                            <td style="text-align: right;">
                                <button type="button" class="btn-remove-item" style="color: var(--danger); cursor: pointer; background:none; font-size:16px;">&times;</button>
                            </td>
                        `;
                        
                        // Adiciona gatilho para remoção
                        tr.querySelector('.btn-remove-item').addEventListener('click', () => removerPeca(idx));
                        tblPecas.appendChild(tr);
                    });

                    calcularTotaisFinais();
                }

                function calcularTotaisFinais() {
                    let totalPecas = pecasLancadas.reduce((acc, curr) => acc + curr.subtotal, 0);
                    let maoObra = stringToFloat(inputMaoObra.value);
                    let totalGeral = totalPecas + maoObra;

                    displayPecasTotal.textContent = '<?php echo htmlspecialchars($currencySymbol); ?> ' + floatToString(totalPecas);
                    displayOSTotal.textContent = '<?php echo htmlspecialchars($currencySymbol); ?> ' + floatToString(totalGeral);
                }

                // Notificação de Sucesso
                function mostrarNotificacaoSucesso(texto) {
                    const container = document.getElementById('custom-alert-container');
                    const box = document.createElement('div');
                    box.style.backgroundColor = '#10b981';
                    box.style.color = '#ffffff';
                    box.style.padding = '12px 20px';
                    box.style.borderRadius = '6px';
                    box.style.boxShadow = '0 10px 15px -3px rgba(0, 0, 0, 0.4)';
                    box.style.fontSize = '14px';
                    box.style.fontWeight = '600';
                    box.style.display = 'flex';
                    box.style.justifyContent = 'space-between';
                    box.style.alignItems = 'center';
                    box.style.gap = '15px';
                    box.style.transition = 'opacity 0.3s ease';
                    
                    box.innerHTML = `
                        <span>✔️ ${texto}</span>
                        <span style="cursor: pointer; font-size: 16px;" onclick="this.parentElement.remove()">×</span>
                    `;
                    
                    container.appendChild(box);
                    setTimeout(() => {
                        box.style.opacity = '0';
                        setTimeout(() => box.remove(), 300);
                    }, 5000);
                }

                // Notificação de Erros
                function mostrarNotificacaoErro(texto) {
                    const container = document.getElementById('custom-alert-container');
                    const box = document.createElement('div');
                    box.style.backgroundColor = '#ef4444';
                    box.style.color = '#ffffff';
                    box.style.padding = '12px 20px';
                    box.style.borderRadius = '6px';
                    box.style.boxShadow = '0 10px 15px -3px rgba(0, 0, 0, 0.4)';
                    box.style.fontSize = '14px';
                    box.style.fontWeight = '600';
                    box.style.display = 'flex';
                    box.style.justifyContent = 'space-between';
                    box.style.alignItems = 'center';
                    box.style.gap = '15px';
                    box.style.transition = 'opacity 0.3s ease';
                    
                    box.innerHTML = `
                        <span>⚠️ ${texto}</span>
                        <span style="cursor: pointer; font-size: 16px;" onclick="this.parentElement.remove()">×</span>
                    `;
                    
                    container.appendChild(box);
                    setTimeout(() => {
                        box.style.opacity = '0';
                        setTimeout(() => box.remove(), 300);
                    }, 5000);
                }
            });
            </script>

        <?php else: ?>
            <!-- ECRÃ DE LISTAGEM GERAL DE ORDENS DE SERVIÇO (DASHBOARD OS) -->
            <div class="card">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px;">
                    <h2 class="card-title" style="margin-bottom: 0;">Registros de Ordens de Serviço</h2>
                    <a href="index.php?page=os&action=create" class="btn btn-primary">
                        Abrir Nova Ordem de Serviço
                    </a>
                </div>

                <?php if (empty($osList)): ?>
                    <p style="color: var(--text-muted); font-size: 14px;">Nenhuma ordem de serviço cadastrada no sistema.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th style="width: 70px;">ID</th>
                                    <th>Data Abertura</th>
                                    <th>Cliente proprietário</th>
                                    <th>Aparelho / Item</th>
                                    <th>Técnico Responsável</th>
                                    <th>Status</th>
                                    <th>Valor Total</th>
                                    <th style="width: 280px; text-align: right;">Ações</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($osList as $os): ?>
                                    <?php
                                    $baseRetirada = $os['updated_at'] ?: $os['data_abertura'];
                                    $diasAguardandoRetirada = 0;
                                    if (isWithdrawalStatus($os['status']) && $os['status'] !== 'entregue') {
                                        $diasAguardandoRetirada = (new DateTime($baseRetirada))->diff(new DateTime())->days;
                                    }
                                    $retiradaVencida = $diasAguardandoRetirada > $prazoMaximoRetirada;
                                    ?>
                                    <tr>
                                        <td><code>#<?php echo $os['id_os']; ?></code></td>
                                        <td><?php echo date('d/m/Y H:i', strtotime($os['data_abertura'])); ?></td>
                                        <td><strong><?php echo htmlspecialchars($os['cliente_nome']); ?></strong></td>
                                        <td>
                                            <?php echo htmlspecialchars($os['aparelho']); ?>
                                            <span style="display: block; font-size: 11.5px; color: var(--text-muted);"><?php echo htmlspecialchars($os['marca']); ?></span>
                                        </td>
                                        <td><?php echo htmlspecialchars($os['tecnico_nome']); ?></td>
                                        <td>
                                            <?php echo getStatusBadge($os['status']); ?>
                                            <?php if ($retiradaVencida): ?>
                                                <span class="badge badge-cancelada" style="margin-top: 6px;">Prazo vencido</span>
                                            <?php elseif (isWithdrawalStatus($os['status']) && $os['status'] !== 'entregue'): ?>
                                                <span style="display: block; font-size: 11.5px; color: var(--text-muted); margin-top: 4px;">
                                                    <?php echo $diasAguardandoRetirada; ?>/<?php echo $prazoMaximoRetirada; ?> dias para retirada
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <strong style="color: var(--success); font-size: 14.5px;">
                                                <?php echo htmlspecialchars($currencySymbol) . ' ' . formatDecimalOS($os['valor_total']); ?>
                                            </strong>
                                        </td>
                                        <td style="text-align: right;">
                                            <!-- Botão de Impressão Direta -->
                                            <a href="index.php?page=imprimir_os&id=<?php echo $os['id_os']; ?>" class="btn btn-secondary" style="padding: 4px 10px; font-size: 12px; margin-right: 4px; background-color: rgba(16, 185, 129, 0.1); color: #10b981; border-color: rgba(16, 185, 129, 0.2);">
                                                Entrada
                                            </a>
                                            <?php if (isWithdrawalStatus($os['status'])): ?>
                                                <a href="index.php?page=imprimir_retirada&id=<?php echo $os['id_os']; ?>" class="btn btn-secondary" style="padding: 4px 10px; font-size: 12px; margin-right: 4px; background-color: rgba(245, 158, 11, 0.1); color: #f59e0b; border-color: rgba(245, 158, 11, 0.2);">
                                                    Retirada
                                                </a>
                                            <?php endif; ?>
                                            <!-- Botão de Linha do Tempo/Auditoria -->
                                            <a href="index.php?page=historico_os&id=<?php echo $os['id_os']; ?>" class="btn btn-secondary" style="padding: 4px 10px; font-size: 12px; margin-right: 4px; background-color: rgba(59, 130, 246, 0.1); color: #3b82f6; border-color: rgba(59, 130, 246, 0.2);">
                                                Histórico
                                            </a>
                                            <a href="index.php?page=os&action=edit&id=<?php echo $os['id_os']; ?>" class="btn btn-secondary" style="padding: 4px 10px; font-size: 12px; margin-right: 4px;">
                                                Editar / Orçar
                                            </a>
                                            <button type="button" class="btn btn-danger btn-confirm-action" 
                                                    data-url="index.php?page=os&action=delete&id=<?php echo $os['id_os']; ?>" 
                                                    data-text="Atenção! Esta ação removerá permanentemente a OS #<?php echo $os['id_os']; ?> e todos os seus itens lançados. Deseja prosseguir?" 
                                                    style="padding: 4px 10px; font-size: 12px;">
                                                Excluir
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Controle JS do Modal de Confirmação customizado -->
            <script>
            document.addEventListener('DOMContentLoaded', function() {
                const modal = document.getElementById('custom-confirm-modal');
                const modalText = document.getElementById('confirm-modal-text');
                const modalOk = document.getElementById('confirm-modal-ok');
                const modalCancel = document.getElementById('custom-confirm-modal');

                document.querySelectorAll('.btn-confirm-action').forEach(btn => {
                    btn.addEventListener('click', function(e) {
                        e.preventDefault();
                        const url = this.getAttribute('data-url');
                        const text = this.getAttribute('data-text');

                        modalText.textContent = text;
                        modalOk.setAttribute('href', url);
                        modal.style.display = 'flex';
                    });
                });

                if (modalCancel) {
                    modalCancel.addEventListener('click', function(e) {
                        if (e.target === modal || e.target === document.getElementById('confirm-modal-cancel')) {
                            modal.style.display = 'none';
                        }
                    });
                }
            });
            </script>
        <?php endif; ?>
