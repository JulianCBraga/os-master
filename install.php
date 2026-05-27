<?php
/**
 * OS Master - Instalador Automático do Sistema
 * * Este ficheiro cria a base de dados, a estrutura de tabelas, as chaves
 * estrangeiras e o utilizador administrador padrão do sistema.
 * * @package OSMaster
 * @author Julian C. Braga
 * @version 1.0
 */

// Inicia a sessão para feedback visual
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Definição temporária das credenciais para criação da base de dados
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'os_master');

$installationSuccess = false;
$installationError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'install') {
    try {
        // 1. Conexão inicial com o servidor MySQL (sem base de dados selecionada)
        $dsnNoDb = "mysql:host=" . DB_HOST . ";charset=utf8mb4";
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        
        $pdoInit = new PDO($dsnNoDb, DB_USER, DB_PASS, $options);
        
        // 2. Criação da base de dados caso não exista
        $pdoInit->exec("CREATE DATABASE IF NOT EXISTS `" . DB_NAME . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;");
        
        // Conectar agora à base de dados criada
        $pdoInit->exec("USE `" . DB_NAME . "`;");
        
        // 3. Desativar verificação de FK para estruturar de forma limpa
        $pdoInit->exec("SET FOREIGN_KEY_CHECKS = 0;");
        
        // Definição das queries SQL do script oficial 'os_master.sql'
        $sqlQueries = [
            // Drop Tables se existirem (para reinstalação limpa se necessário)
            "DROP TABLE IF EXISTS `usuario`;",
            "DROP TABLE IF EXISTS `item_os`;",
            "DROP TABLE IF EXISTS `historico_os`;",
            "DROP TABLE IF EXISTS `folha_pagto`;",
            "DROP TABLE IF EXISTS `os`;",
            "DROP TABLE IF EXISTS `equipamento`;",
            "DROP TABLE IF EXISTS `cliente`;",
            "DROP TABLE IF EXISTS `funcionario`;",
            "DROP TABLE IF EXISTS `pessoa`;",
            "DROP TABLE IF EXISTS `cidade`;",
            "DROP TABLE IF EXISTS `estado`;",
            "DROP TABLE IF EXISTS `produto`;",
            "DROP TABLE IF EXISTS `config_sistema`;",
            
            // Tabela: estado
            "CREATE TABLE `estado` (
              `id_estado` int(11) NOT NULL AUTO_INCREMENT,
              `nome` varchar(50) NOT NULL,
              `sigla` char(2) NOT NULL,
              PRIMARY KEY (`id_estado`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;",
            
            // Tabela: cidade
            "CREATE TABLE `cidade` (
              `id_cidade` int(11) NOT NULL AUTO_INCREMENT,
              `nome` varchar(100) NOT NULL,
              `id_estado` int(11) NOT NULL,
              PRIMARY KEY (`id_cidade`),
              KEY `fk_cidade_estado` (`id_estado`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;",
            
            // Tabela: pessoa
            "CREATE TABLE `pessoa` (
              `id_pessoa` int(11) NOT NULL AUTO_INCREMENT,
              `tipo_pessoa` enum('FISICA','JURIDICA') NOT NULL,
              `nome` varchar(100) NOT NULL,
              `cpf_cnpj` varchar(20) NOT NULL,
              `rg_ie` varchar(20) DEFAULT NULL,
              `telefone` varchar(20) DEFAULT NULL,
              `cep` varchar(10) DEFAULT NULL,
              `endereco` varchar(255) DEFAULT NULL,
              `numero` varchar(10) DEFAULT NULL,
              `bairro` varchar(100) DEFAULT NULL,
              `id_cidade` int(11) NOT NULL,
              `status` tinyint(1) DEFAULT 1,
              `created_at` datetime DEFAULT current_timestamp(),
              `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
              PRIMARY KEY (`id_pessoa`),
              UNIQUE KEY `cpf_cnpj` (`cpf_cnpj`),
              KEY `fk_pessoa_cidade` (`id_cidade`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;",
            
            // Tabela: cliente
            "CREATE TABLE `cliente` (
              `id_pessoa` int(11) NOT NULL,
              `data_ultima_interacao` datetime DEFAULT NULL,
              PRIMARY KEY (`id_pessoa`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;",
            
            // Tabela: config_sistema
            "CREATE TABLE `config_sistema` (
              `id_config` int(11) NOT NULL AUTO_INCREMENT,
              `nome_fantasia` varchar(100) DEFAULT NULL,
              `razao_social` varchar(100) DEFAULT NULL,
              `cnpj` varchar(20) DEFAULT NULL,
              `telefone` varchar(20) DEFAULT NULL,
              `email` varchar(100) DEFAULT NULL,
              `endereco_completo` varchar(255) DEFAULT NULL,
              `logo_caminho` varchar(255) DEFAULT NULL,
              `termo_compromisso_texto` text DEFAULT NULL,
              `prazo_maximo_retirada` int(11) DEFAULT 90,
              PRIMARY KEY (`id_config`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;",
            
            // Tabela: equipamento
            "CREATE TABLE `equipamento` (
              `id_equipamento` int(11) NOT NULL AUTO_INCREMENT,
              `aparelho` varchar(100) NOT NULL,
              `marca` varchar(50) DEFAULT NULL,
              `modelo` varchar(50) DEFAULT NULL,
              `numero_serie` varchar(100) DEFAULT NULL,
              `id_cliente` int(11) NOT NULL,
              PRIMARY KEY (`id_equipamento`),
              KEY `fk_equipamento_cliente` (`id_cliente`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;",
            
            // Tabela: funcionario
            "CREATE TABLE `funcionario` (
              `id_pessoa` int(11) NOT NULL,
              `cargo` varchar(50) DEFAULT NULL,
              `salario` decimal(10,2) DEFAULT 0.00,
              `comissao_os` tinyint(1) DEFAULT 0,
              `valor_comissao_os` decimal(10,2) DEFAULT 0.00,
              `comissao_mo` tinyint(1) DEFAULT 0,
              `valor_comissao_mo` decimal(10,2) DEFAULT 0.00,
              PRIMARY KEY (`id_pessoa`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;",
            
            // Tabela: folha_pagto
            "CREATE TABLE `folha_pagto` (
              `id_folha` int(11) NOT NULL AUTO_INCREMENT,
              `id_funcionario` int(11) NOT NULL,
              `mes_referencia` int(11) NOT NULL,
              `ano_referencia` int(11) NOT NULL,
              `valor_salario_base` decimal(10,2) DEFAULT NULL,
              `total_comissao` decimal(10,2) DEFAULT NULL,
              `valor_total_receber` decimal(10,2) DEFAULT NULL,
              `pago` tinyint(1) DEFAULT 0,
              PRIMARY KEY (`id_folha`),
              KEY `fk_folha_funcionario` (`id_funcionario`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;",
            
            // Tabela: os
            "CREATE TABLE `os` (
              `id_os` int(11) NOT NULL AUTO_INCREMENT,
              `data_abertura` datetime NOT NULL,
              `id_cliente` int(11) NOT NULL,
              `id_equipamento` int(11) NOT NULL,
              `id_tecnico` int(11) NOT NULL,
              `descricao_problema` text DEFAULT NULL,
              `estado_aparelho_entrada` text DEFAULT NULL,
              `diagnostico` text DEFAULT NULL,
              `valor_pecas` decimal(10,2) DEFAULT 0.00,
              `valor_mao_obra` decimal(10,2) DEFAULT 0.00,
              `valor_total` decimal(10,2) DEFAULT 0.00,
              `status` enum('aberta','em_andamento','finalizada','cancelada') NOT NULL,
              `termo_compromisso_gerado` tinyint(1) DEFAULT 0,
              `created_at` datetime DEFAULT current_timestamp(),
              `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
              PRIMARY KEY (`id_os`),
              KEY `fk_os_cliente` (`id_cliente`),
              KEY `fk_os_equipamento` (`id_equipamento`),
              KEY `fk_os_tecnico` (`id_tecnico`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;",
            
            // Tabela: historico_os
            "CREATE TABLE `historico_os` (
              `id_historico` int(11) NOT NULL AUTO_INCREMENT,
              `id_os` int(11) NOT NULL,
              `data_modificacao` datetime DEFAULT current_timestamp(),
              `status_anterior` varchar(50) DEFAULT NULL,
              `status_novo` varchar(50) DEFAULT NULL,
              `observacao_interna` text DEFAULT NULL,
              PRIMARY KEY (`id_historico`),
              KEY `fk_historico_os` (`id_os`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;",
            
            // Tabela: produto
            "CREATE TABLE `produto` (
              `id_produto` int(11) NOT NULL AUTO_INCREMENT,
              `descricao` varchar(100) NOT NULL,
              `custo` decimal(10,2) DEFAULT 0.00,
              `lucro_bruto` decimal(10,2) DEFAULT 0.00,
              `valor` decimal(10,2) NOT NULL,
              `estoque` int(11) DEFAULT 0,
              `created_at` datetime DEFAULT current_timestamp(),
              `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
              PRIMARY KEY (`id_produto`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;",
            
            // Tabela: item_os
            "CREATE TABLE `item_os` (
              `id_item` int(11) NOT NULL AUTO_INCREMENT,
              `id_os` int(11) NOT NULL,
              `id_produto` int(11) NOT NULL,
              `quantidade` int(11) NOT NULL,
              `valor_unitario` decimal(10,2) NOT NULL,
              `subtotal` decimal(10,2) NOT NULL,
              PRIMARY KEY (`id_item`),
              KEY `fk_item_os` (`id_os`),
              KEY `fk_item_produto` (`id_produto`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;",
            
            // Tabela: usuario
            "CREATE TABLE `usuario` (
              `id_pessoa` int(11) NOT NULL,
              `login` varchar(50) NOT NULL,
              `senha` varchar(255) NOT NULL,
              `ativo` tinyint(1) DEFAULT 1,
              PRIMARY KEY (`id_pessoa`),
              UNIQUE KEY `login` (`login`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;",
            
            // Adicionar restrições de Foreign Key (Chaves Estrangeiras)
            "ALTER TABLE `cidade` ADD CONSTRAINT `fk_cidade_estado` FOREIGN KEY (`id_estado`) REFERENCES `estado` (`id_estado`);",
            "ALTER TABLE `cliente` ADD CONSTRAINT `fk_cliente_pessoa` FOREIGN KEY (`id_pessoa`) REFERENCES `pessoa` (`id_pessoa`) ON DELETE CASCADE;",
            "ALTER TABLE `equipamento` ADD CONSTRAINT `fk_equipamento_cliente` FOREIGN KEY (`id_cliente`) REFERENCES `cliente` (`id_pessoa`);",
            "ALTER TABLE `folha_pagto` ADD CONSTRAINT `fk_folha_funcionario` FOREIGN KEY (`id_funcionario`) REFERENCES `funcionario` (`id_pessoa`);",
            "ALTER TABLE `funcionario` ADD CONSTRAINT `fk_funcionario_pessoa` FOREIGN KEY (`id_pessoa`) REFERENCES `pessoa` (`id_pessoa`) ON DELETE CASCADE;",
            "ALTER TABLE `historico_os` ADD CONSTRAINT `fk_historico_os` FOREIGN KEY (`id_os`) REFERENCES `os` (`id_os`) ON DELETE CASCADE;",
            "ALTER TABLE `item_os` ADD CONSTRAINT `fk_item_os` FOREIGN KEY (`id_os`) REFERENCES `os` (`id_os`) ON DELETE CASCADE, ADD CONSTRAINT `fk_item_produto` FOREIGN KEY (`id_produto`) REFERENCES `produto` (`id_produto`);",
            "ALTER TABLE `os` ADD CONSTRAINT `fk_os_cliente` FOREIGN KEY (`id_cliente`) REFERENCES `cliente` (`id_pessoa`), ADD CONSTRAINT `fk_os_equipamento` FOREIGN KEY (`id_equipamento`) REFERENCES `equipamento` (`id_equipamento`), ADD CONSTRAINT `fk_os_tecnico` FOREIGN KEY (`id_tecnico`) REFERENCES `funcionario` (`id_pessoa`);",
            "ALTER TABLE `pessoa` ADD CONSTRAINT `fk_pessoa_cidade` FOREIGN KEY (`id_cidade`) REFERENCES `cidade` (`id_cidade`);",
            "ALTER TABLE `usuario` ADD CONSTRAINT `fk_usuario_pessoa` FOREIGN KEY (`id_pessoa`) REFERENCES `pessoa` (`id_pessoa`) ON DELETE CASCADE;"
        ];

        // Executar todas as estruturas de base de dados
        foreach ($sqlQueries as $query) {
            $pdoInit->exec($query);
        }

        // Reativar restrições de chaves estrangeiras
        $pdoInit->exec("SET FOREIGN_KEY_CHECKS = 1;");

        // 4. Inserção automática de Dados Básicos (Estados, Cidades, Pessoa, Funcionário e Utilizador Admin)
        // 4.1. Estado Padrão (Mato Grosso do Sul)
        $stmtEstado = $pdoInit->prepare("INSERT INTO `estado` (`id_estado`, `nome`, `sigla`) VALUES (1, 'Mato Grosso do Sul', 'MS') ON DUPLICATE KEY UPDATE `id_estado`=1;");
        $stmtEstado->execute();

        // 4.2. Cidade Padrão (Dourados)
        $stmtCidade = $pdoInit->prepare("INSERT INTO `cidade` (`id_cidade`, `nome`, `id_estado`) VALUES (1, 'Dourados', 1) ON DUPLICATE KEY UPDATE `id_cidade`=1;");
        $stmtCidade->execute();

        // 4.3. Pessoa Padrão para o Administrador
        $stmtPessoa = $pdoInit->prepare("INSERT INTO `pessoa` 
            (`id_pessoa`, `tipo_pessoa`, `nome`, `cpf_cnpj`, `rg_ie`, `telefone`, `cep`, `endereco`, `numero`, `bairro`, `id_cidade`, `status`) 
            VALUES (1, 'FISICA', 'Administrador do Sistema', '000.000.000-00', '0000000', '(67) 99999-9999', '79800-000', 'Rua Central de Testes', '100', 'Centro', 1, 1)
            ON DUPLICATE KEY UPDATE `id_pessoa`=1;");
        $stmtPessoa->execute();

        // 4.4. Funcionário Padrão (Administrador / Supervisor)
        $stmtFunc = $pdoInit->prepare("INSERT INTO `funcionario` 
            (`id_pessoa`, `cargo`, `salario`, `comissao_os`, `valor_comissao_os`, `comissao_mo`, `valor_comissao_mo`) 
            VALUES (1, 'Administrador', 3500.00, 1, 10.00, 1, 15.00)
            ON DUPLICATE KEY UPDATE `id_pessoa`=1;");
        $stmtFunc->execute();

        // 4.5. Utilizador de Autenticação com Senha Encriptada
        $adminPasswordHash = password_hash('admin', PASSWORD_DEFAULT);
        $stmtUser = $pdoInit->prepare("INSERT INTO `usuario` 
            (`id_pessoa`, `login`, `senha`, `ativo`) 
            VALUES (1, 'admin', :senha, 1)
            ON DUPLICATE KEY UPDATE `id_pessoa`=1;");
        $stmtUser->execute([':senha' => $adminPasswordHash]);

        // 4.6. Configuração do Sistema Inicial
        $stmtConfig = $pdoInit->prepare("INSERT INTO `config_sistema` 
            (`id_config`, `nome_fantasia`, `razao_social`, `cnpj`, `telefone`, `email`, `endereco_completo`, `termo_compromisso_texto`, `prazo_maximo_retirada`) 
            VALUES (1, 'OS Master Assistência', 'OS Master Soluções em Tecnologia LTDA', '00.000.000/0001-00', '(67) 3411-0000', 'contacto@osmaster.com', 'Av. Presidente Vargas, 1200 - Dourados/MS', 'Autorizo a realização de testes e diagnósticos necessários no meu equipamento. O prazo limite para levantamento do aparelho após notificação do término do serviço é de 90 dias, findo o qual o aparelho será considerado abandonado nos termos da legislação civil vigente.', 90)
            ON DUPLICATE KEY UPDATE `id_config`=1;");
        $stmtConfig->execute();

        $installationSuccess = true;

    } catch (PDOException $e) {
        $installationError = $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Instalador Automático - OS Master</title>
    <style>
        :root {
            --bg-color: #0f172a;
            --card-bg: #1e293b;
            --accent-color: #3b82f6;
            --accent-hover: #2563eb;
            --text-main: #f8fafc;
            --text-muted: #94a3b8;
            --success: #10b981;
            --danger: #ef4444;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            background-color: var(--bg-color);
            color: var(--text-main);
            margin: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            padding: 20px;
        }

        .container {
            width: 100%;
            max-width: 520px;
            background-color: var(--card-bg);
            border-radius: 12px;
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.3), 0 8px 10px -6px rgba(0, 0, 0, 0.3);
            padding: 40px;
            box-sizing: border-box;
            border: 1px solid #334155;
        }

        .header {
            text-align: center;
            margin-bottom: 30px;
        }

        .header h1 {
            font-size: 28px;
            font-weight: 800;
            color: var(--text-main);
            margin: 0 0 10px 0;
            letter-spacing: -0.5px;
        }

        .header p {
            font-size: 14px;
            color: var(--text-muted);
            margin: 0;
        }

        .card {
            background-color: #0f172a;
            border: 1px solid #334155;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 25px;
        }

        .card h2 {
            font-size: 16px;
            font-weight: 600;
            margin: 0 0 10px 0;
            color: var(--accent-color);
        }

        .card ul {
            margin: 0;
            padding-left: 20px;
            font-size: 13.5px;
            color: var(--text-muted);
            line-height: 1.6;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 100%;
            background-color: var(--accent-color);
            color: white;
            border: none;
            border-radius: 6px;
            padding: 14px 20px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            transition: background-color 0.2s;
            text-decoration: none;
        }

        .btn:hover {
            background-color: var(--accent-hover);
        }

        .alert {
            border-radius: 6px;
            padding: 16px;
            font-size: 14px;
            line-height: 1.5;
            margin-bottom: 25px;
        }

        .alert-success {
            background-color: rgba(16, 185, 129, 0.1);
            border: 1px solid var(--success);
            color: #34d399;
        }

        .alert-danger {
            background-color: rgba(239, 68, 68, 0.1);
            border: 1px solid var(--danger);
            color: #f87171;
        }

        .footer {
            text-align: center;
            font-size: 12px;
            color: var(--text-muted);
            margin-top: 30px;
        }
    </style>
</head>
<body>

<div class="container">
    <div class="header">
        <h1>OS Master</h1>
        <p>Instalação do Banco de Dados e Ambiente</p>
    </div>

    <?php if ($installationSuccess): ?>
        <div class="alert alert-success">
            <strong>Instalação concluída com sucesso!</strong><br>
            A base de dados <strong>os_master</strong> e toda a estrutura de tabelas, índices e restrições foram criadas corretamente.
            <br><br>
            <strong>Credenciais de Administrador Padrão:</strong><br>
            • Utilizador: <code style="color: #3b82f6;">admin</code><br>
            • Palavra-passe: <code style="color: #3b82f6;">admin</code>
        </div>
        
        <div class="card">
            <h2>Próximo Passo</h2>
            <p style="font-size: 13.5px; color: var(--text-muted); margin: 0; line-height: 1.5;">
                O ambiente está 100% configurado e pronto para o desenvolvimento. Agora pode aceder à tela de login para validar o acesso.
            </p>
        </div>

        <a href="index.php?page=login" class="btn">Ir para Tela de Login</a>

    <?php else: ?>
        
        <?php if (!empty($installationError)): ?>
            <div class="alert alert-danger">
                <strong>Ocorreu um erro na instalação:</strong><br>
                <?php echo htmlspecialchars($installationError); ?>
            </div>
        <?php endif; ?>

        <p style="font-size: 14px; color: var(--text-muted); line-height: 1.6; margin-bottom: 25px; text-align: center;">
            Este instalador automático irá configurar toda a infraestrutura física necessária para o funcionamento do <strong>OS Master</strong> de forma autónoma.
        </p>

        <div class="card">
            <h2>O que será criado?</h2>
            <ul>
                <li>Base de dados: <code>os_master</code></li>
                <li>Tabelas relacionais com chaves estrangeiras</li>
                <li>Administrador base vinculado à entidade Pessoa</li>
                <li>Configurações iniciais do sistema e termos</li>
                <li>Estados e cidades predefinidos para testes</li>
            </ul>
        </div>

        <form method="POST">
            <input type="hidden" name="action" value="install">
            <button type="submit" class="btn">Executar Instalação e Inicializar Banco</button>
        </form>

    <?php endif; ?>

    <div class="footer">
        OS Master © 2026 • Desenvolvido por Julian C. Braga
    </div>
</div>

</body>
</html>