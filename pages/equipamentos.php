<?php
/**
 * OS Master - Gestão e Cadastro de Equipamentos de Clientes
 * * Este arquivo implementa o CRUD completo para a tabela 'equipamento',
 * relacionando cada aparelho ao seu respectivo cliente (proprietário), com
 * validações de integridade contra exclusão de itens vinculados a OS.
 * * @package OSMaster
 * @author Julian C. Braga
 * @version 1.0
 */

// Impede o acesso direto a este arquivo fora do index.php
if (!defined('BASE_PATH')) {
    header("Location: ../index.php");
    exit;
}

// Verificação de segurança: apenas usuários autenticados podem acessar
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php?page=login");
    exit;
}

$message = '';
$messageType = ''; // 'success' ou 'danger'

// Captura parâmetros de ação para edição ou exclusão
$action = $_GET['action'] ?? 'list';
$editId = $_GET['id'] ?? null;
$returnTo = $_GET['return'] ?? '';
$returnClienteId = filter_input(INPUT_GET, 'id_cliente', FILTER_VALIDATE_INT);
$isReturnToOS = ($returnTo === 'os');
$searchTerm = trim(filter_input(INPUT_GET, 'q', FILTER_DEFAULT) ?? '');
$returnQuery = $isReturnToOS ? '&return=os' . ($returnClienteId ? '&id_cliente=' . $returnClienteId : '') : '';
$equipamentoProprietarios = [];
$equipamentoOsRecentes = [];
$equipamentoAnexos = [];

// Instância de dados padrão para o formulário
$editData = [
    'aparelho'     => '',
    'marca'        => '',
    'modelo'       => '',
    'numero_serie' => '',
    'cor'          => '',
    'patrimonio'   => '',
    'observacoes'  => '',
    'id_cliente'   => ''
];

if ($action === 'create' && $returnClienteId) {
    $editData['id_cliente'] = $returnClienteId;
}

