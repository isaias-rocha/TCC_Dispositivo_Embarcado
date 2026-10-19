<?php
/* =====================================================================
   AURORA GPS - ENDPOINT DE GRAVAÇÃO (gravar.php)
   =====================================================================
   POST http://<servidor>/api_aurora_gps/gravar.php

   Recebe a leitura de telemetria e o estado do botão SOS transmitidos
   pelo hardware ESP32 ou via requisição HTTP, salva o estado do 
   dispositivo e registra a localização histórica no banco MySQL.
   ===================================================================== */

// ---------------------------------------------------------------------
// BLOCO 1: CONFIGURAÇÕES GERAIS E CREDENCIAIS DO BANCO
// ---------------------------------------------------------------------
const DB_HOST = '127.0.0.1';
const DB_PORT = 3306;
const DB_NAME = 'api_aurora_gps';
const DB_USER = 'root';
const DB_PASS = '';                 // Senha do MySQL local

const API_KEY = 'aurora-gps-2026'; // Chave de autenticação esperada no Header (X-API-Key)

const TIMEZONE = 'America/Sao_Paulo';
const DEBUG    = true;              // Defina como false em ambiente de produção

// ---------------------------------------------------------------------
// BLOCO 2: INICIALIZAÇÃO DE TIMEZONE, CABEÇALHOS CORS E HELPERS
// ---------------------------------------------------------------------
date_default_timezone_set(TIMEZONE);
ini_set('display_errors', '0');

// Configuração de cabeçalhos HTTP para API RESTful
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-API-Key');

// Trata requisições Preflight do navegador (OPTIONS)
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

/**
 * Função utilitária para emitir a resposta JSON e encerrar a execução.
 */
