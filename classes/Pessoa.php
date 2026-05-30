<?php
/**
 * OS Master - Classe de Modelo para a Entidade Pessoa
 * * Esta classe gere as regras de negócio, as validações de integridade (CPF/CNPJ)
 * e a persistência de dados para a tabela base 'pessoa', retendo as máscaras.
 * * @package OSMaster
 * @author Julian C. Braga
 * @version 1.3
 */

class Pessoa {

    /**
     * Valida um número de CPF (Cadastro de Pessoas Físicas)
     * @param string $cpf CPF formatado ou apenas números
     * @return bool True se for válido, False caso contrário
     */
    public static function validarCPF(string $cpf): bool {
        // Extrai apenas os números para a validação matemática
        $cpf = preg_replace('/[^0-9]/', '', $cpf);
        
        // Verifica se possui 11 dígitos ou se é uma sequência repetida conhecida
        if (strlen($cpf) !== 11 || preg_match('/(\d)\1{10}/', $cpf)) {
            return false;
        }
        
        // Algoritmo de validação do primeiro dígito verificador
        for ($t = 9; $t < 11; $t++) {
            for ($d = 0, $c = 0; $c < $t; $c++) {
                $d += $cpf[$c] * (($t + 1) - $c);
            }
            $d = ((10 * $d) % 11) % 10;
            if ($cpf[$c] != $d) {
                return false;
            }
        }
        return true;
    }

    /**
     * Valida um número de CNPJ (Cadastro Nacional da Pessoa Jurídica)
     * @param string $cnpj CNPJ formatado ou apenas números
     * @return bool True se for válido, False caso contrário
     */
    public static function validarCNPJ(string $cnpj): bool {
        // Extrai apenas os números para a validação matemática
        $cnpj = preg_replace('/[^0-9]/', '', $cnpj);
        
        // Verifica se possui 14 dígitos ou se é uma sequência repetida conhecida
        if (strlen($cnpj) !== 14 || preg_match('/(\d)\1{13}/', $cnpj)) {
            return false;
        }
        
        // Algoritmo de validação do primeiro dígito verificador
        $tamanho = strlen($cnpj) - 2;
        $numeros = substr($cnpj, 0, $tamanho);
        $digitos = substr($cnpj, $tamanho);
        $soma = 0;
        $pos = $tamanho - 7;
        
        for ($i = $tamanho; $i >= 1; $i--) {
            $soma += $numeros[$tamanho - $i] * $pos--;
            if ($pos < 2) {
                $pos = 9;
            }
        }
        
        $resultado = $soma % 11 < 2 ? 0 : 11 - ($soma % 11);
        if ($resultado != (int)$digitos[0]) {
            return false;
        }
        
        // Algoritmo de validação do segundo dígito verificador
        $tamanho = $tamanho + 1;
        $numeros = substr($cnpj, 0, $tamanho);
        $soma = 0;
        $pos = $tamanho - 7;
        
        for ($i = $tamanho; $i >= 1; $i--) {
            $soma += $numeros[$tamanho - $i] * $pos--;
            if ($pos < 2) {
                $pos = 9;
            }
        }
        
        $resultado = $soma % 11 < 2 ? 0 : 11 - ($soma % 11);
        if ($resultado != (int)$digitos[1]) {
            return false;
        }
        
        return true;
    }