// ==========================================================================
// Processamento de Ações do Formulário (POST)
// ==========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formAction = $_POST['form_action'] ?? '';

    // Coleta e higienização das entradas
    $aparelho     = trim(filter_input(INPUT_POST, 'aparelho', FILTER_DEFAULT));
    $marca        = trim(filter_input(INPUT_POST, 'marca', FILTER_DEFAULT));
    $modelo       = trim(filter_input(INPUT_POST, 'modelo', FILTER_DEFAULT));
    $numero_serie = trim(filter_input(INPUT_POST, 'numero_serie', FILTER_DEFAULT));
    $cor          = trim(filter_input(INPUT_POST, 'cor', FILTER_DEFAULT));
    $patrimonio   = trim(filter_input(INPUT_POST, 'patrimonio', FILTER_DEFAULT));
    $observacoes  = trim(filter_input(INPUT_POST, 'observacoes', FILTER_DEFAULT));
    $id_cliente   = filter_input(INPUT_POST, 'id_cliente', FILTER_VALIDATE_INT);

    // 1. AÇÃO: CADASTRAR NOVO EQUIPAMENTO
    if ($formAction === 'create') {
        if (empty($aparelho) || !$id_cliente) {
            $message = 'O nome do aparelho e o cliente proprietário são campos de preenchimento obrigatório.';
            $messageType = 'danger';
        } else {
            try {
                $pdo->beginTransaction();

                // Inserção segura dos dados do equipamento
                $sqlInsert = "INSERT INTO equipamento (
                                aparelho, marca, modelo, numero_serie, cor, patrimonio, observacoes, id_cliente
                              ) VALUES (
                                :aparelho, :marca, :modelo, :numero_serie, :cor, :patrimonio, :observacoes, :id_cliente
                              )";
                $stmtInsert = $pdo->prepare($sqlInsert);
                $stmtInsert->execute([
                    ':aparelho'     => $aparelho,
                    ':marca'        => $marca ?: null,
                    ':modelo'       => $modelo ?: null,
                    ':numero_serie' => $numero_serie ?: null,
                    ':cor'          => $cor ?: null,
                    ':patrimonio'   => $patrimonio ?: null,
                    ':observacoes'  => $observacoes ?: null,
                    ':id_cliente'   => $id_cliente
                ]);
                $idEquipamento = (int)$pdo->lastInsertId();

                $stmtProp = $pdo->prepare("
                    INSERT INTO equipamento_proprietario (id_equipamento, id_cliente, data_inicio, observacao)
                    VALUES (:id_equipamento, :id_cliente, NOW(), 'Cadastro inicial do equipamento')
                ");
                $stmtProp->execute([
                    ':id_equipamento' => $idEquipamento,
                    ':id_cliente' => $id_cliente
                ]);

                $pdo->commit();
                if ($isReturnToOS) {
                    header("Location: index.php?page=os&action=create&id_cliente={$id_cliente}&id_equipamento={$idEquipamento}");
                    exit;
                }
                $message = 'Equipamento cadastrado com sucesso e vinculado ao cliente!';
                $messageType = 'success';
                $action = 'list';
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $message = 'Erro ao cadastrar o equipamento no servidor.';
                $messageType = 'danger';
            }
        }
    }

    // 2. AÇÃO: ATUALIZAR EQUIPAMENTO EXISTENTE
    if ($formAction === 'update' && $editId) {
        if (empty($aparelho) || !$id_cliente) {
            $message = 'O nome do aparelho e o cliente proprietário são campos de preenchimento obrigatório.';
            $messageType = 'danger';
        } else {
            try {
                $stmtAtual = $pdo->prepare("SELECT id_cliente FROM equipamento WHERE id_equipamento = :id LIMIT 1");
                $stmtAtual->execute([':id' => $editId]);
                $equipamentoAtual = $stmtAtual->fetch();

                if (!$equipamentoAtual) {
                    throw new RuntimeException('Equipamento não encontrado.');
                }

                $pdo->beginTransaction();

                $sqlUpdate = "UPDATE equipamento SET 
                                aparelho = :aparelho, 
                                marca = :marca, 
                                modelo = :modelo, 
                                numero_serie = :numero_serie, 
                                cor = :cor,
                                patrimonio = :patrimonio,
                                observacoes = :observacoes,
                                id_cliente = :id_cliente 
                              WHERE id_equipamento = :id";
                $stmtUpdate = $pdo->prepare($sqlUpdate);
                $stmtUpdate->execute([
                    ':aparelho'     => $aparelho,
                    ':marca'        => $marca ?: null,
                    ':modelo'       => $modelo ?: null,
                    ':numero_serie' => $numero_serie ?: null,
                    ':cor'          => $cor ?: null,
                    ':patrimonio'   => $patrimonio ?: null,
                    ':observacoes'  => $observacoes ?: null,
                    ':id_cliente'   => $id_cliente,
                    ':id'           => $editId
                ]);

                if ((int)$equipamentoAtual['id_cliente'] !== (int)$id_cliente) {
                    $stmtCloseProp = $pdo->prepare("
                        UPDATE equipamento_proprietario
                        SET data_fim = NOW(), observacao = COALESCE(observacao, 'Transferência de proprietário')
                        WHERE id_equipamento = :id_equipamento AND data_fim IS NULL
                    ");
                    $stmtCloseProp->execute([':id_equipamento' => $editId]);

                    $stmtNewProp = $pdo->prepare("
                        INSERT INTO equipamento_proprietario (id_equipamento, id_cliente, data_inicio, observacao)
                        VALUES (:id_equipamento, :id_cliente, NOW(), 'Novo proprietário definido no cadastro')
                    ");
                    $stmtNewProp->execute([
                        ':id_equipamento' => $editId,
                        ':id_cliente' => $id_cliente
                    ]);
                }

                $pdo->commit();
                if ($isReturnToOS) {
                    header("Location: index.php?page=os&action=create&id_cliente={$id_cliente}&id_equipamento={$editId}");
                    exit;
                }
                $message = 'Dados do equipamento atualizados com sucesso!';
                $messageType = 'success';
                $action = 'list';
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $message = 'Erro ao atualizar o equipamento no servidor.';
                $messageType = 'danger';
            }
        }
    }

    // 3. AÇÃO: ANEXAR FOTO AO EQUIPAMENTO
    if ($formAction === 'upload_anexo' && $editId) {
        $action = 'edit';
        $descricaoAnexo = trim(filter_input(INPUT_POST, 'descricao_anexo', FILTER_DEFAULT) ?? '');

        try {
            if (!isset($_FILES['foto_anexo']) || $_FILES['foto_anexo']['error'] !== UPLOAD_ERR_OK) {
                throw new RuntimeException('Selecione uma foto válida para anexar ao equipamento.');
            }

            $fileTmpPath = $_FILES['foto_anexo']['tmp_name'];
            $fileName = $_FILES['foto_anexo']['name'];
            $fileSize = (int)$_FILES['foto_anexo']['size'];
            $fileMime = $_FILES['foto_anexo']['type'] ?? null;
            $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
            $allowedExtensions = ['jpg', 'jpeg', 'png', 'webp', 'gif'];

            if (!in_array($fileExtension, $allowedExtensions, true)) {
                throw new RuntimeException('Formato não suportado. Use JPG, PNG, WEBP ou GIF.');
            }

            if ($fileSize > 5 * 1024 * 1024) {
                throw new RuntimeException('A foto excede o limite de 5MB.');
            }

            $uploadFileDir = BASE_PATH . '/uploads/equipamentos/';
            if (!is_dir($uploadFileDir)) {
                mkdir($uploadFileDir, 0755, true);
            }

            $newFileName = 'equip_' . (int)$editId . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $fileExtension;
            $destPath = $uploadFileDir . $newFileName;

            if (!move_uploaded_file($fileTmpPath, $destPath)) {
                throw new RuntimeException('Não foi possível gravar a foto enviada.');
            }

            $stmtAnexo = $pdo->prepare("
                INSERT INTO equipamento_anexo (id_equipamento, caminho, nome_original, tipo_mime, tamanho, descricao)
                VALUES (:id_equipamento, :caminho, :nome_original, :tipo_mime, :tamanho, :descricao)
            ");
            $stmtAnexo->execute([
                ':id_equipamento' => $editId,
                ':caminho' => 'uploads/equipamentos/' . $newFileName,
                ':nome_original' => $fileName,
                ':tipo_mime' => $fileMime,
                ':tamanho' => $fileSize,
                ':descricao' => $descricaoAnexo ?: null
            ]);

            $message = 'Foto anexada ao equipamento com sucesso!';
            $messageType = 'success';
        } catch (Exception $e) {
            $message = $e->getMessage();
            $messageType = 'danger';
        }
    }
}

// ==========================================================================
// Processamento de Ações de URL (GET)
// ==========================================================================
if ($action === 'delete_anexo' && $editId) {
    $idAnexo = filter_input(INPUT_GET, 'id_anexo', FILTER_VALIDATE_INT);
    try {
        if (!$idAnexo) {
            throw new RuntimeException('Anexo inválido.');
        }

        $stmtAnexo = $pdo->prepare("SELECT caminho FROM equipamento_anexo WHERE id_anexo = :id_anexo AND id_equipamento = :id_equipamento LIMIT 1");
        $stmtAnexo->execute([
            ':id_anexo' => $idAnexo,
            ':id_equipamento' => $editId
        ]);
        $anexo = $stmtAnexo->fetch();

        if (!$anexo) {
            throw new RuntimeException('Anexo não encontrado.');
        }

        $stmtDeleteAnexo = $pdo->prepare("DELETE FROM equipamento_anexo WHERE id_anexo = :id_anexo AND id_equipamento = :id_equipamento");
        $stmtDeleteAnexo->execute([
            ':id_anexo' => $idAnexo,
            ':id_equipamento' => $editId
        ]);

        if (!empty($anexo['caminho']) && file_exists(BASE_PATH . '/' . $anexo['caminho'])) {
            @unlink(BASE_PATH . '/' . $anexo['caminho']);
        }

        $message = 'Foto removida do equipamento.';
        $messageType = 'success';
    } catch (Exception $e) {
        $message = $e->getMessage();
        $messageType = 'danger';
    }
    $action = 'edit';
}

// 1. CARREGAR DADOS PARA EDIÇÃO
if (($action === 'edit' || $action === 'view') && $editId) {
    try {
        $stmtEdit = $pdo->prepare("
            SELECT e.*, p.nome AS cliente_nome, p.cpf_cnpj, p.telefone, p.status AS cliente_status
            FROM equipamento e
            INNER JOIN pessoa p ON e.id_cliente = p.id_pessoa
            WHERE e.id_equipamento = :id
            LIMIT 1
        ");
        $stmtEdit->execute([':id' => $editId]);
        $data = $stmtEdit->fetch();
        
        if ($data) {
            $editData = $data;
            $stmtAnexos = $pdo->prepare("
                SELECT *
                FROM equipamento_anexo
                WHERE id_equipamento = :id
                ORDER BY created_at DESC, id_anexo DESC
            ");
            $stmtAnexos->execute([':id' => $editId]);
            $equipamentoAnexos = $stmtAnexos->fetchAll();

            if ($action === 'view') {
                $stmtProprietarios = $pdo->prepare("
                    SELECT ep.*, p.nome AS cliente_nome, p.cpf_cnpj
                    FROM equipamento_proprietario ep
                    INNER JOIN pessoa p ON ep.id_cliente = p.id_pessoa
                    WHERE ep.id_equipamento = :id
                    ORDER BY ep.data_inicio DESC
                ");
                $stmtProprietarios->execute([':id' => $editId]);
                $equipamentoProprietarios = $stmtProprietarios->fetchAll();

                $stmtOsRecentes = $pdo->prepare("
                    SELECT o.id_os, o.data_abertura, o.status, o.valor_total, p.nome AS cliente_nome
                    FROM os o
                    INNER JOIN pessoa p ON o.id_cliente = p.id_pessoa
                    WHERE o.id_equipamento = :id
                    ORDER BY o.data_abertura DESC
                    LIMIT 8
                ");
                $stmtOsRecentes->execute([':id' => $editId]);
                $equipamentoOsRecentes = $stmtOsRecentes->fetchAll();
            }
        } else {
            $message = 'Equipamento não encontrado.';
            $messageType = 'danger';
            $action = 'list';
        }
    } catch (PDOException $e) {
        $message = 'Erro ao obter dados do equipamento para edição.';
        $messageType = 'danger';
    }
}

// 2. EXECUTAR REMOÇÃO DE EQUIPAMENTO (Com verificação de integridade)
if ($action === 'delete' && $editId) {
    try {
        // Validação de integridade referencial: impede exclusão se o aparelho estiver vinculado a alguma OS
        $stmtCheckOS = $pdo->prepare("SELECT COUNT(*) FROM os WHERE id_equipamento = :id");
        $stmtCheckOS->execute([':id' => $editId]);
        
        if ($stmtCheckOS->fetchColumn() > 0) {
            $message = 'Não é possível remover este equipamento pois ele encontra-se associado ao histórico de Ordens de Serviço.';
            $messageType = 'danger';
        } else {
            $stmtDel = $pdo->prepare("DELETE FROM equipamento WHERE id_equipamento = :id");
            $stmtDel->execute([':id' => $editId]);
            $message = 'Equipamento removido com sucesso do sistema!';
            $messageType = 'success';
        }
    } catch (PDOException $e) {
        $message = 'Erro ao tentar remover o equipamento selecionado.';
        $messageType = 'danger';
    }
    $action = 'list';
}

// ==========================================================================
// Recuperação das Listas de Suporte
// ==========================================================================
$clientesList = [];
$equipamentosList = [];

try {
    // 1. Lista apenas Clientes Ativos para alimentar o dropdown de proprietários
    $sqlClientes = "
        SELECT p.id_pessoa, p.nome, p.cpf_cnpj 
        FROM cliente c 
        INNER JOIN pessoa p ON c.id_pessoa = p.id_pessoa 
        WHERE p.status = 1 AND c.status = 1
        ORDER BY p.nome ASC
    ";
    $clientesList = $pdo->query($sqlClientes)->fetchAll();

    // 2. Lista Geral de Equipamentos com junção na tabela Pessoa para exibir o nome do dono
    $wherePartsEquipamentos = [];
    $paramsEquipamentos = [];
    if ($isReturnToOS && $returnClienteId) {
        $wherePartsEquipamentos[] = 'e.id_cliente = :return_cliente_id';
        $paramsEquipamentos[':return_cliente_id'] = $returnClienteId;
    }
    if ($searchTerm !== '') {
        $wherePartsEquipamentos[] = "(
            e.id_equipamento = :search_id
            OR e.aparelho LIKE :search_aparelho
            OR e.marca LIKE :search_marca
            OR e.modelo LIKE :search_modelo
            OR e.numero_serie LIKE :search_serie
            OR e.cor LIKE :search_cor
            OR e.patrimonio LIKE :search_patrimonio
            OR p.nome LIKE :search_cliente
            OR p.cpf_cnpj LIKE :search_doc
            OR p.cpf_cnpj_limpo LIKE :search_digits
        )";
        $paramsEquipamentos[':search_id'] = ctype_digit($searchTerm) ? (int)$searchTerm : 0;
        $paramsEquipamentos[':search_aparelho'] = '%' . $searchTerm . '%';
        $paramsEquipamentos[':search_marca'] = '%' . $searchTerm . '%';
        $paramsEquipamentos[':search_modelo'] = '%' . $searchTerm . '%';
        $paramsEquipamentos[':search_serie'] = '%' . $searchTerm . '%';
        $paramsEquipamentos[':search_cor'] = '%' . $searchTerm . '%';
        $paramsEquipamentos[':search_patrimonio'] = '%' . $searchTerm . '%';
        $paramsEquipamentos[':search_cliente'] = '%' . $searchTerm . '%';
        $paramsEquipamentos[':search_doc'] = '%' . $searchTerm . '%';
        $digitsTerm = preg_replace('/\D/', '', $searchTerm);
        $paramsEquipamentos[':search_digits'] = $digitsTerm !== '' ? '%' . $digitsTerm . '%' : '__NO_DIGITS__';
    }
    $whereEquipamentos = $wherePartsEquipamentos ? 'WHERE ' . implode(' AND ', $wherePartsEquipamentos) : '';

    $sqlEquipamentos = "
        SELECT e.*, p.nome AS cliente_nome, p.cpf_cnpj, c.status AS cliente_status,
               (
                   SELECT COUNT(*)
                   FROM equipamento_proprietario ep
                   WHERE ep.id_equipamento = e.id_equipamento
               ) AS total_proprietarios
        FROM equipamento e 
        INNER JOIN pessoa p ON e.id_cliente = p.id_pessoa
        INNER JOIN cliente c ON e.id_cliente = c.id_pessoa
        {$whereEquipamentos}
        ORDER BY e.aparelho ASC
    ";
    $stmtEquipamentos = $pdo->prepare($sqlEquipamentos);
    $stmtEquipamentos->execute($paramsEquipamentos);
    $equipamentosList = $stmtEquipamentos->fetchAll();
} catch (PDOException $e) {
    $message = 'Erro ao processar as listagens de suporte a partir da base de dados.';
    $messageType = 'danger';
}
?>

<!-- Feedback de Mensagens do Sistema -->
        <?php if (!empty($message)): ?>
            <div class="alert alert-<?php echo $messageType; ?>" style="margin-bottom: 24px;">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <!-- Container para Notificações Customizadas (Sem usar alert() nativo) -->
        <div id="custom-alert-container" style="position: fixed; top: 20px; right: 20px; z-index: 9999; display: flex; flex-direction: column; gap: 10px;"></div>

        <!-- Modal de Confirmação Customizado (Sem usar confirm() nativo) -->
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

        <?php if ($action === 'view' && $editId): ?>
            <div class="card printable-card">
                <div class="module-header no-print">
                    <div>
                        <h2 class="module-title">Ficha do Equipamento #<?php echo htmlspecialchars($editId); ?></h2>
                        <p class="module-subtitle">Visualização cadastral para consulta e impressão.</p>
                    </div>
                    <div class="action-group">
                        <button type="button" class="btn btn-primary" onclick="window.print()">Imprimir Ficha</button>
                        <?php if ($isReturnToOS): ?>
                            <a href="index.php?page=os&action=create&id_cliente=<?php echo urlencode((string)$editData['id_cliente']); ?>&id_equipamento=<?php echo urlencode((string)$editId); ?>" class="btn btn-primary">Usar na OS</a>
                        <?php endif; ?>
                        <a href="index.php?page=equipamentos&action=edit&id=<?php echo $editId; ?><?php echo $returnQuery; ?>" class="btn btn-secondary">Editar</a>
                        <a href="<?php echo $isReturnToOS ? 'index.php?page=equipamentos&return=os&action=create&id_cliente=' . urlencode((string)$editData['id_cliente']) : 'index.php?page=equipamentos'; ?>" class="btn btn-secondary">Voltar</a>
                    </div>
                </div>

                <div class="print-header">
                    <h1>Ficha do Equipamento</h1>
                    <p>Cadastro #<?php echo htmlspecialchars($editId); ?> - <?php echo date('d/m/Y H:i'); ?></p>
                </div>

                <div class="form-section" style="margin-top: 0;">
                    <h3 class="form-section-title">Identificação</h3>
                    <div class="form-grid form-grid-3">
                        <div>
                            <div class="table-muted-text">Aparelho / Item</div>
                            <div class="table-primary-text"><?php echo htmlspecialchars($editData['aparelho']); ?></div>
                        </div>
                        <div>
                            <div class="table-muted-text">Marca</div>
                            <div class="table-primary-text"><?php echo htmlspecialchars($editData['marca'] ?: 'Não informada'); ?></div>
                        </div>
                        <div>
                            <div class="table-muted-text">Modelo</div>
                            <div class="table-primary-text"><?php echo htmlspecialchars($editData['modelo'] ?: 'Não informado'); ?></div>
                        </div>
                        <div>
                            <div class="table-muted-text">Número de Série</div>
                            <div class="table-primary-text"><?php echo htmlspecialchars($editData['numero_serie'] ?: 'Não informado'); ?></div>
                        </div>
                        <div>
                            <div class="table-muted-text">Cor / Identificação Visual</div>
                            <div class="table-primary-text"><?php echo htmlspecialchars($editData['cor'] ?: 'Não informada'); ?></div>
                        </div>
                        <div>
                            <div class="table-muted-text">Patrimônio / Etiqueta</div>
                            <div class="table-primary-text"><?php echo htmlspecialchars($editData['patrimonio'] ?: 'Não informado'); ?></div>
                        </div>
                    </div>
                </div>

                <div class="form-section">
                    <h3 class="form-section-title">Proprietário Atual</h3>
                    <div class="form-grid form-grid-3">
                        <div>
                            <div class="table-muted-text">Cliente</div>
                            <div class="table-primary-text"><?php echo htmlspecialchars($editData['cliente_nome'] ?? 'Não informado'); ?></div>
                        </div>
                        <div>
                            <div class="table-muted-text">CPF / CNPJ</div>
                            <div class="table-primary-text"><?php echo htmlspecialchars($editData['cpf_cnpj'] ?: 'Não informado'); ?></div>
                        </div>
                        <div>
                            <div class="table-muted-text">Telefone</div>
                            <div class="table-primary-text"><?php echo htmlspecialchars($editData['telefone'] ?: 'Não informado'); ?></div>
                        </div>
                    </div>
                </div>

                <div class="form-section">
                    <h3 class="form-section-title">Histórico de Proprietários</h3>
                    <?php if (empty($equipamentoProprietarios)): ?>
                        <p style="color: var(--text-muted); font-size: 14px; margin: 0;">Nenhum histórico de propriedade registrado para este equipamento.</p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Cliente</th>
                                        <th>Documento</th>
                                        <th>Início</th>
                                        <th>Fim</th>
                                        <th>Observação</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($equipamentoProprietarios as $prop): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($prop['cliente_nome']); ?></td>
                                            <td><?php echo htmlspecialchars($prop['cpf_cnpj'] ?: 'Não informado'); ?></td>
                                            <td><?php echo !empty($prop['data_inicio']) ? date('d/m/Y H:i', strtotime($prop['data_inicio'])) : 'Não informado'; ?></td>
                                            <td><?php echo !empty($prop['data_fim']) ? date('d/m/Y H:i', strtotime($prop['data_fim'])) : 'Atual'; ?></td>
                                            <td><?php echo htmlspecialchars($prop['observacao'] ?: 'Sem observação'); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="form-section">
                    <h3 class="form-section-title">Fotos e Anexos</h3>
                    <?php if (empty($equipamentoAnexos)): ?>
                        <p style="color: var(--text-muted); font-size: 14px; margin: 0;">Nenhuma foto anexada a este equipamento.</p>
                    <?php else: ?>
                        <div class="attachment-grid">
                            <?php foreach ($equipamentoAnexos as $anexo): ?>
                                <a href="<?php echo htmlspecialchars($anexo['caminho']); ?>" target="_blank" class="attachment-card">
                                    <img src="<?php echo htmlspecialchars($anexo['caminho']); ?>" alt="<?php echo htmlspecialchars($anexo['descricao'] ?: 'Foto do equipamento'); ?>">
                                    <div>
                                        <div class="table-primary-text"><?php echo htmlspecialchars($anexo['descricao'] ?: 'Foto do equipamento'); ?></div>
                                        <div class="table-muted-text"><?php echo !empty($anexo['created_at']) ? date('d/m/Y H:i', strtotime($anexo['created_at'])) : 'Data não informada'; ?></div>
                                    </div>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="form-section">
                    <h3 class="form-section-title">OS Recentes</h3>
                    <?php if (empty($equipamentoOsRecentes)): ?>
                        <p style="color: var(--text-muted); font-size: 14px; margin: 0;">Nenhuma OS registrada para este equipamento.</p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>OS</th>
                                        <th>Data</th>
                                        <th>Cliente</th>
                                        <th>Status</th>
                                        <th>Valor</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($equipamentoOsRecentes as $osRecente): ?>
                                        <tr>
                                            <td><code>#<?php echo htmlspecialchars($osRecente['id_os']); ?></code></td>
                                            <td><?php echo !empty($osRecente['data_abertura']) ? date('d/m/Y H:i', strtotime($osRecente['data_abertura'])) : 'Não informada'; ?></td>
                                            <td><?php echo htmlspecialchars($osRecente['cliente_nome']); ?></td>
                                            <td><?php echo htmlspecialchars($osRecente['status']); ?></td>
                                            <td>R$ <?php echo number_format((float)$osRecente['valor_total'], 2, ',', '.'); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="form-section">
                    <h3 class="form-section-title">Observações Fixas</h3>
                    <p style="color: var(--text-main); line-height: 1.6; margin: 0;"><?php echo nl2br(htmlspecialchars($editData['observacoes'] ?: 'Nenhuma observação cadastrada.')); ?></p>
                </div>
            </div>
        <?php else: ?>
        <div style="display: grid; grid-template-columns: 1fr; gap: 32px;">
            <?php if (!in_array($action, ['create', 'edit'], true)): ?>
            
            <!-- Listagem de Equipamentos Cadastrados -->
            <div class="card">
                <div class="module-header">
                    <div>
                        <h2 class="module-title">Equipamentos Cadastrados</h2>
                        <p class="module-subtitle">
                            <?php echo count($equipamentosList); ?> equipamento(s)
                            <?php echo ($isReturnToOS && $returnClienteId) ? 'do cliente selecionado para a OS.' : 'vinculados a clientes.'; ?>
                        </p>
                    </div>
                    <div class="action-group">
                        <?php if ($isReturnToOS): ?>
                            <a href="index.php?page=os&action=create<?php echo $returnClienteId ? '&id_cliente=' . $returnClienteId : ''; ?>" class="btn btn-secondary">Voltar para OS</a>
                        <?php endif; ?>
                        <a href="index.php?page=equipamentos&action=create<?php echo $returnQuery; ?>" class="btn btn-primary">Cadastrar Novo Equipamento</a>
                    </div>
                </div>
                
                <?php if (empty($equipamentosList)): ?>
                    <form method="GET" action="index.php" class="form-section" style="margin-top: 0; margin-bottom: 18px;">
                        <input type="hidden" name="page" value="equipamentos">
                        <?php if ($isReturnToOS): ?>
                            <input type="hidden" name="return" value="os">
                            <input type="hidden" name="action" value="create">
                            <?php if ($returnClienteId): ?>
                                <input type="hidden" name="id_cliente" value="<?php echo $returnClienteId; ?>">
                            <?php endif; ?>
                        <?php endif; ?>
                        <div class="form-grid form-grid-2">
                            <div class="form-group" style="margin-bottom: 0;">
                                <label for="q" class="form-label">Procurar Equipamento</label>
                                <input type="text" id="q" name="q" class="form-control" value="<?php echo htmlspecialchars($searchTerm); ?>" placeholder="Aparelho, marca, modelo, série, patrimônio, cliente ou código">
                            </div>
                            <div style="display: flex; gap: 10px; align-items: flex-end;">
                                <button type="submit" class="btn btn-primary">Procurar</button>
                                <a href="index.php?page=equipamentos<?php echo $isReturnToOS ? '&return=os&action=create' . ($returnClienteId ? '&id_cliente=' . $returnClienteId : '') : ''; ?>" class="btn btn-secondary">Limpar</a>
                            </div>
                        </div>
                    </form>
                    <p style="color: var(--text-muted); font-size: 14px;">
                        <?php echo $searchTerm !== '' ? 'Nenhum equipamento encontrado para a busca informada.' : 'Nenhum equipamento cadastrado no sistema.'; ?>
                    </p>
                <?php else: ?>
                    <form method="GET" action="index.php" class="form-section" style="margin-top: 0; margin-bottom: 18px;">
                        <input type="hidden" name="page" value="equipamentos">
                        <?php if ($isReturnToOS): ?>
                            <input type="hidden" name="return" value="os">
                            <input type="hidden" name="action" value="create">
                            <?php if ($returnClienteId): ?>
                                <input type="hidden" name="id_cliente" value="<?php echo $returnClienteId; ?>">
                            <?php endif; ?>
                        <?php endif; ?>
                        <div class="form-grid form-grid-2">
                            <div class="form-group" style="margin-bottom: 0;">
                                <label for="q" class="form-label">Procurar Equipamento</label>
                                <input type="text" id="q" name="q" class="form-control" value="<?php echo htmlspecialchars($searchTerm); ?>" placeholder="Aparelho, marca, modelo, série, patrimônio, cliente ou código">
                            </div>
                            <div style="display: flex; gap: 10px; align-items: flex-end;">
                                <button type="submit" class="btn btn-primary">Procurar</button>
                                <a href="index.php?page=equipamentos<?php echo $isReturnToOS ? '&return=os&action=create' . ($returnClienteId ? '&id_cliente=' . $returnClienteId : '') : ''; ?>" class="btn btn-secondary">Limpar</a>
                            </div>
                        </div>
                    </form>
                    <div class="table-responsive">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th style="width: 70px;">ID</th>
                                    <th>Aparelho / Item</th>
                                    <th>Marca / Modelo</th>
                                    <th>Nº de Série</th>
                                    <th>Identificação</th>
                                    <th>Cliente</th>
                                    <th style="width: 240px; text-align: right;">Ações</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($equipamentosList as $eq): ?>
                                    <tr style="<?php echo ($eq['cliente_status'] == 0) ? 'opacity: 0.6;' : ''; ?>">
                                        <td><code>#<?php echo $eq['id_equipamento']; ?></code></td>
                                        <td>
                                            <div class="table-primary-text"><?php echo htmlspecialchars($eq['aparelho']); ?></div>
                                            <div class="table-muted-text"><?php echo htmlspecialchars($eq['observacoes'] ?: 'Sem observações fixas'); ?></div>
                                        </td>
                                        <td>
                                            <div class="table-primary-text"><?php echo htmlspecialchars($eq['marca'] ?: 'Marca não informada'); ?></div>
                                            <div class="table-muted-text"><?php echo htmlspecialchars($eq['modelo'] ?: 'Modelo não informado'); ?></div>
                                        </td>
                                        <td><code><?php echo htmlspecialchars($eq['numero_serie'] !== '' ? $eq['numero_serie'] : 'Não informado'); ?></code></td>
                                        <td>
                                            <div class="table-primary-text"><?php echo htmlspecialchars($eq['cor'] ?: 'Cor não informada'); ?></div>
                                            <div class="table-muted-text"><?php echo htmlspecialchars($eq['patrimonio'] ?: 'Sem patrimônio'); ?></div>
                                        </td>
                                        <td>
                                            <span class="badge" style="background-color: var(--info-bg); color: var(--info);">
                                                <?php echo htmlspecialchars($eq['cliente_nome']); ?>
                                            </span>
                                            <div class="table-muted-text"><?php echo htmlspecialchars($eq['cpf_cnpj'] ?: 'Documento não informado'); ?></div>
                                            <div class="table-muted-text"><?php echo (int)$eq['total_proprietarios']; ?> registro(s) de propriedade</div>
                                        </td>
                                        <td style="text-align: right;">
                                            <div class="action-group">
                                            <?php if ($isReturnToOS): ?>
                                                <a href="index.php?page=os&action=create&id_cliente=<?php echo $eq['id_cliente']; ?>&id_equipamento=<?php echo $eq['id_equipamento']; ?>" class="btn btn-primary" style="padding: 4px 10px; font-size: 12px;">
                                                    Usar na OS
                                                </a>
                                            <?php endif; ?>
                                            <a href="index.php?page=equipamentos&action=view&id=<?php echo $eq['id_equipamento']; ?><?php echo $isReturnToOS ? '&return=os&id_cliente=' . $eq['id_cliente'] : ''; ?>" class="btn btn-secondary" style="padding: 4px 10px; font-size: 12px; margin-right: 4px;">
                                                Visualizar
                                            </a>
                                            <a href="index.php?page=equipamentos&action=edit&id=<?php echo $eq['id_equipamento']; ?><?php echo $isReturnToOS ? '&return=os&id_cliente=' . $eq['id_cliente'] : ''; ?>" class="btn btn-secondary" style="padding: 4px 10px; font-size: 12px; margin-right: 4px;">
                                                Editar
                                            </a>
                                            <button type="button" class="btn btn-danger btn-confirm-action" 
                                                    data-url="index.php?page=equipamentos&action=delete&id=<?php echo $eq['id_equipamento']; ?>" 
                                                    data-text="Tem certeza que deseja remover o equipamento '<?php echo htmlspecialchars($eq['aparelho']); ?>'?" 
                                                    style="padding: 4px 10px; font-size: 12px;">
                                                Remover
                                            </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

            <?php else: ?>

            <!-- Formulário de Cadastro / Edição -->
            <div class="card">
                <?php if ($action === 'edit' && $editId): ?>
                    <div class="module-header">
                        <div>
                            <h2 class="module-title" style="color: var(--warning);">Editar Equipamento</h2>
                            <p class="module-subtitle">Atualize o cadastro fixo do aparelho.</p>
                        </div>
                        <a href="<?php echo $isReturnToOS ? 'index.php?page=equipamentos&return=os&action=create&id_cliente=' . urlencode((string)$editData['id_cliente']) : 'index.php?page=equipamentos'; ?>" class="btn btn-secondary">Voltar</a>
                    </div>
                    <form id="formEquipamento" action="index.php?page=equipamentos&action=edit&id=<?php echo $editId; ?><?php echo $returnQuery; ?>" method="POST" autocomplete="off">
                        <input type="hidden" name="form_action" value="update">

                        <div class="form-section" style="margin-top: 0;">
                            <div class="form-section-title">Proprietário</div>
                            <p class="module-subtitle" style="margin-bottom: 14px;">Ao trocar o cliente, o sistema mantém o proprietário anterior no histórico.</p>
                            <div class="form-group">
                                <label for="id_cliente" class="form-label">Cliente Proprietário</label>
                                <select id="id_cliente" name="id_cliente" class="form-control" required>
                                    <option value="">Selecione o proprietário...</option>
                                    <?php foreach ($clientesList as $cli): ?>
                                        <option value="<?php echo $cli['id_pessoa']; ?>" <?php echo ($cli['id_pessoa'] == $editData['id_cliente']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($cli['nome']) . ' (' . htmlspecialchars($cli['cpf_cnpj'] ?: 'Sem documento') . ')'; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <div class="form-section">
                            <div class="form-section-title">Identificação do equipamento</div>
                            <div class="form-group">
                                <label for="aparelho" class="form-label">Aparelho / Item</label>
                                <input type="text" id="aparelho" name="aparelho" class="form-control" value="<?php echo htmlspecialchars($editData['aparelho']); ?>" placeholder="Ex: Notebook, Smartphone, Impressora" required>
                            </div>
                            <div class="form-grid form-grid-2">
                                <div class="form-group">
                                    <label for="marca" class="form-label">Marca</label>
                                    <input type="text" id="marca" name="marca" class="form-control" value="<?php echo htmlspecialchars($editData['marca']); ?>" placeholder="Ex: Dell, Samsung, Apple">
                                </div>
                                <div class="form-group">
                                    <label for="modelo" class="form-label">Modelo</label>
                                    <input type="text" id="modelo" name="modelo" class="form-control" value="<?php echo htmlspecialchars($editData['modelo']); ?>" placeholder="Ex: Inspiron 15, Galaxy S21">
                                </div>
                            </div>
                            <div class="form-grid form-grid-2">
                                <div class="form-group">
                                    <label for="numero_serie" class="form-label">Número de Série (N/S)</label>
                                    <input type="text" id="numero_serie" name="numero_serie" class="form-control" value="<?php echo htmlspecialchars($editData['numero_serie']); ?>" placeholder="Ex: BR123456">
                                </div>
                                <div class="form-group">
                                    <label for="cor" class="form-label">Cor / Identificação Visual</label>
                                    <input type="text" id="cor" name="cor" class="form-control" value="<?php echo htmlspecialchars($editData['cor']); ?>" placeholder="Ex: Preto, prata, adesivo azul">
                                </div>
                            </div>
                            <div class="form-group">
                                <label for="patrimonio" class="form-label">Patrimônio / Etiqueta Interna</label>
                                <input type="text" id="patrimonio" name="patrimonio" class="form-control" value="<?php echo htmlspecialchars($editData['patrimonio']); ?>" placeholder="Ex: PAT-1024, etiqueta da empresa">
                            </div>
                            <div class="form-group">
                                <label for="observacoes" class="form-label">Observações Fixas</label>
                                <textarea id="observacoes" name="observacoes" class="form-control" rows="3" placeholder="Detalhes permanentes do aparelho, acessórios recorrentes, observações do cliente..."><?php echo htmlspecialchars($editData['observacoes']); ?></textarea>
                            </div>
                        </div>

                        <div style="display: flex; gap: 10px; margin-top: 24px;">
                            <button type="submit" class="btn btn-primary" style="flex-grow: 1;">Salvar</button>
                            <a href="<?php echo $isReturnToOS ? 'index.php?page=os&action=create&id_cliente=' . urlencode((string)$editData['id_cliente']) : 'index.php?page=equipamentos'; ?>" class="btn btn-secondary">Cancelar</a>
                        </div>
                    </form>

                    <div class="form-section">
                        <div class="form-section-title">Fotos do equipamento</div>
                        <form action="index.php?page=equipamentos&action=edit&id=<?php echo $editId; ?><?php echo $returnQuery; ?>" method="POST" enctype="multipart/form-data" autocomplete="off" style="display: grid; gap: 12px;">
                            <input type="hidden" name="form_action" value="upload_anexo">
                            <div class="form-group" style="margin-bottom: 0;">
                                <label for="foto_anexo" class="form-label">Foto</label>
                                <input type="file" id="foto_anexo" name="foto_anexo" class="form-control" accept="image/jpeg,image/png,image/webp,image/gif" required>
                            </div>
                            <div class="form-group" style="margin-bottom: 0;">
                                <label for="descricao_anexo" class="form-label">Descrição</label>
                                <input type="text" id="descricao_anexo" name="descricao_anexo" class="form-control" placeholder="Ex: aparelho fechado, placa, número de série">
                            </div>
                            <button type="submit" class="btn btn-secondary">Anexar Foto</button>
                        </form>

                        <?php if (empty($equipamentoAnexos)): ?>
                            <p style="color: var(--text-muted); font-size: 13px; margin-top: 14px;">Nenhuma foto anexada.</p>
                        <?php else: ?>
                            <div class="attachment-grid" style="margin-top: 14px;">
                                <?php foreach ($equipamentoAnexos as $anexo): ?>
                                    <div class="attachment-card">
                                        <a href="<?php echo htmlspecialchars($anexo['caminho']); ?>" target="_blank">
                                            <img src="<?php echo htmlspecialchars($anexo['caminho']); ?>" alt="<?php echo htmlspecialchars($anexo['descricao'] ?: 'Foto do equipamento'); ?>">
                                        </a>
                                        <div>
                                            <div class="table-primary-text"><?php echo htmlspecialchars($anexo['descricao'] ?: 'Foto do equipamento'); ?></div>
                                            <div class="table-muted-text"><?php echo !empty($anexo['created_at']) ? date('d/m/Y H:i', strtotime($anexo['created_at'])) : 'Data não informada'; ?></div>
                                        </div>
                                        <button type="button" class="btn btn-danger btn-confirm-action"
                                                data-url="index.php?page=equipamentos&action=delete_anexo&id=<?php echo $editId; ?>&id_anexo=<?php echo $anexo['id_anexo']; ?><?php echo $returnQuery; ?>"
                                                data-text="Deseja remover esta foto do equipamento?"
                                                style="padding: 4px 10px; font-size: 12px;">
                                            Remover Foto
                                        </button>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <div class="module-header">
                        <div>
                            <h2 class="module-title">Novo Equipamento</h2>
                            <p class="module-subtitle">Vincule o aparelho ao cliente antes de abrir OS.</p>
                        </div>
                        <a href="<?php echo $isReturnToOS ? 'index.php?page=equipamentos&return=os&action=create' . ($returnClienteId ? '&id_cliente=' . urlencode((string)$returnClienteId) : '') : 'index.php?page=equipamentos'; ?>" class="btn btn-secondary">Voltar</a>
                    </div>
                    
                    <?php if (empty($clientesList)): ?>
                        <div class="alert alert-danger" style="margin: 0; font-size: 13.5px; line-height: 1.5;">
                            <strong>Aviso Importante:</strong><br>
                            É necessário cadastrar pelo menos um <a href="index.php?page=clientes" style="color: inherit; text-decoration: underline; font-weight: bold;">Cliente</a> ativo antes de cadastrar um equipamento.
                        </div>
                    <?php else: ?>
                        <form id="formEquipamento" action="index.php?page=equipamentos<?php echo $isReturnToOS ? '&return=os' . ($returnClienteId ? '&id_cliente=' . $returnClienteId : '') : ''; ?>" method="POST" autocomplete="off">
                            <input type="hidden" name="form_action" value="create">

                            <div class="form-section" style="margin-top: 0;">
                                <div class="form-section-title">Proprietário</div>
                                <div class="form-group">
                                    <label for="id_cliente" class="form-label">Cliente Proprietário</label>
                                    <select id="id_cliente" name="id_cliente" class="form-control" required>
                                        <option value="">Selecione o proprietário...</option>
                                        <?php foreach ($clientesList as $cli): ?>
                                            <option value="<?php echo $cli['id_pessoa']; ?>" <?php echo ($cli['id_pessoa'] == $editData['id_cliente']) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($cli['nome']) . ' (' . htmlspecialchars($cli['cpf_cnpj'] ?: 'Sem documento') . ')'; ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>

                            <div class="form-section">
                                <div class="form-section-title">Identificação do equipamento</div>
                                <div class="form-group">
                                    <label for="aparelho" class="form-label">Aparelho / Item</label>
                                    <input type="text" id="aparelho" name="aparelho" class="form-control" placeholder="Ex: Notebook, Smartphone, Impressora" required>
                                </div>

                                <div class="form-grid form-grid-2">
                                    <div class="form-group">
                                        <label for="marca" class="form-label">Marca</label>
                                        <input type="text" id="marca" name="marca" class="form-control" placeholder="Ex: Dell, Samsung, Apple">
                                    </div>
                                    <div class="form-group">
                                        <label for="modelo" class="form-label">Modelo</label>
                                        <input type="text" id="modelo" name="modelo" class="form-control" placeholder="Ex: Inspiron 15, Galaxy S21">
                                    </div>
                                </div>

                                <div class="form-grid form-grid-2">
                                    <div class="form-group">
                                        <label for="numero_serie" class="form-label">Número de Série (N/S)</label>
                                        <input type="text" id="numero_serie" name="numero_serie" class="form-control" placeholder="Ex: BR123456">
                                    </div>
                                    <div class="form-group">
                                        <label for="cor" class="form-label">Cor / Identificação Visual</label>
                                        <input type="text" id="cor" name="cor" class="form-control" placeholder="Ex: Preto, prata, adesivo azul">
                                    </div>
                                </div>

                                <div class="form-group">
                                    <label for="patrimonio" class="form-label">Patrimônio / Etiqueta Interna</label>
                                    <input type="text" id="patrimonio" name="patrimonio" class="form-control" placeholder="Ex: PAT-1024, etiqueta da empresa">
                                </div>

                                <div class="form-group">
                                    <label for="observacoes" class="form-label">Observações Fixas</label>
                                    <textarea id="observacoes" name="observacoes" class="form-control" rows="3" placeholder="Detalhes permanentes do aparelho, acessórios recorrentes, observações do cliente..."></textarea>
                                </div>
                            </div>

                            <button type="submit" class="btn btn-primary" style="width: 100%; margin-top: 24px;">
                                Cadastrar Equipamento
                            </button>
                        </form>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
            <?php endif; ?>

        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Lógica JS Avançada de Teclado, Notificações Customizadas e Modal de Confirmação -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('formEquipamento');
    const modal = document.getElementById('custom-confirm-modal');
    const modalText = document.getElementById('confirm-modal-text');
    const modalOk = document.getElementById('confirm-modal-ok');
    const modalCancel = document.getElementById('confirm-modal-cancel');

    // 1. IMPEDE A SUBMISSÃO DO FORMULÁRIO AO TECLAR ENTER NOS INPUTS
    if (form) {
        form.addEventListener('keydown', function(e) {
            if (e.key === 'Enter' && e.target.tagName === 'INPUT') {
                e.preventDefault();
                
                // Salta para o próximo campo de entrada disponível
                const fields = Array.from(form.querySelectorAll('input, select, textarea'));
                const index = fields.indexOf(e.target);
                if (index > -1 && index + 1 < fields.length) {
                    fields[index + 1].focus();
                }
                return false;
            }
        });
    }

    // 2. SISTEMA DE NOTIFICAÇÃO EM TELA (Substituto do alert() nativo)
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

    // 3. VALIDAÇÃO CLIENTE ADICIONAL NO SUBMIT
    if (form) {
        form.addEventListener('submit', function(e) {
            const clienteSelect = document.getElementById('id_cliente');
            const aparelhoInput = document.getElementById('aparelho');

            if (clienteSelect && clienteSelect.value === '') {
                e.preventDefault();
                mostrarNotificacaoErro('Por favor, selecione o cliente proprietário do equipamento.');
                clienteSelect.focus();
                return false;
            }

            if (aparelhoInput && aparelhoInput.value.trim() === '') {
                e.preventDefault();
                mostrarNotificacaoErro('Por favor, informe o tipo do aparelho.');
                aparelhoInput.focus();
                return false;
            }
        });
    }

    // 4. CONTROLO DO MODAL DE CONFIRMAÇÃO DE REMOÇÃO (Substituto do confirm() nativo)
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
        modalCancel.addEventListener('click', function() {
            modal.style.display = 'none';
        });
    }

    // Fecha o modal se clicar fora do conteúdo
    modal.addEventListener('click', function(e) {
        if (e.target === modal) {
            modal.style.display = 'none';
        }
    });
});
</script>
