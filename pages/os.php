<?php
/**
 * OS Master - Gestão e Abertura de Ordens de Serviço (OS)
 * * Este ficheiro implementa a lógica Mestre-Detalhe completa para gerir OS,
 * permitir associar clientes, equipamentos, técnicos, lançar peças em tempo real
 * com cálculo de totais automatizado e gerir o histórico de modificações.
 * Incorpora janelas modais assíncronas (AJAX) para cadastros rápidos de Clientes e Equipamentos.
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

// Carrega a classe de controlo base Pessoa necessária para validações assíncronas
require_once BASE_PATH . '/classes/Pessoa.php';

// ==========================================================================
// CONTROLADOR DE REQUISIÇÕES ASSÍNCRONAS (AJAX / Fetch API)
// ==========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['ajax_action'])) {
    // Descartamos todo o lixo HTML do buffer de saída acumulado pelo index.php
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/json');
    $ajaxAction = $_GET['ajax_action'];

    // 1. AJAX: Criar Cliente Rápido (Com todos os campos requisitados)
    if ($ajaxAction === 'create_cliente') {
        $tipo_pessoa = trim(filter_input(INPUT_POST, 'modal_tipo_pessoa', FILTER_DEFAULT)) ?: 'FISICA';
        $nome        = trim(filter_input(INPUT_POST, 'modal_nome', FILTER_DEFAULT));
        $cpf_cnpj    = trim(filter_input(INPUT_POST, 'modal_cpf_cnpj', FILTER_DEFAULT));
        $rg_ie       = trim(filter_input(INPUT_POST, 'modal_rg_ie', FILTER_DEFAULT));
        $telefone    = trim(filter_input(INPUT_POST, 'modal_telefone', FILTER_DEFAULT));
        $cep         = trim(filter_input(INPUT_POST, 'modal_cep', FILTER_DEFAULT));
        $endereco    = trim(filter_input(INPUT_POST, 'modal_endereco', FILTER_DEFAULT));
        $numero      = trim(filter_input(INPUT_POST, 'modal_numero', FILTER_DEFAULT));
        $bairro      = trim(filter_input(INPUT_POST, 'modal_bairro', FILTER_DEFAULT));
        $id_cidade   = filter_input(INPUT_POST, 'modal_id_cidade', FILTER_VALIDATE_INT);

        if (empty($nome) || !$id_cidade) {
            echo json_encode(['success' => false, 'error' => 'Nome e Cidade são de preenchimento obrigatório.']);
            exit;
        }

        try {
            $pdo->beginTransaction();

            $pessoaData = [
                'tipo_pessoa' => $tipo_pessoa,
                'nome'        => $nome,
                'cpf_cnpj'    => $cpf_cnpj,
                'rg_ie'       => $rg_ie,
                'telefone'    => $telefone,
                'cep'         => $cep,
                'endereco'    => $endereco,
                'numero'      => $numero,
                'bairro'      => $bairro,
                'id_cidade'   => $id_cidade,
                'status'      => 1
            ];

            // Executa a persistência utilizando as regras estruturais e de herança da classe Pessoa
            $id_pessoa = Pessoa::create($pdo, $pessoaData);

            // Insere na tabela dependente 'cliente'
            $sqlCliente = "INSERT INTO cliente (id_pessoa, data_ultima_interacao) VALUES (:id_pessoa, NULL)";
            $pdo->prepare($sqlCliente)->execute([':id_pessoa' => $id_pessoa]);

            $pdo->commit();
            echo json_encode([
                'success' => true,
                'id'      => $id_pessoa,
                'nome'    => $nome,
                'cpf_cnpj'=> $cpf_cnpj
            ]);
            exit;
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            exit;
        }
    }

    // 2. AJAX: Criar Equipamento Rápido
    if ($ajaxAction === 'create_equipamento') {
        $aparelho     = trim(filter_input(INPUT_POST, 'modal_aparelho', FILTER_DEFAULT));
        $marca        = trim(filter_input(INPUT_POST, 'modal_marca', FILTER_DEFAULT));
        $modelo       = trim(filter_input(INPUT_POST, 'modal_modelo', FILTER_DEFAULT));
        $numero_serie = trim(filter_input(INPUT_POST, 'modal_numero_serie', FILTER_DEFAULT));
        $id_cliente   = filter_input(INPUT_POST, 'modal_id_cliente', FILTER_VALIDATE_INT);

        if (empty($aparelho) || !$id_cliente) {
            echo json_encode(['success' => false, 'error' => 'Aparelho e proprietário são de preenchimento obrigatório.']);
            exit;
        }

        try {
            $sqlInsert = "INSERT INTO equipamento (aparelho, marca, modelo, numero_serie, id_cliente) 
                          VALUES (:aparelho, :marca, :modelo, :numero_serie, :id_cliente)";
            $stmt = $pdo->prepare($sqlInsert);
            $stmt->execute([
                ':aparelho'     => $aparelho,
                ':marca'        => $marca,
                ':modelo'       => $modelo,
                ':numero_serie' => $numero_serie,
                ':id_cliente'   => $id_cliente
            ]);
            $id_eq = $pdo->lastInsertId();

            echo json_encode([
                'success'  => true,
                'id'       => $id_eq,
                'aparelho' => $aparelho,
                'marca'    => $marca,
                'modelo'   => $modelo,
                'id_cliente' => $id_cliente
            ]);
            exit;
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'error' => 'Erro interno ao tentar registar o equipamento.']);
            exit;
        }
    }
}

// Auto-healing: Altera a coluna 'status' de ENUM estrito para VARCHAR extensível para suportar os novos estados
try {
    $pdo->exec("ALTER TABLE `os` MODIFY `status` varchar(50) NOT NULL;");
} catch (PDOException $e) {
    // Silencioso se já estiver alterado ou se não houver privilégios
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

// Retorna o elemento HTML do badge estilizado de acordo com o novo fluxo de progresso
if (!function_exists('getStatusBadge')) {
    function getStatusBadge($status): string {
        switch ($status) {
            case 'aguardando_analise':
                return '<span class="badge badge-aberta">Aguardando Análise</span>';
            case 'em_diagnostico':
                return '<span class="badge badge-aberta">Em Diagnóstico</span>';
            case 'aguardando_aprovacao':
                return '<span class="badge badge-em-andamento">Aguardando Aprovação</span>';
            case 'aguardando_peca':
                return '<span class="badge badge-em-andamento">Aguardando Peça</span>';
            case 'em_reparo':
                return '<span class="badge badge-em-andamento">Em Reparo</span>';
            case 'reparo_concluido':
                return '<span class="badge badge-finalizada">Reparo Concluído</span>';
            case 'sem_conserto':
                return '<span class="badge badge-cancelada">Sem Conserto</span>';
            case 'pronto_para_retirada':
                return '<span class="badge badge-finalizada">Pronto para Retirada</span>';
            case 'entregue':
                return '<span class="badge badge-finalizada">Entregue</span>';
            case 'abandonado':
                return '<span class="badge badge-cancelada">Abandonado</span>';
            default:
                return '<span class="badge badge-aberta">' . htmlspecialchars(ucfirst($status)) . '</span>';
        }
    }
}

// Recupera símbolo monetário
$currencySymbol = 'R$';
try {
    $stmtCurrency = $pdo->query("SELECT * FROM config_sistema LIMIT 1");
    $sysConfig = $stmtCurrency->fetch();
    if ($sysConfig && isset($sysConfig['moeda']) && !empty($sysConfig['moeda'])) {
        $currencySymbol = $sysConfig['moeda'];
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

    // Coleta de arrays de peças dinâmicas vindas do formulário
    $postProdutos    = $_POST['item_produto_id'] ?? [];
    $postQuantidades = $_POST['item_quantidade'] ?? [];

    if (!$id_cliente || !$id_equipamento || !$id_tecnico || empty($status)) {
        $message = 'Cliente, Equipamento, Técnico Responsável e Status são de preenchimento obrigatório.';
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
                            // Validação estrita de estoque antes da baixa (Ignora estoque se o status indicar encerramento sem conserto/abandono)
                            if ($product['estoque'] < $qty && !in_array($status, ['sem_conserto', 'abandonado'])) {
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

                            // Abate estoque caso o status não seja de encerramento improdutivo
                            if (!in_array($status, ['sem_conserto', 'abandonado'])) {
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
        $stmtDel = $pdo->prepare("DELETE FROM os WHERE id_os = :id");
        $stmtDel->execute([':id' => $editId]);
        $message = 'Ordem de Serviço removida com sucesso do sistema.';
        $messageType = 'success';
    } catch (PDOException $e) {
        $message = 'Não é possível remover a OS selecionada devido a dependências ativas.';
        $messageType = 'danger';
    }
    $action = 'list';
}

// ==========================================================================
// Recuperação das Listas de Suporte
// ==========================================================================
$clientesList = [];
$cidadesList = [];
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
        WHERE p.status = 1 
        ORDER BY p.nome ASC
    ")->fetchAll();

    // 2. Lista de Cidades com Estados para o dropdown do cadastro rápido de clientes
    $cidadesList = $pdo->query("
        SELECT c.id_cidade, c.nome AS cidade, e.sigla AS uf 
        FROM cidade c 
        INNER JOIN estado e ON c.id_estado = e.id_estado 
        ORDER BY c.nome ASC
    ")->fetchAll();

    // 3. Equipamentos mapeados para uso em filtro JS
    $equipamentosList = $pdo->query("
        SELECT id_equipamento, aparelho, marca, modelo, id_cliente 
        FROM equipamento 
        ORDER BY aparelho ASC
    ")->fetchAll();

    // 4. Técnicos/Funcionários ativos no sistema
    $tecnicosList = $pdo->query("
        SELECT p.id_pessoa, p.nome, f.cargo 
        FROM funcionario f 
        INNER JOIN pessoa p ON f.id_pessoa = p.id_pessoa 
        WHERE p.status = 1 AND f.cargo IN ('Técnico', 'Administrador')
        ORDER BY p.nome ASC
    ")->fetchAll();

    // 5. Produtos em estoque para listagem de peças
    $produtosList = $pdo->query("SELECT id_produto, descricao, valor, estoque FROM produto ORDER BY descricao ASC")->fetchAll();

    // 6. Listagem Geral de Ordens de Serviço (Junções Completas)
    $sqlOS = "
        SELECT o.id_os, o.data_abertura, o.status, o.valor_total, 
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
                                        <button type="button" id="btn-open-modal-cliente" class="btn btn-secondary" style="padding: 10px 14px; font-weight: 700; font-size: 16px;" title="Registrar Cliente Rápido">+</button>
                                    </div>
                                </div>

                                <div class="form-group">
                                    <label for="id_equipamento" class="form-label">Equipamento Associado</label>
                                    <div style="display: flex; gap: 8px;">
                                        <select id="id_equipamento" name="id_equipamento" class="form-control" required disabled style="flex-grow: 1;">
                                            <option value="">Selecione o cliente primeiro...</option>
                                        </select>
                                        <button type="button" id="btn-open-modal-equipamento" class="btn btn-secondary" style="padding: 10px 14px; font-weight: 700; font-size: 16px;" title="Registrar Equipamento Rápido" disabled>+</button>
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
                                    <select id="status" name="status" class="form-control" required>
                                        <option value="aguardando_analise" <?php echo ($editData['status'] === 'aguardando_analise') ? 'selected' : ''; ?>>Aguardando Análise</option>
                                        <option value="em_diagnostico" <?php echo ($editData['status'] === 'em_diagnostico') ? 'selected' : ''; ?>>Em Diagnóstico</option>
                                        <option value="aguardando_aprovacao" <?php echo ($editData['status'] === 'aguardando_aprovacao') ? 'selected' : ''; ?>>Aguardando Aprovação</option>
                                        <option value="aguardando_peca" <?php echo ($editData['status'] === 'aguardando_peca') ? 'selected' : ''; ?>>Aguardando Peça</option>
                                        <option value="em_reparo" <?php echo ($editData['status'] === 'em_reparo') ? 'selected' : ''; ?>>Em Reparo</option>
                                        <option value="reparo_concluido" <?php echo ($editData['status'] === 'reparo_concluido') ? 'selected' : ''; ?>>Reparo Concluído</option>
                                        <option value="sem_conserto" <?php echo ($editData['status'] === 'sem_conserto') ? 'selected' : ''; ?>>Sem Conserto</option>
                                        <option value="pronto_para_retirada" <?php echo ($editData['status'] === 'pronto_para_retirada') ? 'selected' : ''; ?>>Pronto para Retirada</option>
                                        <option value="entregue" <?php echo ($editData['status'] === 'entregue') ? 'selected' : ''; ?>>Entregue</option>
                                        <option value="abandonado" <?php echo ($editData['status'] === 'abandonado') ? 'selected' : ''; ?>>Abandonado</option>
                                    </select>
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
                                            <option value="<?php echo $prod['id_produto']; ?>" data-preco="<?php echo $prod['valor']; ?>" data-estoque="<?php echo $prod['estoque']; ?>">
                                                <?php echo htmlspecialchars($prod['descricao']) . ' (R$ ' . formatDecimalOS($prod['valor']) . ') [Estoque: ' . $prod['estoque'] . ']'; ?>
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

            <!-- JANELA MODAL: REGISTRO RÁPIDO DE CLIENTES COMPLETO -->
            <div id="modal-novo-cliente" style="display: none; position: fixed; inset: 0; background-color: rgba(15, 23, 42, 0.85); align-items: center; justify-content: center; z-index: 9999; padding: 20px; overflow-y: auto;">
                <div style="background-color: var(--bg-card); border: 1px solid var(--border-color); border-radius: var(--radius-lg); max-width: 650px; width: 100%; padding: 32px; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.6); display: flex; flex-direction: column; gap: 20px;">
                    <h3 style="font-size: 18px; font-weight: 700; color: var(--text-main); margin-bottom: 4px; border-bottom: 1px solid var(--border-color); padding-bottom: 12px;">Registrar Novo Cliente</h3>
                    
                    <form id="form-modal-cliente" autocomplete="off">
                        <div style="display: flex; gap: 24px; margin-bottom: 16px; background-color: rgba(255,255,255,0.02); padding: 12px; border-radius: var(--radius-sm); border: 1px solid var(--border-color);">
                            <span class="form-label" style="margin-bottom: 0; align-self: center;">Tipo:</span>
                            <label style="display: inline-flex; align-items: center; gap: 8px; cursor: pointer;">
                                <input type="radio" name="modal_tipo_pessoa" value="FISICA" checked style="width: 16px; height: 16px;"> Pessoa Física
                            </label>
                            <label style="display: inline-flex; align-items: center; gap: 8px; cursor: pointer;">
                                <input type="radio" name="modal_tipo_pessoa" value="JURIDICA" style="width: 16px; height: 16px;"> Pessoa Jurídica
                            </label>
                        </div>

                        <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 16px;">
                            <div class="form-group">
                                <label id="modal_lbl_nome" class="form-label">Nome Completo</label>
                                <input type="text" id="modal_nome" name="modal_nome" class="form-control" required placeholder="Ex: João da Silva">
                            </div>
                            <div class="form-group">
                                <label id="modal_lbl_rg_ie" class="form-label">RG</label>
                                <input type="text" id="modal_rg_ie" name="modal_rg_ie" class="form-control" placeholder="Ex: 0000000">
                            </div>
                        </div>

                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                            <div class="form-group">
                                <label id="modal_lbl_cpf_cnpj" class="form-label">CPF (Opcional)</label>
                                <input type="text" id="modal_cpf_cnpj" name="modal_cpf_cnpj" class="form-control" placeholder="000.000.000-00">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Telefone de Contacto</label>
                                <input type="text" id="modal_telefone" name="modal_telefone" class="form-control" placeholder="(67) 99999-9999">
                            </div>
                        </div>

                        <div style="display: grid; grid-template-columns: 1fr 2fr 1fr; gap: 16px;">
                            <div class="form-group">
                                <label class="form-label">CEP (Auto-completar)</label>
                                <input type="text" id="modal_cep" name="modal_cep" class="form-control" placeholder="79800-000" maxlength="9">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Endereço</label>
                                <input type="text" id="modal_endereco" name="modal_endereco" class="form-control" placeholder="Rua, Avenida">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Número</label>
                                <input type="text" id="modal_numero" name="modal_numero" class="form-control" placeholder="123">
                            </div>
                        </div>

                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                            <div class="form-group">
                                <label class="form-label">Bairro</label>
                                <input type="text" id="modal_bairro" name="modal_bairro" class="form-control" placeholder="Centro">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Cidade</label>
                                <select id="modal_id_cidade" name="modal_id_cidade" class="form-control" required>
                                    <option value="">Selecione a cidade...</option>
                                    <?php foreach ($cidadesList as $cid): ?>
                                        <option value="<?php echo $cid['id_cidade']; ?>">
                                            <?php echo htmlspecialchars($cid['cidade']) . ' (' . htmlspecialchars($cid['uf']) . ')'; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <div style="display: flex; justify-content: flex-end; gap: 12px; margin-top: 24px; border-top: 1px solid var(--border-color); padding-top: 16px;">
                            <button type="button" id="btn-close-modal-cliente" class="btn btn-secondary">Cancelar</button>
                            <button type="submit" class="btn btn-primary">Registrar Cliente</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- JANELA MODAL: REGISTRO RÁPIDO DE EQUIPAMENTOS -->
            <div id="modal-novo-equipamento" style="display: none; position: fixed; inset: 0; background-color: rgba(15, 23, 42, 0.85); align-items: center; justify-content: center; z-index: 9999; padding: 20px; overflow-y: auto;">
                <div style="background-color: var(--bg-card); border: 1px solid var(--border-color); border-radius: var(--radius-lg); max-width: 500px; width: 100%; padding: 32px; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.6); display: flex; flex-direction: column; gap: 20px;">
                    <h3 style="font-size: 18px; font-weight: 700; color: var(--text-main); margin-bottom: 4px; border-bottom: 1px solid var(--border-color); padding-bottom: 12px;">Registrar Equipamento</h3>
                    
                    <p style="font-size: 13px; color: var(--text-muted); margin: 0; background-color: rgba(255,255,255,0.02); padding: 10px; border-radius: var(--radius-sm); border: 1px solid var(--border-color);">
                        Proprietário: <strong id="modal_proprietario_nome" style="color: var(--primary);">Nenhum</strong>
                    </p>

                    <form id="form-modal-equipamento" autocomplete="off">
                        <input type="hidden" id="modal_id_cliente" name="modal_id_cliente">

                        <div class="form-group">
                            <label class="form-label">Nome do Aparelho / Item</label>
                            <input type="text" id="modal_aparelho" name="modal_aparelho" class="form-control" placeholder="Ex: Notebook, Smartphone" required>
                        </div>

                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                            <div class="form-group">
                                <label class="form-label">Marca</label>
                                <input type="text" id="modal_marca" name="modal_marca" class="form-control" placeholder="Ex: Dell, Apple">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Modelo</label>
                                <input type="text" id="modal_modelo" name="modal_modelo" class="form-control" placeholder="Ex: Inspiron 15">
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Número de Série (N/S)</label>
                            <input type="text" id="modal_numero_serie" name="modal_numero_serie" class="form-control" placeholder="Ex: BR123456">
                        </div>

                        <div style="display: flex; justify-content: flex-end; gap: 12px; margin-top: 24px; border-top: 1px solid var(--border-color); padding-top: 16px;">
                            <button type="button" id="btn-close-modal-equipamento" class="btn btn-secondary">Cancelar</button>
                            <button type="submit" class="btn btn-primary">Registrar Equipamento</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- MOTOR DE LOGICA DE CONTROLE (ViaCEP, Filtros de Equipamento, Cálculos Dinâmicos) -->
            <script>
            document.addEventListener('DOMContentLoaded', function() {
                const form = document.getElementById('formOS');
                const selectCliente = document.getElementById('id_cliente');
                const selectEquipamento = document.getElementById('id_equipamento');
                const btnAddEquipamento = document.getElementById('btn-open-modal-equipamento');
                
                // Arrays predefinidos de equipamentos vindos do PHP
                let equipamentos = <?php echo json_encode($equipamentosList); ?>;
                const selectedEquipamentoId = "<?php echo $editData['id_equipamento']; ?>";

                // 1. FILTRAGEM DINÂMICA DE EQUIPAMENTOS POR CLIENTE
                function filtrarEquipamentos() {
                    const clienteId = selectCliente.value;
                    selectEquipamento.innerHTML = '<option value="">Selecione o equipamento...</option>';
                    
                    if (clienteId === '') {
                        selectEquipamento.disabled = true;
                        btnAddEquipamento.disabled = true;
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
                    btnAddEquipamento.disabled = false; // Habilita o botão "+" de equipamento já que temos cliente selecionado
                }

                if (selectCliente) {
                    selectCliente.addEventListener('change', filtrarEquipamentos);
                    if (selectCliente.value !== '') {
                        filtrarEquipamentos();
                    }
                }

                // 2. CONTROLE DE ABERTURA E OPERAÇÕES DOS MODALS ASSÍNCRONOS
                const modalCliente = document.getElementById('modal-novo-cliente');
                const modalEquipamento = document.getElementById('modal-novo-equipamento');

                const btnOpenCliente = document.getElementById('btn-open-modal-cliente');
                const btnCloseCliente = document.getElementById('btn-close-modal-cliente');
                const formModalCliente = document.getElementById('form-modal-cliente');

                const btnCloseEquipamento = document.getElementById('btn-close-modal-equipamento');
                const formModalEquipamento = document.getElementById('form-modal-equipamento');

                // Manipuladores de abertura e fechamento
                btnOpenCliente.addEventListener('click', () => {
                    modalCliente.style.display = 'flex';
                    document.getElementById('modal_nome').focus();
                });

                btnCloseCliente.addEventListener('click', () => {
                    formModalCliente.reset();
                    modalCliente.style.display = 'none';
                });

                btnAddEquipamento.addEventListener('click', () => {
                    const clienteNome = selectCliente.options[selectCliente.selectedIndex].text;
                    document.getElementById('modal_proprietario_nome').textContent = clienteNome;
                    document.getElementById('modal_id_cliente').value = selectCliente.value;
                    modalEquipamento.style.display = 'flex';
                    document.getElementById('modal_aparelho').focus();
                });

                btnCloseEquipamento.addEventListener('click', () => {
                    formModalEquipamento.reset();
                    modalEquipamento.style.display = 'none';
                });

                // Tratamento dinâmico de Tipo de Pessoa no Modal
                const modalCpfCnpjInput = document.getElementById('modal_cpf_cnpj');
                const modalLblNome = document.getElementById('modal_lbl_nome');
                const modalLblCpfCnpj = document.getElementById('modal_lbl_cpf_cnpj');
                const modalLblRgIe = document.getElementById('modal_lbl_rg_ie');

                function ajustarModalTipoPessoa() {
                    const selectedType = document.querySelector('input[name="modal_tipo_pessoa"]:checked').value;
                    if (selectedType === 'FISICA') {
                        modalLblNome.textContent = 'Nome Completo';
                        modalLblCpfCnpj.textContent = 'CPF (Opcional)';
                        modalCpfCnpjInput.placeholder = '000.000.000-00';
                        modalLblRgIe.textContent = 'RG';
                    } else {
                        modalLblNome.textContent = 'Razão Social';
                        modalLblCpfCnpj.textContent = 'CNPJ (Opcional)';
                        modalCpfCnpjInput.placeholder = '00.000.000/0001-00';
                        modalLblRgIe.textContent = 'Inscrição Estadual (IE)';
                    }
                    modalCpfCnpjInput.value = '';
                }

                document.querySelectorAll('input[name="modal_tipo_pessoa"]').forEach(radio => {
                    radio.addEventListener('change', ajustarModalTipoPessoa);
                });

                // Autocompletar CEP na Janela Modal de Clientes
                const modalCepInput = document.getElementById('modal_cep');
                const modalEnderecoInput = document.getElementById('modal_endereco');
                const modalBairroInput = document.getElementById('modal_bairro');
                const modalCidadeSelect = document.getElementById('modal_id_cidade');

                if (modalCepInput) {
                    modalCepInput.addEventListener('input', function() {
                        let v = this.value.replace(/\D/g, '');
                        if (v.length > 8) v = v.slice(0, 8);
                        v = v.replace(/^(\d{5})(\d{1,3})$/, '$1-$2');
                        this.value = v;

                        const cleanCEP = v.replace(/\D/g, '');
                        if (cleanCEP.length === 8) {
                            buscarCepModal(cleanCEP);
                        }
                    });
                }

                function buscarCepModal(cep) {
                    modalCepInput.style.borderColor = 'var(--primary)';
                    fetch(`https://viacep.com.br/ws/${cep}/json/`)
                        .then(response => response.json())
                        .then(data => {
                            if (data.erro) {
                                mostrarNotificacaoErro("CEP não localizado na base de dados.");
                                modalCepInput.style.borderColor = '#ef4444';
                                return;
                            }
                            modalEnderecoInput.value = data.logradouro || '';
                            modalBairroInput.value = data.bairro || '';
                            
                            const localidade = data.localidade.toLowerCase();
                            for (let i = 0; i < modalCidadeSelect.options.length; i++) {
                                const optText = modalCidadeSelect.options[i].text.toLowerCase();
                                if (optText.includes(localidade)) {
                                    modalCidadeSelect.selectedIndex = i;
                                    break;
                                }
                            }
                            modalCepInput.style.borderColor = 'var(--success)';
                        })
                        .catch(() => {
                            mostrarNotificacaoErro("Não foi possível conectar com o serviço de CEP.");
                            modalCepInput.style.borderColor = '#ef4444';
                        });
                }

                // Máscaras de entrada em tempo real para os campos do Modal
                if (modalCpfCnpjInput) {
                    modalCpfCnpjInput.addEventListener('input', function() {
                        const selectedType = document.querySelector('input[name="modal_tipo_pessoa"]:checked').value;
                        let v = this.value.replace(/\D/g, '');
                        if (selectedType === 'FISICA') {
                            if (v.length > 11) v = v.slice(0, 11);
                            v = v.replace(/(\d{3})(\d)/, '$1.$2');
                            v = v.replace(/(\d{3})(\d)/, '$1.$2');
                            v = v.replace(/(\d{3})(\d{1,2})$/, '$1-$2');
                        } else {
                            if (v.length > 14) v = v.slice(0, 14);
                            v = v.replace(/^(\d{2})(\d)/, '$1.$2');
                            v = v.replace(/^(\d{2})\.(\d{3})(\d)/, '$1.$2.$3');
                            v = v.replace(/\.(\d{3})(\d)/, '.$1/$2');
                            v = v.replace(/(\d{4})(\d{1,2})$/, '$1-$2');
                        }
                        this.value = v;
                    });
                }

                const modalTel = document.getElementById('modal_telefone');
                if (modalTel) {
                    modalTel.addEventListener('input', function() {
                        let v = this.value.replace(/\D/g, '');
                        if (v.length > 11) v = v.slice(0, 11);
                        if (v.length > 10) {
                            v = v.replace(/^(\d{2})(\d{5})(\d{4})$/, '($1) $2-$3');
                        } else if (v.length > 5) {
                            v = v.replace(/^(\d{2})(\d{4})(\d{4})$/, '($1) $2-$3');
                        } else if (v.length > 2) {
                            v = v.replace(/^(\d{2})(\d*)$/, '($1) $2');
                        } else {
                            v = v.replace(/^(\d*)$/, '($1');
                        }
                        this.value = v;
                    });
                }

                // Auxiliares de Validação de Documentos no modal
                function validarCPF(cpf) {
                    cpf = cpf.replace(/\D/g, '');
                    if (cpf.length !== 11 || /^(\d)\1{10}$/.test(cpf)) return false;
                    let soma = 0, resto;
                    for (let i = 1; i <= 9; i++) soma += parseInt(cpf.substring(i-1, i)) * (11 - i);
                    resto = (soma * 10) % 11;
                    if (resto === 10 || resto === 11) resto = 0;
                    if (resto !== parseInt(cpf.substring(9, 10))) return false;
                    soma = 0;
                    for (let i = 1; i <= 10; i++) soma += parseInt(cpf.substring(i-1, i)) * (12 - i);
                    resto = (soma * 10) % 11;
                    if (resto === 10 || resto === 11) resto = 0;
                    if (resto !== parseInt(cpf.substring(10, 11))) return false;
                    return true;
                }

                function validarCNPJ(cnpj) {
                    cnpj = cnpj.replace(/\D/g, '');
                    if (cnpj.length !== 14 || /^(\d)\1{13}$/.test(cnpj)) return false;
                    let tamanho = cnpj.length - 2;
                    let numeros = cnpj.substring(0, tamanho);
                    let digitos = cnpj.substring(tamanho);
                    let soma = 0, pos = tamanho - 7;
                    for (let i = tamanho; i >= 1; i--) {
                        soma += numeros.charAt(tamanho - i) * pos--;
                        if (pos < 2) pos = 9;
                    }
                    let resultado = soma % 11 < 2 ? 0 : 11 - (soma % 11);
                    if (resultado != digitos.charAt(0)) return false;
                    tamanho = tamanho + 1;
                    numeros = cnpj.substring(0, tamanho);
                    soma = 0;
                    pos = tamanho - 7;
                    for (let i = tamanho; i >= 1; i--) {
                        soma += numeros.charAt(tamanho - i) * pos--;
                        if (pos < 2) pos = 9;
                    }
                    resultado = soma % 11 < 2 ? 0 : 11 - (soma % 11);
                    if (resultado != digitos.charAt(1)) return false;
                    return true;
                }

                // SUBMISSÃO DOS FORMULÁRIOS DE MODAL VIA FETCH (AJAX)
                formModalCliente.addEventListener('submit', function(e) {
                    e.preventDefault();
                    
                    const docVal = modalCpfCnpjInput.value;
                    const tipo = document.querySelector('input[name="modal_tipo_pessoa"]:checked').value;

                    if (docVal !== '') {
                        if (tipo === 'FISICA' && !validarCPF(docVal)) {
                            mostrarNotificacaoErro('O CPF informado no cadastro rápido é inválido.');
                            modalCpfCnpjInput.focus();
                            return;
                        } else if (tipo === 'JURIDICA' && !validarCNPJ(docVal)) {
                            mostrarNotificacaoErro('O CNPJ informado no cadastro rápido é inválido.');
                            modalCpfCnpjInput.focus();
                            return;
                        }
                    }

                    const formData = new FormData(this);
                    fetch('index.php?page=os&ajax_action=create_cliente', {
                        method: 'POST',
                        body: formData
                    })
                    .then(res => res.json())
                    .then(data => {
                        if (data.success) {
                            // Adiciona dinamicamente a nova opção de cliente no dropdown principal e seleciona-a
                            const opt = document.createElement('option');
                            opt.value = data.id;
                            opt.text = data.nome + (data.cpf_cnpj ? ` (${data.cpf_cnpj})` : '');
                            opt.selected = true;
                            selectCliente.appendChild(opt);

                            // Limpa e fecha a modal
                            formModalCliente.reset();
                            modalCliente.style.display = 'none';

                            // Força a atualização do seletor de equipamentos correspondente
                            filtrarEquipamentos();
                            mostrarNotificacaoSucesso('Cliente cadastrado e selecionado com sucesso!');
                        } else {
                            mostrarNotificacaoErro(data.error || 'Não foi possível cadastrar o cliente.');
                        }
                    })
                    .catch(() => {
                        mostrarNotificacaoErro('Erro de comunicação assíncrona com o servidor.');
                    });
                });

                formModalEquipamento.addEventListener('submit', function(e) {
                    e.preventDefault();
                    const formData = new FormData(this);

                    fetch('index.php?page=os&ajax_action=create_equipamento', {
                        method: 'POST',
                        body: formData
                    })
                    .then(res => res.json())
                    .then(data => {
                        if (data.success) {
                            // Adiciona o equipamento inserido ao array local do JS para que a filtragem dinâmica permaneça integrada!
                            equipamentos.push({
                                id_equipamento: data.id,
                                aparelho: data.aparelho,
                                marca: data.marca,
                                modelo: data.modelo,
                                id_cliente: data.id_cliente
                            });

                            // Atualiza os dropdowns
                            const novoId = data.id;
                            selectEquipamento.innerHTML = '<option value="">Selecione o equipamento...</option>';
                            const filtrados = equipamentos.filter(eq => eq.id_cliente == data.id_cliente);
                            
                            filtrados.forEach(eq => {
                                const option = document.createElement('option');
                                option.value = eq.id_equipamento;
                                option.text = `${eq.aparelho} ${eq.marca ? `(${eq.marca})` : ''} ${eq.modelo ? `[${eq.modelo}]` : ''}`;
                                if (eq.id_equipamento == novoId) {
                                    option.selected = true;
                                }
                                selectEquipamento.appendChild(option);
                            });
                            selectEquipamento.disabled = false;

                            // Fecha modal e reseta formulário
                            formModalEquipamento.reset();
                            modalEquipamento.style.display = 'none';
                            mostrarNotificacaoSucesso('Equipamento cadastrado e vinculado com sucesso!');
                        } else {
                            mostrarNotificacaoErro(data.error || 'Erro ao registrar o equipamento.');
                        }
                    })
                    .catch(() => {
                        mostrarNotificacaoErro('Erro ao salvar as informações do equipamento.');
                    });
                });


                // 3. IMPEDE A SUBMISSÃO DO FORMULÁRIO AO TECLAR ENTER
                if (form) {
                    form.addEventListener('keydown', function(e) {
                        if (e.key === 'Enter' && e.target.tagName === 'INPUT') {
                            e.preventDefault();
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
                        const descricao = option.text.split(' (');

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
                            <td style="text-align: right;">R$ ${floatToString(peca.subtotal)}</td>
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

                    displayPecasTotal.textContent = 'R$ ' + floatToString(totalPecas);
                    displayOSTotal.textContent = 'R$ ' + floatToString(totalGeral);
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
                                        </td>
                                        <td>
                                            <strong style="color: var(--success); font-size: 14.5px;">
                                                <?php echo htmlspecialchars($currencySymbol) . ' ' . formatDecimalOS($os['valor_total']); ?>
                                            </strong>
                                        </td>
                                        <td style="text-align: right;">
                                            <!-- Botão de Impressão Direta -->
                                            <a href="index.php?page=imprimir_os&id=<?php echo $os['id_os']; ?>" class="btn btn-secondary" style="padding: 4px 10px; font-size: 12px; margin-right: 4px; background-color: rgba(16, 185, 129, 0.1); color: #10b981; border-color: rgba(16, 185, 129, 0.2);">
                                                Imprimir
                                            </a>
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