    /**
     * Regista uma nova pessoa na base de dados
     * @param PDO $pdo Instância ativa do PDO
     * @param array $data Vetor associativo com os dados da pessoa
     * @return int Retorna o id_pessoa gerado após a inserção
     * @throws Exception Se ocorrer um erro ou duplicado de documento
     */
    public static function create(PDO $pdo, array $data): int {
        // Mantém a versão formatada para salvar visualmente na base de dados
        $rawCpfCnpj = !empty($data['cpf_cnpj']) ? trim($data['cpf_cnpj']) : null;
        // Gera a versão limpa apenas com números para validação matemática
        $cpfCnpjClean = $rawCpfCnpj !== null ? preg_replace('/[^0-9]/', '', $rawCpfCnpj) : null;

        if ($cpfCnpjClean !== null && $cpfCnpjClean !== '') {
            // Validação de acordo com o tipo de pessoa
            if ($data['tipo_pessoa'] === 'FISICA') {
                if (!self::validarCPF($cpfCnpjClean)) {
                    throw new Exception("O número de CPF informado é inválido.");
                }
            } else {
                if (!self::validarCNPJ($cpfCnpjClean)) {
                    throw new Exception("O número de CNPJ informado é inválido.");
                }
            }

            // Verifica duplicados ignorando pontos, hifens e barras na comparação
            $sqlCheck = "SELECT cpf_cnpj FROM pessoa 
                         WHERE cpf_cnpj_limpo = :clean_doc
                         OR cpf_cnpj = :raw 
                         OR REPLACE(REPLACE(REPLACE(cpf_cnpj, '.', ''), '-', ''), '/', '') = :clean_legacy";
            
            $stmtCheck = $pdo->prepare($sqlCheck);
            $stmtCheck->execute([
                ':clean_doc' => $cpfCnpjClean,
                ':raw' => $rawCpfCnpj,
                ':clean_legacy' => $cpfCnpjClean
            ]);
            if ($stmtCheck->fetch()) {
                throw new Exception("Já existe uma pessoa registada com este CPF/CNPJ.");
            }
        } else {
            $rawCpfCnpj = null; // Armazena como NULL real para evitar colisão na UNIQUE KEY
        }

        $sql = "INSERT INTO pessoa (
                    tipo_pessoa, nome, cpf_cnpj, cpf_cnpj_limpo, rg_ie, telefone, 
                    cep, endereco, numero, bairro, id_cidade, status
                ) VALUES (
                    :tipo_pessoa, :nome, :cpf_cnpj, :cpf_cnpj_limpo, :rg_ie, :telefone, 
                    :cep, :endereco, :numero, :bairro, :id_cidade, :status
                )";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':tipo_pessoa' => $data['tipo_pessoa'],
            ':nome'        => trim($data['nome']),
            ':cpf_cnpj'    => $rawCpfCnpj, // Salva o valor com a máscara original
            ':cpf_cnpj_limpo' => $cpfCnpjClean ?: null,
            ':rg_ie'       => trim($data['rg_ie'] ?? ''),
            ':telefone'    => trim($data['telefone'] ?? ''),
            ':cep'         => trim($data['cep'] ?? ''),
            ':endereco'    => trim($data['endereco'] ?? ''),
            ':numero'      => trim($data['numero'] ?? ''),
            ':bairro'      => trim($data['bairro'] ?? ''),
            ':id_cidade'   => (int)$data['id_cidade'],
            ':status'      => isset($data['status']) ? (int)$data['status'] : 1
        ]);

        return (int)$pdo->lastInsertId();
    }

    /**
     * Atualiza os dados de uma pessoa existente
     * @param PDO $pdo Instância ativa do PDO
     * @param int $id ID da pessoa a ser atualizada
     * @param array $data Vetor associativo com os novos dados
     * @return bool Retorna true em caso de sucesso
     * @throws Exception Se o documento for inválido ou duplicado
     */
    public static function update(PDO $pdo, int $id, array $data): bool {
        $rawCpfCnpj = !empty($data['cpf_cnpj']) ? trim($data['cpf_cnpj']) : null;
        $cpfCnpjClean = $rawCpfCnpj !== null ? preg_replace('/[^0-9]/', '', $rawCpfCnpj) : null;

        if ($cpfCnpjClean !== null && $cpfCnpjClean !== '') {
            // Validação semântica
            if ($data['tipo_pessoa'] === 'FISICA') {
                if (!self::validarCPF($cpfCnpjClean)) {
                    throw new Exception("O número de CPF informado é inválido.");
                }
            } else {
                if (!self::validarCNPJ($cpfCnpjClean)) {
                    throw new Exception("O número de CNPJ informado é inválido.");
                }
            }

            // Validação de duplicados excluindo o próprio ID (ignora formatação)
            $sqlCheck = "SELECT cpf_cnpj FROM pessoa 
                         WHERE (cpf_cnpj_limpo = :clean_doc OR cpf_cnpj = :raw OR REPLACE(REPLACE(REPLACE(cpf_cnpj, '.', ''), '-', ''), '/', '') = :clean_legacy) 
                         AND id_pessoa != :id";
            
            $stmtCheck = $pdo->prepare($sqlCheck);
            $stmtCheck->execute([
                ':clean_doc' => $cpfCnpjClean,
                ':raw' => $rawCpfCnpj,
                ':clean_legacy' => $cpfCnpjClean,
                ':id' => $id
            ]);
            if ($stmtCheck->fetch()) {
                throw new Exception("Outra pessoa registada já utiliza este CPF/CNPJ.");
            }
        } else {
            $rawCpfCnpj = null; // Armazena como NULL real para evitar conflito de chaves
        }

        $sql = "UPDATE pessoa SET 
                    tipo_pessoa = :tipo_pessoa, 
                    nome = :nome, 
                    cpf_cnpj = :cpf_cnpj, 
                    cpf_cnpj_limpo = :cpf_cnpj_limpo,
                    rg_ie = :rg_ie, 
                    telefone = :telefone, 
                    cep = :cep, 
                    endereco = :endereco, 
                    numero = :numero, 
                    bairro = :bairro, 
                    id_cidade = :id_cidade, 
                    status = :status 
                WHERE id_pessoa = :id";

        $stmt = $pdo->prepare($sql);
        return $stmt->execute([
            ':tipo_pessoa' => $data['tipo_pessoa'],
            ':nome'        => trim($data['nome']),
            ':cpf_cnpj'    => $rawCpfCnpj, // Atualiza mantendo a máscara
            ':cpf_cnpj_limpo' => $cpfCnpjClean ?: null,
            ':rg_ie'       => trim($data['rg_ie'] ?? ''),
            ':telefone'    => trim($data['telefone'] ?? ''),
            ':cep'         => trim($data['cep'] ?? ''),
            ':endereco'    => trim($data['endereco'] ?? ''),
            ':numero'      => trim($data['numero'] ?? ''),
            ':bairro'      => trim($data['bairro'] ?? ''),
            ':id_cidade'   => (int)$data['id_cidade'],
            ':status'      => isset($data['status']) ? (int)$data['status'] : 1,
            ':id'          => $id
        ]);
    }

    /**
     * Altera o status de atividade de uma pessoa (Inativação lógica)
     * @param PDO $pdo Instância ativa do PDO
     * @param int $id ID da pessoa
     * @param int $status Novo status (1 para Ativo, 0 para Inativo)
     * @return bool Retorna true em caso de sucesso
     */
    public static function setStatus(PDO $pdo, int $id, int $status): bool {
        $stmt = $pdo->prepare("UPDATE pessoa SET status = :status WHERE id_pessoa = :id");
        return $stmt->execute([':status' => $status, ':id' => $id]);
    }

    /**
     * Recupera um registo de pessoa pelo seu ID
     * @param PDO $pdo Instância ativa do PDO
     * @param int $id ID da pessoa
     * @return array|false Retorna o vetor associativo ou false se não encontrado
     */
    public static function getById(PDO $pdo, int $id) {
        $stmt = $pdo->prepare("SELECT * FROM pessoa WHERE id_pessoa = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        return $stmt->fetch();
    }

    /**
     * Recupera uma pessoa pelo CPF/CNPJ normalizado, quando informado.
     */
    public static function getByDocumento(PDO $pdo, ?string $cpfCnpj) {
        $cpfCnpjClean = $cpfCnpj !== null ? preg_replace('/[^0-9]/', '', $cpfCnpj) : '';

        if ($cpfCnpjClean === '') {
            return false;
        }

        $stmt = $pdo->prepare("
            SELECT *
            FROM pessoa
            WHERE cpf_cnpj_limpo = :clean_doc
               OR REPLACE(REPLACE(REPLACE(cpf_cnpj, '.', ''), '-', ''), '/', '') = :clean_legacy
            LIMIT 1
        ");
        $stmt->execute([
            ':clean_doc' => $cpfCnpjClean,
            ':clean_legacy' => $cpfCnpjClean
        ]);

        return $stmt->fetch();
    }
}
