<?php
/**
 * OS Master - Gestão e Cadastro de Cidades
 * * Este ficheiro implementa o CRUD (Criar, Ler, Atualizar, Eliminar)
 * para a tabela 'cidade', conectando-a dinamicamente à tabela 'estado'.
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

$message = '';
$messageType = ''; // 'success' ou 'danger'

// Captura parâmetros de ação para edição ou eliminação
$action = $_GET['action'] ?? 'list';
$editId = $_GET['id'] ?? null;

// Instância de dados para o formulário de edição
$editData = ['nome' => '', 'id_estado' => ''];

// ==========================================================================
// Processamento de Ações do Formulário (POST)
// ==========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formAction = $_POST['form_action'] ?? '';

    // 1. AÇÃO: INSERIR NOVA CIDADE
    if ($formAction === 'create') {
        $nome = trim(filter_input(INPUT_POST, 'nome', FILTER_DEFAULT));
        $id_estado = filter_input(INPUT_POST, 'id_estado', FILTER_VALIDATE_INT);

        if (empty($nome) || !$id_estado) {
            $message = 'Todos os campos são de preenchimento obrigatório.';
            $messageType = 'danger';
        } else {
            try {
                // Validação contra duplicados (Mesmo nome de cidade no mesmo estado)
                $stmtCheck = $pdo->prepare("SELECT COUNT(*) FROM cidade WHERE nome = :nome AND id_estado = :id_estado");
                $stmtCheck->execute([':nome' => $nome, ':id_estado' => $id_estado]);
                
                if ($stmtCheck->fetchColumn() > 0) {
                    $message = 'Já existe uma cidade com este nome registada neste estado.';
                    $messageType = 'danger';
                } else {
                    // Inserção segura
                    $stmtInsert = $pdo->prepare("INSERT INTO cidade (nome, id_estado) VALUES (:nome, :id_estado)");
                    $stmtInsert->execute([':nome' => $nome, ':id_estado' => $id_estado]);
                    
                    $message = 'Cidade registada com sucesso!';
                    $messageType = 'success';
                }
            } catch (PDOException $e) {
                $message = 'Erro ao processar o registo no servidor.';
                $messageType = 'danger';
            }
        }
    }

    // 2. AÇÃO: ATUALIZAR CIDADE EXISTENTE
    if ($formAction === 'update' && $editId) {
        $nome = trim(filter_input(INPUT_POST, 'nome', FILTER_DEFAULT));
        $id_estado = filter_input(INPUT_POST, 'id_estado', FILTER_VALIDATE_INT);

        if (empty($nome) || !$id_estado) {
            $message = 'Todos os campos são de preenchimento obrigatório.';
            $messageType = 'danger';
        } else {
            try {
                // Validação contra duplicados ignorando a própria cidade que está a ser editada
                $stmtCheck = $pdo->prepare("SELECT COUNT(*) FROM cidade WHERE nome = :nome AND id_estado = :id_estado AND id_cidade != :id");
                $stmtCheck->execute([':nome' => $nome, ':id_estado' => $id_estado, ':id' => $editId]);
                
                if ($stmtCheck->fetchColumn() > 0) {
                    $message = 'Já existe outra cidade com este nome registada neste estado.';
                    $messageType = 'danger';
                } else {
                    // Atualização segura
                    $stmtUpdate = $pdo->prepare("UPDATE cidade SET nome = :nome, id_estado = :id_estado WHERE id_cidade = :id");
                    $stmtUpdate->execute([':nome' => $nome, ':id_estado' => $id_estado, ':id' => $editId]);
                    
                    $message = 'Cidade atualizada com sucesso!';
                    $messageType = 'success';
                    $action = 'list'; // Retorna para a listagem
                }
            } catch (PDOException $e) {
                $message = 'Erro ao atualizar a cidade no servidor.';
                $messageType = 'danger';
            }
        }
    }
}

// ==========================================================================
// Processamento de Ações de URL (GET)
// ==========================================================================
// 1. CARREGAR DADOS PARA EDIÇÃO
if ($action === 'edit' && $editId) {
    try {
        $stmtEdit = $pdo->prepare("SELECT nome, id_estado FROM cidade WHERE id_cidade = :id LIMIT 1");
        $stmtEdit->execute([':id' => $editId]);
        $data = $stmtEdit->fetch();
        if ($data) {
            $editData = $data;
        } else {
            $message = 'Cidade não encontrada para edição.';
            $messageType = 'danger';
            $action = 'list';
        }
    } catch (PDOException $e) {
        $message = 'Erro ao carregar dados da cidade.';
        $messageType = 'danger';
    }
}

// 2. EXECUTAR ELIMINAÇÃO
if ($action === 'delete' && $editId) {
    try {
        // Validação de Integridade Referencial (Impede órfãos na tabela pessoa/clientes/funcionários)
        $stmtFK = $pdo->prepare("SELECT COUNT(*) FROM pessoa WHERE id_cidade = :id");
        $stmtFK->execute([':id' => $editId]);
        
        if ($stmtFK->fetchColumn() > 0) {
            $message = 'Não é possível eliminar esta cidade, pois existem pessoas (clientes ou funcionários) associadas a ela.';
            $messageType = 'danger';
        } else {
            $stmtDel = $pdo->prepare("DELETE FROM cidade WHERE id_cidade = :id");
            $stmtDel->execute([':id' => $editId]);
            $message = 'Cidade eliminada com sucesso!';
            $messageType = 'success';
        }
    } catch (PDOException $e) {
        $message = 'Erro ao tentar eliminar a cidade selecionada.';
        $messageType = 'danger';
    }
    $action = 'list';
}

// ==========================================================================
// Recuperação das Listas de Suporte (Estados e Cidades)
// ==========================================================================
$estadosList = [];
$cidadesList = [];

try {
    // Lista de Estados para alimentar o elemento <select>
    $stmtEst = $pdo->query("SELECT id_estado, nome, sigla FROM estado ORDER BY nome ASC");
    $estadosList = $stmtEst->fetchAll();

    // Lista de Cidades com Junção (INNER JOIN) para exibir os dados completos do estado pai
    $stmtCid = $pdo->query("
        SELECT c.id_cidade, c.nome AS cidade_nome, e.nome AS estado_nome, e.sigla AS estado_sigla 
        FROM cidade c 
        INNER JOIN estado e ON c.id_estado = e.id_estado 
        ORDER BY c.nome ASC
    ");
    $cidadesList = $stmtCid->fetchAll();
} catch (PDOException $e) {
    $message = 'Erro ao carregar os dados de suporte da base de dados.';
    $messageType = 'danger';
}
?>

<!-- Feedback de Mensagens do Sistema -->
        <?php if (!empty($message)): ?>
            <div class="alert alert-<?php echo $messageType; ?>" style="margin-bottom: 24px;">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <div style="display: grid; grid-template-columns: 1fr 340px; gap: 32px; align-items: start;">
            
            <!-- Coluna Esquerda: Listagem de Cidades -->
            <div class="card">
                <h2 class="card-title">Cidades Registadas</h2>
                
                <?php if (empty($cidadesList)): ?>
                    <p style="color: var(--text-muted); font-size: 14px;">Nenhuma cidade registada no sistema.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th style="width: 80px;">ID</th>
                                    <th>Nome da Cidade</th>
                                    <th>Estado</th>
                                    <th style="width: 140px; text-align: right;">Ações</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($cidadesList as $cid): ?>
                                    <tr>
                                        <td><?php echo $cid['id_cidade']; ?></td>
                                        <td><strong><?php echo htmlspecialchars($cid['cidade_nome']); ?></strong></td>
                                        <td>
                                            <span class="badge badge-aberta" style="background-color: var(--info-bg); color: var(--info);">
                                                <?php echo htmlspecialchars($cid['estado_nome']) . ' (' . htmlspecialchars($cid['estado_sigla']) . ')'; ?>
                                            </span>
                                        </td>
                                        <td style="text-align: right;">
                                            <a href="index.php?page=cidades&action=edit&id=<?php echo $cid['id_cidade']; ?>" class="btn btn-secondary" style="padding: 4px 10px; font-size: 12px; margin-right: 4px;">
                                                Editar
                                            </a>
                                            <a href="index.php?page=cidades&action=delete&id=<?php echo $cid['id_cidade']; ?>" class="btn btn-danger" style="padding: 4px 10px; font-size: 12px;" onclick="return confirm('Tem a certeza que deseja eliminar esta cidade?');">
                                                Eliminar
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Coluna Direita: Formulário Dinâmico (Inserção / Edição) -->
            <div class="card">
                <?php if (empty($estadosList)): ?>
                    <div class="alert alert-danger" style="margin: 0; font-size: 13.5px;">
                        <strong>Aviso Importante:</strong><br>
                        É necessário registar pelo menos um <a href="index.php?page=estados" style="color: inherit; text-decoration: underline; font-weight: bold;">Estado</a> antes de cadastrar uma cidade.
                    </div>
                <?php else: ?>
                    
                    <?php if ($action === 'edit' && $editId): ?>
                        <h2 class="card-title" style="color: var(--warning);">Editar Cidade</h2>
                        <form action="index.php?page=cidades&id=<?php echo $editId; ?>" method="POST" autocomplete="off">
                            <input type="hidden" name="form_action" value="update">
                            
                            <div class="form-group">
                                <label for="nome" class="form-label">Nome da Cidade</label>
                                <input type="text" id="nome" name="nome" class="form-control" value="<?php echo htmlspecialchars($editData['nome']); ?>" required>
                            </div>

                            <div class="form-group">
                                <label for="id_estado" class="form-label">Estado Pertencente</label>
                                <select id="id_estado" name="id_estado" class="form-control" required>
                                    <option value="">Selecione um Estado...</option>
                                    <?php foreach ($estadosList as $est): ?>
                                        <option value="<?php echo $est['id_estado']; ?>" <?php echo ($est['id_estado'] == $editData['id_estado']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($est['nome']) . ' (' . htmlspecialchars($est['sigla']) . ')'; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div style="display: flex; gap: 10px; margin-top: 24px;">
                                <button type="submit" class="btn btn-primary" style="flex-grow: 1;">Guardar</button>
                                <a href="index.php?page=cidades" class="btn btn-secondary">Cancelar</a>
                            </div>
                        </form>
                    <?php else: ?>
                        <h2 class="card-title">Nova Cidade</h2>
                        <form action="index.php?page=cidades" method="POST" autocomplete="off">
                            <input type="hidden" name="form_action" value="create">
                            
                            <div class="form-group">
                                <label for="nome" class="form-label">Nome da Cidade</label>
                                <input type="text" id="nome" name="nome" class="form-control" placeholder="Ex: Cascavel" required>
                            </div>

                            <div class="form-group">
                                <label for="id_estado" class="form-label">Estado Pertencente</label>
                                <select id="id_estado" name="id_estado" class="form-control" required>
                                    <option value="">Selecione um Estado...</option>
                                    <?php foreach ($estadosList as $est): ?>
                                        <option value="<?php echo $est['id_estado']; ?>">
                                            <?php echo htmlspecialchars($est['nome']) . ' (' . htmlspecialchars($est['sigla']) . ')'; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <button type="submit" class="btn btn-primary" style="width: 100%; margin-top: 24px;">
                                Registar Cidade
                            </button>
                        </form>
                    <?php endif; ?>

                <?php endif; ?>
