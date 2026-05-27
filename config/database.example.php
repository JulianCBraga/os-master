<?php
/**
 * OS Master - exemplo de configuracao de banco de dados.
 *
 * Copie este arquivo para config/database.php e ajuste as credenciais do seu
 * ambiente local antes de executar o sistema.
 */

define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'os_master');

$pdo = null;

try {
    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';

    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];

    $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
} catch (PDOException $e) {
    $currentScript = basename($_SERVER['PHP_SELF']);

    if ((int) $e->getCode() === 1049) {
        if ($currentScript !== 'install.php') {
            header('Location: install.php');
            exit;
        }
    } elseif ($currentScript !== 'install.php') {
        die(
            '<h3>Erro Critico de Infraestrutura</h3>' .
            '<p>Nao foi possivel estabelecer ligacao ao servidor de base de dados.</p>' .
            '<p><strong>Detalhes tecnicos:</strong> ' . htmlspecialchars($e->getMessage()) . '</p>' .
            '<p><em>Por favor, certifique-se de que o MySQL esta iniciado no painel do XAMPP.</em></p>'
        );
    }
}
