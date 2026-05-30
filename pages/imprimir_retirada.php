<?php
/**
 * OS Master - Termo de Retirada e Garantia
 * * Este ficheiro gera uma visualização limpa e otimizada para impressão.
 * É disparado automaticamente quando o status da Ordem de Serviço muda para "Entregue".
 * Contém declaração de recebimento e linhas para assinatura do cliente.
 * * @package OSMaster
 * @author Julian C. Braga
 * @version 2.3
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

// Captura e valida o ID da OS a ser impressa
$id_os = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$id_os) {
    die("<div style='padding: 20px; font-family: sans-serif; color: red;'>Erro: Número da Ordem de Serviço não fornecido ou inválido.</div>");
}

$osData = null;
$configSistema = null;
$historicoEntrega = null;

try {
    // 1. Busca os parâmetros globais da empresa (Nome fantasia, CNPJ, Telefone)
    $stmtConfig = $pdo->query("SELECT * FROM config_sistema WHERE id_config = 1 LIMIT 1");
    $configSistema = $stmtConfig->fetch();

    // 2. Busca todos os dados relacionais da OS (Cliente, Equipamento, Técnico)
    $sqlOS = "SELECT o.*, 
                     pCli.nome AS cliente_nome, pCli.cpf_cnpj AS cliente_doc, pCli.telefone AS cliente_tel,
                     e.aparelho, e.marca, e.modelo, e.numero_serie,
                     pTec.nome AS tecnico_nome 
              FROM os o 
              INNER JOIN pessoa pCli ON o.id_cliente = pCli.id_pessoa 
              INNER JOIN equipamento e ON o.id_equipamento = e.id_equipamento 
              INNER JOIN pessoa pTec ON o.id_tecnico = pTec.id_pessoa 
              WHERE o.id_os = :id LIMIT 1";
    
    $stmtOS = $pdo->prepare($sqlOS);
    $stmtOS->execute([':id' => $id_os]);
    $osData = $stmtOS->fetch();

    if (!$osData) {
        die("<div style='padding: 20px; font-family: sans-serif; color: red;'>Erro: Ordem de Serviço #{$id_os} não localizada no sistema.</div>");
    }

    // 3. Busca a última observação registada no histórico para anexar ao laudo de entrega (opcional)
    // CORREÇÃO DEFINITIVA: Remoção de qualquer ORDER BY de datas que possa variar o nome da coluna no seu DB
    $sqlHist = "SELECT observacao_interna FROM historico_os WHERE id_os = :id";
    $stmtHist = $pdo->prepare($sqlHist);
    $stmtHist->execute([':id' => $id_os]);
    $historicoRegistros = $stmtHist->fetchAll();
    
    if (!empty($historicoRegistros)) {
        // Extrai o último elemento do array (que representa a última observação inserida de facto)
        $ultimoRegisto = end($historicoRegistros);
        $historicoEntrega = $ultimoRegisto['observacao_interna'];
    }

} catch (PDOException $e) {
    die("<div style='padding: 20px; font-family: sans-serif; color: red;'>Erro de Base de Dados: " . htmlspecialchars($e->getMessage()) . "</div>");
}

// Arrays de mapeamento para o Status visual
$statusOficiais = [
    'aguardando_analise' => 'Aguardando Análise',
    'em_diagnostico' => 'Em Diagnóstico',
    'aguardando_aprovacao' => 'Aguardando Aprovação',
    'aguardando_peca' => 'Aguardando Peça',
    'em_reparo' => 'Em Reparo',
    'reparo_concluido' => 'Reparo concluído',
    'pronto_para_retirada' => 'Pronto para Retirada',
    'sem_conserto' => 'Sem conserto retirar',
    'entregue' => 'Entregue (Finalizada)',
    'abandonado' => 'Abandonado',
    'descarte' => 'Descarte'
];
?>

<!-- Estilos exclusivos para impressão (Ignora o estilo global do painel) -->
<style>
    /* Reset básico para impressão */
    body {
        margin: 0;
        padding: 0;
        background-color: #f1f5f9;
        font-family: Arial, Helvetica, sans-serif;
        color: #000;
    }
    
    /* Container que simula uma folha A4 no ecrã */
    .print-sheet {
        max-width: 800px;
        margin: 20px auto;
        padding: 40px;
        background-color: #fff;
        border: 1px solid #ccc;
        box-shadow: 0 0 10px rgba(0,0,0,0.1);
    }

    .header-box {
        display: flex;
        justify-content: space-between;
        align-items: center;
        border-bottom: 2px solid #000;
        padding-bottom: 15px;
        margin-bottom: 20px;
    }

    .empresa-info h1 {
        margin: 0 0 5px 0;
        font-size: 24px;
        text-transform: uppercase;
    }

    .empresa-info p {
        margin: 0;
        font-size: 12px;
        color: #333;
    }

    .os-number-box {
        text-align: right;
    }

    .os-number-box h2 {
        margin: 0;
        font-size: 28px;
        color: #000;
    }

    .section-title {
        font-size: 12px;
        text-transform: uppercase;
        background-color: #eee;
        padding: 6px 10px;
        margin: 20px 0 10px 0;
        font-weight: bold;
        border-left: 4px solid #000;
    }

    .info-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 10px;
        font-size: 13px;
        margin-bottom: 10px;
    }

    .info-grid div {
        background-color: #fafafa;
        padding: 8px;
        border: 1px solid #eee;
    }

    .info-grid span {
        display: block;
        font-size: 10px;
        color: #666;
        text-transform: uppercase;
        margin-bottom: 2px;
    }

    .info-grid strong {
        font-size: 14px;
    }

    .termo-box {
        margin-top: 30px;
        padding: 20px;
        border: 1px dashed #000;
        background-color: #fcfcfc;
        font-size: 13px;
        line-height: 1.6;
        text-align: justify;
    }

    .signatures {
        display: flex;
        justify-content: space-between;
        margin-top: 60px;
    }

    .signature-line {
        width: 45%;
        text-align: center;
    }

    .signature-line div {
        border-top: 1px solid #000;
        margin-bottom: 5px;
    }

    .signature-line strong {
        font-size: 13px;
        display: block;
    }

    .signature-line span {
        font-size: 11px;
        color: #555;
    }

    .btn-print {
        display: block;
        width: 100%;
        max-width: 800px;
        margin: 0 auto 20px auto;
        padding: 15px;
        background-color: #3b82f6;
        color: #fff;
        text-align: center;
        text-decoration: none;
        font-weight: bold;
        border-radius: 4px;
        cursor: pointer;
        border: none;
        font-size: 16px;
    }
    
    .btn-print:hover { background-color: #2563eb; }

    /* Regras estritas para a hora de enviar para a impressora */
    @media print {
        body {
            background-color: #fff !important;
            margin: 0 !important;
            padding: 0 !important;
        }
        .print-sheet {
            margin: 0 !important;
            padding: 0 !important;
            border: none !important;
            box-shadow: none !important;
            max-width: 100% !important;
            width: 100% !important;
        }
        .no-print {
            display: none !important; /* Esconde os botões ao imprimir */
        }
    }
</style>

<!-- Painel de controle de impressão (oculto na impressão) -->
<div class="no-print" style="max-width: 800px; margin: 20px auto; background-color: #f1f5f9; border: 1px solid #cbd5e1; border-radius: 6px; padding: 16px; display: flex; justify-content: space-between; align-items: center; font-family: sans-serif;">
    <div>
        <strong style="color: #1e293b; font-size: 14px; display: block; margin-bottom: 2px;">Visualização de Impressão</strong>
        <span style="font-size: 11.5px; color: #64748b;">Termo de retirada formatado para impressão e assinatura do cliente.</span>
    </div>
    <div style="display: flex; gap: 8px;">
        <a href="index.php?page=os" style="background-color: #64748b; color: white; text-decoration: none; padding: 8px 16px; border-radius: 4px; font-weight: bold; font-size: 12.5px; display: inline-block;">← Voltar</a>
        <button onclick="window.print();" style="background-color: #22c55e; color: white; border: none; padding: 8px 20px; border-radius: 4px; font-weight: bold; cursor: pointer; font-size: 12.5px; display: inline-block;">🖨️ Imprimir Termo</button>
    </div>
</div>

<div class="print-sheet">
    
    <!-- CABEÇALHO -->
    <div class="header-box">
        <div class="empresa-info">
            <h1><?php echo htmlspecialchars($configSistema['nome_fantasia'] ?? 'OS Master'); ?></h1>
            <?php if (!empty($configSistema['cnpj'])): ?>
                <p>NIF/CNPJ: <?php echo htmlspecialchars($configSistema['cnpj']); ?></p>
            <?php endif; ?>
            <?php if (!empty($configSistema['telefone'])): ?>
                <p>Contacto: <?php echo htmlspecialchars($configSistema['telefone']); ?></p>
            <?php endif; ?>
        </div>
        <div class="os-number-box">
            <p style="margin: 0; font-size: 12px; font-weight: bold; text-transform: uppercase;">Termo de Entrega / Retirada</p>
            <h2>OS #<?php echo str_pad($osData['id_os'], 5, '0', STR_PAD_LEFT); ?></h2>
            <p style="margin: 5px 0 0 0; font-size: 12px;">Data: <?php echo date('d/m/Y H:i'); ?></p>
        </div>
    </div>

    <!-- DADOS DO CLIENTE -->
    <div class="section-title">1. Dados do Cliente / Proprietário</div>
    <div class="info-grid">
        <div>
            <span>Nome do Cliente</span>
            <strong><?php echo htmlspecialchars($osData['cliente_nome']); ?></strong>
        </div>
        <div>
            <span>Documento (CPF/CNPJ)</span>
            <strong><?php echo htmlspecialchars($osData['cliente_doc'] ?: 'Não Informado'); ?></strong>
        </div>
        <div>
            <span>Contacto / Telefone</span>
            <strong><?php echo htmlspecialchars($osData['cliente_tel'] ?: 'Não Informado'); ?></strong>
        </div>
        <div>
            <span>Técnico Responsável pela Entrega</span>
            <strong><?php echo htmlspecialchars($osData['tecnico_nome']); ?></strong>
        </div>
    </div>

    <!-- DADOS DO EQUIPAMENTO -->
    <div class="section-title">2. Dados do Equipamento</div>
    <div class="info-grid">
        <div>
            <span>Aparelho / Tipo</span>
            <strong><?php echo htmlspecialchars($osData['aparelho']); ?></strong>
        </div>
        <div>
            <span>Marca / Modelo</span>
            <strong><?php echo htmlspecialchars($osData['marca'] . ' ' . $osData['modelo']); ?></strong>
        </div>
        <div style="grid-column: span 2;">
            <span>Número de Série (S/N) / IMEI</span>
            <strong><?php echo htmlspecialchars($osData['numero_serie'] ?: 'Não Informado'); ?></strong>
        </div>
    </div>

    <!-- STATUS FINAL E OBSERVAÇÕES -->
    <div class="section-title">3. Parecer Técnico e Estado Final</div>
    <div style="padding: 10px; border: 1px solid #eee; font-size: 13px;">
        <p style="margin: 0 0 10px 0;"><strong>Status Final do Equipamento:</strong> <?php echo htmlspecialchars($statusOficiais[$osData['status']] ?? $osData['status']); ?></p>
        <?php if ($historicoEntrega): ?>
            <p style="margin: 0;"><strong>Última Observação Técnica:</strong><br><?php echo nl2br(htmlspecialchars($historicoEntrega)); ?></p>
        <?php endif; ?>
    </div>

    <!-- TERMO LEGAL DE DECLARAÇÃO -->
    <div class="termo-box">
        <strong>DECLARAÇÃO DE RECEBIMENTO E CONFORMIDADE</strong><br><br>
        Declaro para os devidos fins legais que estou a retirar o equipamento acima descrito nas instalações da empresa <strong><?php echo htmlspecialchars($configSistema['nome_fantasia'] ?? 'OS Master'); ?></strong>, nesta data.<br><br>
        Confirmo que o mesmo foi devidamente testado na minha presença, e que concordo com o estado de entrega e o parecer técnico relatado. Estou ciente dos termos de garantia legal aplicáveis aos serviços prestados e às eventuais peças substituídas constantes no orçamento (se aplicável), e que a garantia perde a validade em caso de mau uso, violação de selos de segurança, quedas ou derramamento de líquidos pós-entrega.
    </div>

    <!-- ASSINATURAS -->
    <div class="signatures">
        <div class="signature-line">
            <div></div>
            <strong><?php echo htmlspecialchars($osData['cliente_nome']); ?></strong>
            <span>Assinatura do Cliente / Recebedor</span>
        </div>
        
        <div class="signature-line">
            <div></div>
            <strong><?php echo htmlspecialchars($configSistema['nome_fantasia'] ?? 'OS Master'); ?></strong>
            <span>Assinatura da Assistência Técnica</span>
        </div>
    </div>

    <div style="text-align: center; margin-top: 40px; font-size: 10px; color: #999;">
        Documento gerado pelo sistema OS Master em <?php echo date('d/m/Y \à\s H:i:s'); ?>
    </div>

</div>
