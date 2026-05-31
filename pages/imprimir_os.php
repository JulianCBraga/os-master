<?php
/**
 * OS Master - Comprovativo de Entrada e Orçamento para Impressão
 * * Este ficheiro implementa uma visualização limpa, livre de barras laterais,
 * otimizada para impressoras térmicas ou folhas A4, contendo os termos legais
 * e assinaturas.
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

// Captura o ID da OS a ser impressa
$id_os = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

$osData = null;
$pecasList = [];
$configSistema = null;
$currencySymbol = 'R$';

if ($id_os) {
    try {
        // 1. Busca os parâmetros globais da empresa e termo legal
        $stmtConfig = $pdo->query("SELECT * FROM config_sistema WHERE id_config = 1 LIMIT 1");
        $configSistema = $stmtConfig->fetch();
        if ($configSistema && isset($configSistema['moeda']) && !empty($configSistema['moeda'])) {
            $currencySymbol = $configSistema['moeda'];
        }

        // 2. Busca dados completos da OS, Cliente, Equipamento e Técnico
        $sqlOS = "
            SELECT o.*, 
                   pCli.nome AS cliente_nome, pCli.cpf_cnpj AS cliente_doc, pCli.telefone AS cliente_telefone,
                   pCli.endereco AS cliente_rua, pCli.numero AS cliente_num, pCli.bairro AS cliente_bairro,
                   cCli.nome AS cliente_cidade, eCli.sigla AS cliente_uf,
                   e.aparelho, e.marca, e.modelo, e.numero_serie, e.id_equipamento,
                   pTec.nome AS tecnico_nome
            FROM os o
            INNER JOIN cliente c ON o.id_cliente = c.id_pessoa
            INNER JOIN pessoa pCli ON c.id_pessoa = pCli.id_pessoa
            INNER JOIN cidade cCli ON pCli.id_cidade = cCli.id_cidade
            INNER JOIN estado eCli ON cCli.id_estado = eCli.id_estado
            INNER JOIN equipamento e ON o.id_equipamento = e.id_equipamento
            INNER JOIN funcionario f ON o.id_tecnico = f.id_pessoa
            INNER JOIN pessoa pTec ON f.id_pessoa = pTec.id_pessoa
            WHERE o.id_os = :id LIMIT 1
        ";
        $stmtOS = $pdo->prepare($sqlOS);
        $stmtOS->execute([':id' => $id_os]);
        $osData = $stmtOS->fetch();

        if ($osData) {
            // 3. Busca as peças aplicadas nesta OS
            $sqlPecas = "
                SELECT i.*, p.descricao 
                FROM item_os i
                INNER JOIN produto p ON i.id_produto = p.id_produto
                WHERE i.id_os = :id
            ";
            $stmtPecas = $pdo->prepare($sqlPecas);
            $stmtPecas->execute([':id' => $id_os]);
            $pecasList = $stmtPecas->fetchAll();

            // Marca que o termo de compromisso/comprovativo foi gerado e visualizado
            if ($osData['termo_compromisso_gerado'] == 0) {
                $pdo->prepare("UPDATE os SET termo_compromisso_gerado = 1 WHERE id_os = :id")->execute([':id' => $id_os]);
            }
        }
    } catch (PDOException $e) {
        $osData = null;
    }
}

// Helpers de formatação locais para manter escopo isolado
if (!function_exists('formatMoneyLocal')) {
    function formatMoneyLocal($value): string {
        return number_format((float)$value, 2, ',', '.');
    }
}

// Badge de status local para manter independência do ficheiro de funções
if (!function_exists('getStatusTextLocal')) {
    function getStatusTextLocal($status): string {
        switch ($status) {
            case 'aguardando_analise': return 'Aguardando Análise';
            case 'em_diagnostico': return 'Em Diagnóstico';
            case 'aguardando_aprovacao': return 'Aguardando Aprovação';
            case 'aguardando_peca': return 'Aguardando Peça';
            case 'em_reparo': return 'Em Reparo';
            case 'reparo_concluido': return 'Reparo Concluído';
            case 'sem_conserto': return 'Sem conserto retirar';
            case 'pronto_para_retirada': return 'Pronto para Retirada';
            case 'entregue': return 'Entregue';
            case 'abandonado': return 'Abandonado';
            case 'descarte': return 'Descarte';
            default: return ucfirst(str_replace('_', ' ', $status));
        }
    }
}
?>

<div style="background-color: #ffffff; color: #000000; min-height: 100vh; padding: 40px; font-family: 'Courier New', Courier, monospace; font-size: 13px; line-height: 1.5; max-width: 800px; margin: 0 auto; box-shadow: 0 0 10px rgba(0,0,0,0.05); border: 1px solid #e2e8f0;" id="print-sheet">
    
    <!-- Painel flutuante de controle de impressão (Ocultado ao imprimir) -->
    <div class="no-print" style="background-color: #f1f5f9; border: 1px solid #cbd5e1; border-radius: 6px; padding: 16px; margin-bottom: 30px; display: flex; justify-content: space-between; align-items: center; font-family: sans-serif;">
        <div>
            <strong style="color: #1e293b; font-size: 14px; display: block; margin-bottom: 2px;">Visualização de Impressão</strong>
            <span style="font-size: 11.5px; color: #64748b;">Esta folha foi estruturada sem barras laterais e formatada para folhas de papel.</span>
        </div>
        <div style="display: flex; gap: 8px;">
            <a href="index.php?page=os" style="background-color: #64748b; color: white; text-decoration: none; padding: 8px 16px; border-radius: 4px; font-weight: bold; font-size: 12.5px; display: inline-block;">← Voltar</a>
            <button onclick="window.print();" style="background-color: #22c55e; color: white; border: none; padding: 8px 20px; border-radius: 4px; font-weight: bold; cursor: pointer; font-size: 12.5px; display: inline-block;">🖨️ Imprimir Recibo</button>
        </div>
    </div>

    <?php if (!$osData): ?>
        <div style="border: 2px dashed #ef4444; padding: 20px; text-align: center; border-radius: 6px; font-family: sans-serif;">
            <h3 style="color: #ef4444; margin-top: 0;">Ordem de Serviço Não Encontrada</h3>
            <p style="color: #64748b; margin-bottom: 0;">O ID de OS solicitado não pôde ser carregado a partir da base de dados do sistema.</p>
        </div>
    <?php else: ?>

        <!-- CABEÇALHO DA EMPRESA -->
        <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 2px double #000000; padding-bottom: 20px; margin-bottom: 20px;">
            <div style="flex-grow: 1;">
                <h1 style="font-size: 20px; font-weight: 800; margin: 0 0 6px 0; text-transform: uppercase;">
                    <?php echo htmlspecialchars($configSistema['nome_fantasia'] ?? 'OS Master Assistência'); ?>
                </h1>
                <p style="margin: 0; font-size: 12px; color: #1e293b;">
                    <?php echo htmlspecialchars($configSistema['razao_social'] ?? ''); ?><br>
                    CNPJ: <?php echo htmlspecialchars($configSistema['cnpj'] ?? '00.000.000/0001-00'); ?><br>
                    Tel: <?php echo htmlspecialchars($configSistema['telefone'] ?? ''); ?> • E-mail: <?php echo htmlspecialchars($configSistema['email'] ?? ''); ?><br>
                    Endereço: <?php echo htmlspecialchars($configSistema['endereco_completo'] ?? ''); ?>
                </p>
            </div>
            
            <div style="width: 140px; text-align: right;">
                <?php if (!empty($configSistema['logo_caminho']) && file_exists(BASE_PATH . '/' . $configSistema['logo_caminho'])): ?>
                    <img src="<?php echo htmlspecialchars($configSistema['logo_caminho']); ?>" style="max-width: 100%; max-height: 75px; object-fit: contain;">
                <?php else: ?>
                    <div style="border: 1px solid #000; padding: 10px; font-size: 11px; font-weight: bold; text-align: center; font-family: sans-serif;">LOGÓTIPO</div>
                <?php endif; ?>
            </div>
        </div>

        <!-- TÍTULO DO COMPROVATIVO -->
        <div style="text-align: center; margin-bottom: 24px;">
            <h2 style="font-size: 16px; font-weight: bold; text-transform: uppercase; margin: 0; border: 1px solid #000; padding: 6px; letter-spacing: 1px;">
                Ficha de Entrada & Orçamento de Ordem de Serviço #<?php echo $osData['id_os']; ?>
            </h2>
            <div style="display: flex; justify-content: space-between; margin-top: 10px; font-size: 12px; font-weight: bold;">
                <span>Abertura: <?php echo date('d/m/Y H:i', strtotime($osData['data_abertura'])); ?></span>
                <span>Técnico: <?php echo htmlspecialchars($osData['tecnico_nome']); ?></span>
                <span style="text-transform: uppercase;">Status: <?php echo getStatusTextLocal($osData['status']); ?></span>
            </div>
        </div>

        <!-- SEÇÃO 1: CLIENTE E APARELHO -->
        <table style="width: 100%; border-collapse: collapse; margin-bottom: 20px; font-size: 12px;">
            <tr>
                <td style="width: 50%; padding: 8px; border: 1px solid #000000; vertical-align: top;">
                    <strong style="text-transform: uppercase; display: block; border-bottom: 1px solid #000; margin-bottom: 6px; padding-bottom: 2px;">DADOS DO CLIENTE</strong>
                    Nome: <strong><?php echo htmlspecialchars($osData['cliente_nome']); ?></strong><br>
                    CPF/CNPJ: <?php echo htmlspecialchars($osData['cliente_doc'] ?: 'Não cadastrado'); ?><br>
                    Telefone: <?php echo htmlspecialchars($osData['cliente_telefone'] ?: 'Não cadastrado'); ?><br>
                    Morada: <?php echo htmlspecialchars($osData['cliente_rua'] . ', ' . $osData['cliente_num']); ?><br>
                    Bairro: <?php echo htmlspecialchars($osData['cliente_bairro'] . ' - ' . $osData['cliente_cidade'] . '/' . $osData['cliente_uf']); ?>
                </td>
                <td style="width: 50%; padding: 8px; border: 1px solid #000000; vertical-align: top;">
                    <strong style="text-transform: uppercase; display: block; border-bottom: 1px solid #000; margin-bottom: 6px; padding-bottom: 2px;">EQUIPAMENTO / ITEM</strong>
                    Aparelho: <strong><?php echo htmlspecialchars($osData['aparelho']); ?></strong><br>
                    Marca / Modelo: <?php echo htmlspecialchars($osData['marca'] . ' / ' . $osData['modelo']); ?><br>
                    Número de Série: <code><?php echo htmlspecialchars($osData['numero_serie'] ?: 'Não informado'); ?></code><br>
                    Estado Físico Entrada:<br>
                    <span style="font-style: italic; color: #4b5563;"><?php echo htmlspecialchars($osData['estado_aparelho_entrada'] ?: 'Nenhuma observação física reportada.'); ?></span>
                </td>
            </tr>
        </table>

        <!-- SEÇÃO 2: DETALHES DE PROBLEMAS -->
        <div style="border: 1px solid #000000; padding: 10px; margin-bottom: 20px; font-size: 12px;">
            <strong style="text-transform: uppercase; display: block; border-bottom: 1px solid #000000; margin-bottom: 6px; padding-bottom: 2px;">Defeito / Problema Relatado</strong>
            <p style="margin: 0; white-space: pre-line; line-height: 1.4; color: #1f2937;"><?php echo htmlspecialchars($osData['descricao_problema'] ?: 'Nenhuma descrição detalhada.'); ?></p>
        </div>

        <?php if (!empty($osData['diagnostico'])): ?>
            <div style="border: 1px solid #000000; padding: 10px; margin-bottom: 20px; font-size: 12px;">
                <strong style="text-transform: uppercase; display: block; border-bottom: 1px solid #000000; margin-bottom: 6px; padding-bottom: 2px;">Diagnóstico / Laudo Técnico Realizado</strong>
                <p style="margin: 0; white-space: pre-line; line-height: 1.4; color: #1f2937;"><?php echo htmlspecialchars($osData['diagnostico']); ?></p>
            </div>
        <?php endif; ?>

        <!-- SEÇÃO 3: PEÇAS E MATERIAIS ENVOLVIDOS (Se houver) -->
        <?php if (!empty($pecasList)): ?>
            <div style="border: 1px solid #000000; padding: 10px; margin-bottom: 20px; font-size: 12px;">
                <strong style="text-transform: uppercase; display: block; border-bottom: 1px solid #000000; margin-bottom: 6px; padding-bottom: 2px;">Peças, Componentes e Insumos</strong>
                <table style="width: 100%; border-collapse: collapse; margin-top: 6px;">
                    <thead>
                        <tr style="border-bottom: 1px dashed #000;">
                            <th style="text-align: left; padding: 4px 0;">Item/Descrição</th>
                            <th style="text-align: center; padding: 4px 0; width: 80px;">Qtd</th>
                            <th style="text-align: right; padding: 4px 0; width: 120px;">Unitário</th>
                            <th style="text-align: right; padding: 4px 0; width: 120px;">Subtotal</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pecasList as $item): ?>
                            <tr>
                                <td style="padding: 4px 0;"><?php echo htmlspecialchars($item['descricao']); ?></td>
                                <td style="text-align: center; padding: 4px 0;"><?php echo $item['quantidade']; ?></td>
                                <td style="text-align: right; padding: 4px 0;"><?php echo htmlspecialchars($currencySymbol) . ' ' . formatMoneyLocal($item['valor_unitario']); ?></td>
                                <td style="text-align: right; padding: 4px 0;"><?php echo htmlspecialchars($currencySymbol) . ' ' . formatMoneyLocal($item['subtotal']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <!-- ORÇAMENTO FINANCEIRO CONSOLIDADO -->
        <div style="display: flex; justify-content: flex-end; margin-bottom: 30px;">
            <table style="border-collapse: collapse; font-size: 13px; width: 380px; border: 1px solid #000000;">
                <tr>
                    <td style="padding: 6px 8px; border-bottom: 1px dashed #000;">Total de Peças:</td>
                    <td style="text-align: right; padding: 6px 8px; border-bottom: 1px dashed #000; font-weight: bold;">
                        <?php echo htmlspecialchars($currencySymbol) . ' ' . formatMoneyLocal($osData['valor_pecas']); ?>
                    </td>
                </tr>
                <tr>
                    <td style="padding: 6px 8px; border-bottom: 1px solid #000;">Mão de Obra / Serviço:</td>
                    <td style="text-align: right; padding: 6px 8px; border-bottom: 1px solid #000; font-weight: bold;">
                        <?php echo htmlspecialchars($currencySymbol) . ' ' . formatMoneyLocal($osData['valor_mao_obra']); ?>
                    </td>
                </tr>
                <tr style="background-color: #f8fafc; font-size: 14px;">
                    <td style="padding: 8px; font-weight: bold;">TOTAL GERAL:</td>
                    <td style="text-align: right; padding: 8px; font-weight: 800; color: #10b981;">
                        <?php echo htmlspecialchars($currencySymbol) . ' ' . formatMoneyLocal($osData['valor_total']); ?>
                    </td>
                </tr>
            </table>
        </div>

        <!-- SEÇÃO 4: TERMOS E DECLARAÇÃO LEGAL (Termo de Compromisso) -->
        <?php if (!empty($configSistema['termo_compromisso_texto'])): ?>
            <div style="border: 1px solid #000000; padding: 12px; font-size: 11px; text-align: justify; line-height: 1.4; margin-bottom: 40px; background-color: rgba(0,0,0,0.01);">
                <strong style="text-transform: uppercase; display: block; text-align: center; margin-bottom: 6px; font-size: 11.5px; border-bottom: 1px dashed #000; padding-bottom: 4px;">Termos de Consentimento e Responsabilidade</strong>
                <?php echo nl2br(htmlspecialchars($configSistema['termo_compromisso_texto'])); ?>
            </div>
        <?php endif; ?>

        <!-- SEÇÃO 5: ASSINATURAS -->
        <div style="display: flex; justify-content: space-between; margin-top: 50px; font-size: 12px;">
            <div style="width: 45%; text-align: center;">
                <div style="border-top: 1px solid #000000; margin-bottom: 4px; padding-top: 4px;"></div>
                <strong><?php echo htmlspecialchars($osData['cliente_nome']); ?></strong><br>
                <span>Assinatura do Cliente / Proprietário</span>
            </div>
            <div style="width: 45%; text-align: center;">
                <div style="border-top: 1px solid #000000; margin-bottom: 4px; padding-top: 4px;"></div>
                <strong><?php echo htmlspecialchars($configSistema['nome_fantasia'] ?? 'OS Master'); ?></strong><br>
                <span>Responsável Técnico / Receção</span>
            </div>
        </div>

    <?php endif; ?>
</div>

<style>
/* CSS especial de regras de impressão (Oculta botões e barra de ferramentas flutuantes) */
@media print {
    body {
        background-color: #ffffff !important;
        margin: 0 !important;
        padding: 0 !important;
    }
    .no-print {
        display: none !important;
    }
    #print-sheet {
        max-width: 100% !important;
        box-shadow: none !important;
        padding: 0 !important;
        border: none !important;
    }
}
</style>
