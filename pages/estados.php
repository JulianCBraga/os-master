<?php
/**
 * OS Master - Gestão e Cadastro de Estados
 * * Este ficheiro implementa o CRUD (Criar, Ler, Atualizar, Eliminar)
 * para a tabela 'estado', garantindo a integridade dos dados.
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
$editData = ['nome' => '', 'sigla' => ''];

// ==========================================================================
// Processamento de Ações do Formulário (POST)
// ==========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formAction = $_POST['form_action'] ?? '';

    // 1. AÇÃO: INSERIR NOVO ESTADO
    if ($formAction === 'create') {
        $nome = trim(filter_input(INPUT_POST, 'nome', FILTER_DEFAULT));
        $sigla = strtoupper(trim(filter_input(INPUT_POST, 'sigla', FILTER_DEFAULT)));

        if (empty($nome) || empty($sigla)) {
            $message = 'Todos os campos são de preenchimento obrigatório.';
            $messageType = 'danger';
        } elseif (strlen($sigla) !== 2) {
            $message = 'A sigla deve conter exatamente 2 caracteres.';
            $messageType = 'danger';
        } else {
            try {
                // Validação contra duplicados (Nome ou Sigla)
                $stmtCheck = $pdo->prepare("SELECT COUNT(*) FROM estado WHERE nome = :nome OR sigla = :sigla");
                $stmtCheck->execute([':nome' => $nome, ':sigla' => $sigla]);
                
                if ($stmtCheck->fetchColumn() > 0) {
                    $message = 'Já existe um estado registado com este Nome ou Sigla.';
                    $messageType = 'danger';
                } else {
                    // Inserção segura
                    $stmtInsert = $pdo->prepare("INSERT INTO estado (nome, sigla) VALUES (:nome, :sigla)");
                    $stmtInsert->execute([':nome' => $nome, ':sigla' => $sigla]);
                    
                    $message = 'Estado registado com sucesso!';
                    $messageType = 'success';
                }
            } catch (PDOException $e) {
                $message = 'Erro ao processar o registo no servidor.';
                $messageType = 'danger';
            }
        }
    }

    // 2. AÇÃO: ATUALIZAR ESTADO EXISTENTE
    if ($formAction === 'update' && $editId) {
        $nome = trim(filter_input(INPUT_POST, 'nome', FILTER_DEFAULT));
        $sigla = strtoupper(trim(filter_input(INPUT_POST, 'sigla', FILTER_DEFAULT)));

        if (empty($nome) || empty($sigla)) {
            $message = 'Todos os campos são de preenchimento obrigatório.';
            $messageType = 'danger';
        } elseif (strlen($sigla) !== 2) {
            $message = 'A sigla deve conter exatamente 2 caracteres.';
            $messageType = 'danger';
        } else {
            try {
                // Validação contra duplicados ignorando o próprio registo que está a ser editado
                $stmtCheck = $pdo->prepare("SELECT COUNT(*) FROM estado WHERE (nome = :nome OR sigla = :sigla) AND id_estado != :id");
                $stmtCheck->execute([':nome' => $nome, ':sigla' => $sigla, ':id' => $editId]);
                
                if ($stmtCheck->fetchColumn() > 0) {
                    $message = 'Outro estado já utiliza este Nome ou Sigla.';
                    $messageType = 'danger';
                } else {
                    // Atualização segura
                    $stmtUpdate = $pdo->prepare("UPDATE estado SET nome = :nome, sigla = :sigla WHERE id_estado = :id");
                    $stmtUpdate->execute([':nome' => $nome, ':sigla' => $sigla, ':id' => $editId]);
                    
                    $message = 'Estado atualizado com sucesso!';
                    $messageType = 'success';
                    $action = 'list'; // Retorna para a listagem
                }
            } catch (PDOException $e) {
                $message = 'Erro ao atualizar o estado no servidor.';
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
        $stmtEdit = $pdo->prepare("SELECT nome, sigla FROM estado WHERE id_estado = :id LIMIT 1");
        $stmtEdit->execute([':id' => $editId]);
        $data = $stmtEdit->fetch();
        if ($data) {
            $editData = $data;
        } else {
            $message = 'Estado não encontrado para edição.';
            $messageType = 'danger';
            $action = 'list';
        }
    } catch (PDOException $e) {
        $message = 'Erro ao carregar dados do estado.';
        $messageType = 'danger';
    }
}

// 2. EXECUTAR ELIMINAÇÃO
if ($action === 'delete' && $editId) {
    try {
        // Validação de Integridade Referencial (Impede órfãos na tabela cidade)
        $stmtFK = $pdo->prepare("SELECT COUNT(*) FROM cidade WHERE id_estado = :id");
        $stmtFK->execute([':id' => $editId]);
        
        if ($stmtFK->fetchColumn() > 0) {
            $message = 'Não é possível eliminar este estado, pois existem cidades associadas a ele.';
            $messageType = 'danger';
        } else {
            $stmtDel = $pdo->prepare("DELETE FROM estado WHERE id_estado = :id");
            $stmtDel->execute([':id' => $editId]);
            $message = 'Estado eliminado com sucesso!';
            $messageType = 'success';
        }
    } catch (PDOException $e) {
        $message = 'Erro ao tentar eliminar o estado selecionado.';
        $messageType = 'danger';
    }
    $action = 'list';
}

// ==========================================================================
// Recuperação da Lista Geral de Estados
// ==========================================================================
$estadosList = [];
try {
    $stmtList = $pdo->query("SELECT id_estado, nome, sigla FROM estado ORDER BY nome ASC");
    $estadosList = $stmtList->fetchAll();
} catch (PDOException $e) {
    $message = 'Erro ao carregar a listagem de estados.';
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
            
            <!-- Coluna Esquerda: Listagem de Estados -->
            <div class="card">
                <h2 class="card-title">Estados Registados</h2>
                
                <?php if (empty($estadosList)): ?>
                    <p style="color: var(--text-muted); font-size: 14px;">Nenhum estado registado no sistema.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th style="width: 80px;">ID</th>
                                    <th>Nome do Estado</th>
                                    <th style="width: 100px;">Sigla</th>
                                    <th style="width: 140px; text-align: right;">Ações</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($estadosList as $est): ?>
                                    <tr>
                                        <td><?php echo $est['id_estado']; ?></td>
                                        <td><strong><?php echo htmlspecialchars($est['nome']); ?></strong></td>
                                        <td><span class="badge badge-aberta"><?php echo htmlspecialchars($est['sigla']); ?></span></td>
                                        <td style="text-align: right;">
                                            <a href="index.php?page=estados&action=edit&id=<?php echo $est['id_estado']; ?>" class="btn btn-secondary" style="padding: 4px 10px; font-size: 12px; margin-right: 4px;">
                                                Editar
                                            </a>
                                            <a href="index.php?page=estados&action=delete&id=<?php echo $est['id_estado']; ?>" class="btn btn-danger" style="padding: 4px 10px; font-size: 12px;" onclick="return confirm('Tem a certeza que deseja eliminar este estado?');">
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
                <?php if ($action === 'edit' && $editId): ?>
                    <h2 class="card-title" style="color: var(--warning);">Editar Estado</h2>
                    <form action="index.php?page=estados&id=<?php echo $editId; ?>" method="POST" autocomplete="off">
                        <input type="hidden" name="form_action" value="update">
                        
                        <div class="form-group">
                            <label for="nome" class="form-label">Nome do Estado</label>
                            <input type="text" id="nome" name="nome" class="form-control" value="<?php echo htmlspecialchars($editData['nome']); ?>" required>
                        </div>

                        <div class="form-group">
                            <label for="sigla" class="form-label">Sigla (2 letras)</label>
                            <input type="text" id="sigla" name="sigla" class="form-control" value="<?php echo htmlspecialchars($editData['sigla']); ?>" maxlength="2" required style="text-transform: uppercase;">
                        </div>

                        <div style="display: flex; gap: 10px; margin-top: 24px;">
                            <button type="submit" class="btn btn-primary" style="flex-grow: 1;">Guardar</button>
                            <a href="index.php?page=estados" class="btn btn-secondary">Cancelar</a>
                        </div>
                    </form>
                <?php else: ?>
                    <h2 class="card-title">Novo Estado</h2>
                    <form action="index.php?page=estados" method="POST" autocomplete="off">
                        <input type="hidden" name="form_action" value="create">
                        
                        <div class="form-group">
                            <label for="nome" class="form-label">Nome do Estado</label>
                            <input type="text" id="nome" name="nome" class="form-control" placeholder="Ex: São Paulo" required>
                        </div>

                        <div class="form-group">
                            <label for="sigla" class="form-label">Sigla (2 letras)</label>
                            <input type="text" id="sigla" name="sigla" class="form-control" placeholder="Ex: SP" maxlength="2" required style="text-transform: uppercase;">
                        </div>

                        <button type="submit" class="btn btn-primary" style="width: 100%; margin-top: 24px;">
                            Registar Estado
                        </button>
                    </form>
                <?php endif; ?>