function responder(int $status, array $corpo): never
{
    http_response_code($status);
    echo json_encode($corpo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Funçao utilitária para padronizar retornos de erro HTTP.
 */
function erro(int $status, string $mensagem, array $detalhes = []): never
{
    $corpo = [
        'sucesso' => false,
        'erro' => [
            'mensagem' => $mensagem,
            'status'   => $status
        ]
    ];

    if ($detalhes !== []) {
        $corpo['erro']['detalhes'] = $detalhes;
    }

    responder($status, $corpo);
}

/**
 * Lê cabeçalhos HTTP garantindo compatibilidade entre Apache/Nginx/FastCGI.
 */
function header_http(string $nome): string
{
    $chave = 'HTTP_' . strtoupper(str_replace('-', '_', $nome));

    if (isset($_SERVER[$chave])) {
        return (string) $_SERVER[$chave];
    }

    if (function_exists('apache_request_headers')) {
        foreach (apache_request_headers() as $k => $v) {
            if (strcasecmp($k, $nome) === 0) {
                return (string) $v;
            }
        }
    }

    return '';
}

// ---------------------------------------------------------------------
// BLOCO 3: VALIDAÇÃO DO MÉTODO DE REQUISIÇÃO HTTP
// ---------------------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    erro(405, 'Método não permitido. Utilize o método POST.');
}

// ---------------------------------------------------------------------
// BLOCO 4: AUTENTICAÇÃO VIA HEADER (X-API-Key)
// ---------------------------------------------------------------------
if (API_KEY !== '' && !hash_equals(API_KEY, header_http('X-API-Key'))) {
    erro(401, 'Chave de API ausente ou inválida no cabeçalho (X-API-Key).');
}

// ---------------------------------------------------------------------
// BLOCO 5: DECODIFICAÇÃO DO CORPO DA REQUISIÇÃO (PAYLOAD JSON)
// ---------------------------------------------------------------------
$bruto = file_get_contents('php://input');

if ($bruto === false || trim($bruto) === '') {
    erro(400, 'Corpo da requisição vazio. Envie um payload no formato JSON.');
}

$dados = json_decode($bruto, true);

if (!is_array($dados)) {
    erro(400, 'JSON inválido recebido no corpo da requisição: ' . json_last_error_msg());
}

// ---------------------------------------------------------------------
// BLOCO 6: SANITIZAÇÃO E VALIDAÇÃO DOS CAMPOS RECEBIDOS
// ---------------------------------------------------------------------
$erros = [];

// -- Identificação e Metadados do Hardware --
$deviceUid = trim((string) ($dados['device_uid'] ?? ''));

if ($deviceUid === '') {
    $erros['device_uid'] = 'Campo obrigatório.';
} elseif (!preg_match('/^[A-Za-z0-9:_\-\.]{3,64}$/', $deviceUid)) {
    $erros['device_uid'] = 'Deve ter de 3 a 64 caracteres (A-Z, 0-9, :, _, -, .).';
}

$nome     = isset($dados['nome'])     ? mb_substr(trim((string) $dados['nome']), 0, 120)   : null;
$modelo   = isset($dados['modelo'])   ? mb_substr(trim((string) $dados['modelo']), 0, 60)  : null;
$firmware = isset($dados['firmware']) ? mb_substr(trim((string) $dados['firmware']), 0, 30) : null;

/**
 * Valida se um valor numérico está dentro de um limite esperado.
 */
function numero(array $dados, string $campo, bool $obrigatorio, float $min, float $max, array &$erros): ?float
{
    $valor = $dados[$campo] ?? null;

    if ($valor === null || $valor === '') {
        if ($obrigatorio) {
            $erros[$campo] = 'Campo obrigatório.';
        }
        return null;
    }

    if (!is_numeric($valor)) {
        $erros[$campo] = 'Deve ser um número válido.';
        return null;
    }

    $numero = (float) $valor;

    if (!is_finite($numero) || $numero < $min || $numero > $max) {
        $erros[$campo] = sprintf('Deve estar dentro da faixa de %s até %s.', $min, $max);
        return null;
    }

    return $numero;
}

// -- Telemetria do GPS e do Hardware --
$latitude   = numero($dados, 'latitude',       true,  -90,    90,    $erros);
$longitude  = numero($dados, 'longitude',      true,  -180,   180,   $erros);
$altitude   = numero($dados, 'altitude_m',     false, -500,   20000, $erros);
$velocidade = numero($dados, 'velocidade_kmh', false, 0,      1200,  $erros);
$satelites  = numero($dados, 'satelites',      false, 0,      64,    $erros);
$bateria    = numero($dados, 'bateria',        false, 0,      100,   $erros);

// -- Tratamento de Estado do Alerta SOS (Conversão para 0 ou 1) --
$sos = 0;
if (isset($dados['sos'])) {
    $valSos = $dados['sos'];
    if (is_bool($valSos)) {
        $sos = $valSos ? 1 : 0;
    } elseif (is_numeric($valSos)) {
        $sos = ((int) $valSos === 1) ? 1 : 0;
    } elseif (is_string($valSos)) {
        $sos = (strtolower(trim($valSos)) === 'true' || trim($valSos) === '1') ? 1 : 0;
    }
}

// -- Tratamento da Data/Hora da Leitura do GPS --
$dataHoraGps = null;
$dhBruta     = $dados['data_hora_gps'] ?? null;

if ($dhBruta === null || $dhBruta === '') {
    $dataHoraGps = date('Y-m-d H:i:s'); // Se ausente, atribui o timestamp do servidor
} elseif (is_numeric($dhBruta) && (int) $dhBruta > 946684800) {
    $dataHoraGps = date('Y-m-d H:i:s', (int) $dhBruta); // Timestamp UNIX
} else {
    foreach (['Y-m-d H:i:s', 'Y-m-d\TH:i:s', 'Y-m-d\TH:i:sP', 'd/m/Y H:i:s'] as $formato) {
        $data = DateTime::createFromFormat($formato, trim((string) $dhBruta));

        if ($data instanceof DateTime && DateTime::getLastErrors() === false) {
            $dataHoraGps = $data->format('Y-m-d H:i:s');
            break;
        }
    }

    if ($dataHoraGps === null) {
        $erros['data_hora_gps'] = 'Data/Hora inválida. Utilize o formato Y-m-d H:i:s.';
    }
}

// Interrompe se houver falhas de validação nos campos
if ($erros !== []) {
    erro(422, 'Inconsistência nos dados fornecidos.', $erros);
}

// ---------------------------------------------------------------------
// BLOCO 7: CONEXÃO COM O BANCO DE DADOS (PDO)
// ---------------------------------------------------------------------
try {
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', DB_HOST, DB_PORT, DB_NAME),
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
} catch (PDOException $e) {
    error_log('[api_aurora_gps] Erro de conexão: ' . $e->getMessage());
    erro(503, 'Não foi possível conectar ao banco de dados MySQL.', DEBUG ? ['debug' => $e->getMessage()] : []);
}

// ---------------------------------------------------------------------
// BLOCO 8: TRANSAÇÃO BANCO DE DADOS (CADASTRO/UPDATE + INSERÇÃO)
// ---------------------------------------------------------------------
try {
    $pdo->beginTransaction();

    // 8.1 - Upsert na tabela de Dispositivos (Cadastra se novo, ou atualiza o último contato e bateria)
    $stmt = $pdo->prepare(
        'INSERT INTO dispositivos (device_uid, nome, modelo, firmware, bateria_atual, ultimo_contato)
              VALUES (:device_uid, :nome, :modelo, :firmware, :bateria_atual, :ultimo_contato)
         ON DUPLICATE KEY UPDATE
              nome           = COALESCE(VALUES(nome), nome),
              modelo         = COALESCE(VALUES(modelo), modelo),
              firmware       = COALESCE(VALUES(firmware), firmware),
              bateria_atual  = COALESCE(VALUES(bateria_atual), bateria_atual),
              ultimo_contato = VALUES(ultimo_contato),
              id             = LAST_INSERT_ID(id)'
    );

    $stmt->execute([
        'device_uid'     => $deviceUid,
        'nome'           => $nome,
        'modelo'         => $modelo,
        'firmware'       => $firmware,
        'bateria_atual'  => $bateria !== null ? (int) $bateria : null,
        'ultimo_contato' => $dataHoraGps,
    ]);

    $dispositivoId = (int) $pdo->lastInsertId();
    $novoCadastro  = $stmt->rowCount() === 1;

    // Verificação se o dispositivo foi bloqueado manualmente (ativo = 0)
    $stmt = $pdo->prepare('SELECT ativo FROM dispositivos WHERE id = :id');
    $stmt->execute(['id' => $dispositivoId]);

    if ((int) $stmt->fetchColumn() !== 1) {
        $pdo->rollBack();
        erro(403, sprintf('O dispositivo "%s" está desativado na base de dados.', $deviceUid));
    }

    // 8.2 - Inserção da Leitura de Localização e Emergência
    $stmt = $pdo->prepare(
        'INSERT IGNORE INTO localizacoes
             (dispositivo_id, latitude, longitude, altitude_m, velocidade_kmh,
              satelites, bateria, sos, data_hora_gps, ip_origem)
         VALUES
             (:dispositivo_id, :latitude, :longitude, :altitude_m, :velocidade_kmh,
              :satelites, :bateria, :sos, :data_hora_gps, :ip_origem)'
    );

    $stmt->execute([
        'dispositivo_id' => $dispositivoId,
        'latitude'       => $latitude,
        'longitude'      => $longitude,
        'altitude_m'     => $altitude,
        'velocidade_kmh' => $velocidade,
        'satelites'      => $satelites !== null ? (int) $satelites : null,
        'bateria'        => $bateria !== null ? (int) $bateria : null,
        'sos'            => $sos,
        'data_hora_gps'  => $dataHoraGps,
        'ip_origem'      => $_SERVER['REMOTE_ADDR'] ?? null,
    ]);

    $duplicada     = $stmt->rowCount() === 0;
    $localizacaoId = $duplicada ? null : (int) $pdo->lastInsertId();

    $pdo->commit();
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('[api_aurora_gps] Erro ao gravar: ' . $e->getMessage());
    erro(500, 'Falha ao salvar o ponto de localização no banco.', DEBUG ? ['debug' => $e->getMessage()] : []);
}

// ---------------------------------------------------------------------
// BLOCO 9: RESPOSTA HTTP DE SUCESSO
// ---------------------------------------------------------------------
responder($duplicada ? 200 : 201, [
    'sucesso' => true,
    'dados'   => [
        'dispositivo' => [
            'id'            => $dispositivoId,
            'device_uid'    => $deviceUid,
            'nome'          => $nome,
            'bateria_atual' => $bateria !== null ? (int) $bateria : null,
            'novo'          => $novoCadastro,
        ],
        'localizacao' => [
            'id'             => $localizacaoId,
            'latitude'       => $latitude,
            'longitude'      => $longitude,
            'altitude_m'     => $altitude,
            'velocidade_kmh' => $velocidade,
            'satelites'      => $satelites !== null ? (int) $satelites : null,
            'bateria'        => $bateria !== null ? (int) $bateria : null,
            'sos'            => (bool) $sos,
            'data_hora_gps'  => $dataHoraGps,
        ],
        'duplicada' => $duplicada,
    ],
]);
