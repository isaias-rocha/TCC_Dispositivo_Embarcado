<?php
/* =====================================================================
   ENDPOINT 1 - GRAVACAO
   POST http://<servidor>/api_arduino/gravar.php

   Recebe (JSON) a identificacao do projeto Arduino + a leitura do GPS
   e grava nas tabelas `dispositivos` e `localizacoes`.

   Header obrigatorio:  X-API-Key: <API_KEY definida abaixo>

   Corpo esperado:
   {
     "device_uid": "ESP32-A1B2C3D4E5F6",   // obrigatorio
     "nome":       "Rastreador GPS 01",
     "modelo":     "ESP32 + NEO-6M",
     "firmware":   "1.0.0",
     "latitude":   -23.5613,               // obrigatorio
     "longitude":  -46.6565,               // obrigatorio
     "altitude_m":     760.2,
     "velocidade_kmh": 12.4,
     "satelites":      9,
     "data_hora_gps":  "2026-08-30 14:32:10"  // ausente = hora do servidor
   }
   ===================================================================== */

// ------------------------- CONFIGURACAO -------------------------
const DB_HOST = '127.0.0.1';
const DB_PORT = 3306;
const DB_NAME = 'api_arduino';
const DB_USER = 'root';
const DB_PASS = '';                  // <-- coloque a senha do MySQL aqui

const API_KEY = 'arduino-gps-2026';  // <-- use a mesma chave no ESP32 ('' desativa)

const TIMEZONE = 'America/Sao_Paulo';
const DEBUG    = true;               // false em producao (esconde detalhes de erro)

// ------------------------- INICIALIZACAO -------------------------
date_default_timezone_set(TIMEZONE);
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-API-Key');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

/** Responde em JSON e encerra a execucao. */
function responder(int $status, array $corpo): never
{
    http_response_code($status);
    echo json_encode($corpo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** Resposta padrao de erro. */
function erro(int $status, string $mensagem, array $detalhes = []): never
{
    $corpo = ['sucesso' => false, 'erro' => ['mensagem' => $mensagem, 'status' => $status]];

    if ($detalhes !== []) {
        $corpo['erro']['detalhes'] = $detalhes;
    }

    responder($status, $corpo);
}

/** Le um header HTTP de forma compativel com Apache/CGI. */
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

// ------------------------- 1. METODO -------------------------
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    erro(405, 'Metodo nao permitido. Use POST.');
}

// ------------------------- 2. AUTENTICACAO -------------------------
if (API_KEY !== '' && !hash_equals(API_KEY, header_http('X-API-Key'))) {
    erro(401, 'Chave de API ausente ou invalida (header X-API-Key).');
}

// ------------------------- 3. CORPO DA REQUISICAO -------------------------
$bruto = file_get_contents('php://input');

if ($bruto === false || trim($bruto) === '') {
    erro(400, 'Corpo da requisicao vazio. Envie um JSON.');
}

$dados = json_decode($bruto, true);

if (!is_array($dados)) {
    erro(400, 'Corpo da requisicao nao e um JSON valido: ' . json_last_error_msg());
}

// ------------------------- 4. VALIDACAO -------------------------
$erros = [];

// -- Identificacao do dispositivo --
$deviceUid = trim((string) ($dados['device_uid'] ?? ''));

if ($deviceUid === '') {
    $erros['device_uid'] = 'Campo obrigatorio.';
} elseif (!preg_match('/^[A-Za-z0-9:_\-\.]{3,64}$/', $deviceUid)) {
    $erros['device_uid'] = 'Use de 3 a 64 caracteres (letras, numeros, : _ - .).';
}

$nome     = isset($dados['nome'])     ? mb_substr(trim((string) $dados['nome']), 0, 120)   : null;
$modelo   = isset($dados['modelo'])   ? mb_substr(trim((string) $dados['modelo']), 0, 60)  : null;
$firmware = isset($dados['firmware']) ? mb_substr(trim((string) $dados['firmware']), 0, 30) : null;

/** Valida um numero dentro de uma faixa. Retorna null quando ausente/opcional. */
function numero(array $dados, string $campo, bool $obrigatorio, float $min, float $max, array &$erros): ?float
{
    $valor = $dados[$campo] ?? null;

    if ($valor === null || $valor === '') {
        if ($obrigatorio) {
            $erros[$campo] = 'Campo obrigatorio.';
        }

        return null;
    }

    if (!is_numeric($valor)) {
        $erros[$campo] = 'Deve ser um numero.';

        return null;
    }

    $numero = (float) $valor;

    if (!is_finite($numero) || $numero < $min || $numero > $max) {
        $erros[$campo] = sprintf('Deve estar entre %s e %s.', $min, $max);

        return null;
    }

    return $numero;
}

$latitude   = numero($dados, 'latitude',       true,  -90,    90,    $erros);
$longitude  = numero($dados, 'longitude',      true,  -180,   180,   $erros);
$altitude   = numero($dados, 'altitude_m',     false, -500,   20000, $erros);
$velocidade = numero($dados, 'velocidade_kmh', false, 0,      1200,  $erros);
$satelites  = numero($dados, 'satelites',      false, 0,      64,    $erros);

// -- Data/hora do GPS: aceita "Y-m-d H:i:s", ISO-8601 ou timestamp unix --
$dataHoraGps = null;
$dhBruta     = $dados['data_hora_gps'] ?? null;

if ($dhBruta === null || $dhBruta === '') {
    $dataHoraGps = date('Y-m-d H:i:s');                     // ausente: usa a hora do servidor
} elseif (is_numeric($dhBruta) && (int) $dhBruta > 946684800) {
    $dataHoraGps = date('Y-m-d H:i:s', (int) $dhBruta);      // timestamp unix
} else {
    foreach (['Y-m-d H:i:s', 'Y-m-d\TH:i:s', 'Y-m-d\TH:i:sP', 'd/m/Y H:i:s'] as $formato) {
        $data = DateTime::createFromFormat($formato, trim((string) $dhBruta));

        if ($data instanceof DateTime && DateTime::getLastErrors() === false) {
            $dataHoraGps = $data->format('Y-m-d H:i:s');
            break;
        }
    }

    if ($dataHoraGps === null) {
        $erros['data_hora_gps'] = 'Data/hora invalida. Use o formato Y-m-d H:i:s.';
    }
}

if ($erros !== []) {
    erro(422, 'Dados invalidos na requisicao.', $erros);
}

// ------------------------- 5. GRAVACAO NO BANCO -------------------------
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
    error_log('[api_arduino] conexao: ' . $e->getMessage());
    erro(503, 'Nao foi possivel conectar ao banco de dados.', DEBUG ? ['debug' => $e->getMessage()] : []);
}

try {
    $pdo->beginTransaction();

    // 5.1 - Cadastra o dispositivo no primeiro envio; nos demais, apenas atualiza os metadados.
    $stmt = $pdo->prepare(
        'INSERT INTO dispositivos (device_uid, nome, modelo, firmware, ultimo_contato)
              VALUES (:device_uid, :nome, :modelo, :firmware, :ultimo_contato)
         ON DUPLICATE KEY UPDATE
              nome           = COALESCE(VALUES(nome), nome),
              modelo         = COALESCE(VALUES(modelo), modelo),
              firmware       = COALESCE(VALUES(firmware), firmware),
              ultimo_contato = VALUES(ultimo_contato),
              id             = LAST_INSERT_ID(id)'
    );

    $stmt->execute([
        'device_uid'     => $deviceUid,
        'nome'           => $nome,
        'modelo'         => $modelo,
        'firmware'       => $firmware,
        'ultimo_contato' => $dataHoraGps,
    ]);

    $dispositivoId = (int) $pdo->lastInsertId();
    $novoCadastro  = $stmt->rowCount() === 1;   // 1 = INSERT, 2 = UPDATE

    // Bloqueia dispositivos desativados manualmente no banco (ativo = 0)
    $stmt = $pdo->prepare('SELECT ativo FROM dispositivos WHERE id = :id');
    $stmt->execute(['id' => $dispositivoId]);

    if ((int) $stmt->fetchColumn() !== 1) {
        $pdo->rollBack();
        erro(403, sprintf('Dispositivo "%s" esta inativo.', $deviceUid));
    }

    // 5.2 - Grava a leitura. INSERT IGNORE + chave unica (dispositivo_id, data_hora_gps)
    //       fazem reenvios do mesmo ponto serem ignorados em vez de duplicados.
    $stmt = $pdo->prepare(
        'INSERT IGNORE INTO localizacoes
             (dispositivo_id, latitude, longitude, altitude_m, velocidade_kmh,
              satelites, data_hora_gps, ip_origem)
         VALUES
             (:dispositivo_id, :latitude, :longitude, :altitude_m, :velocidade_kmh,
              :satelites, :data_hora_gps, :ip_origem)'
    );

    $stmt->execute([
        'dispositivo_id' => $dispositivoId,
        'latitude'       => $latitude,
        'longitude'      => $longitude,
        'altitude_m'     => $altitude,
        'velocidade_kmh' => $velocidade,
        'satelites'      => $satelites !== null ? (int) $satelites : null,
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

    error_log('[api_arduino] gravar: ' . $e->getMessage());
    erro(500, 'Falha ao gravar a localizacao.', DEBUG ? ['debug' => $e->getMessage()] : []);
}

// ------------------------- 6. RESPOSTA -------------------------
responder($duplicada ? 200 : 201, [
    'sucesso' => true,
    'dados'   => [
        'dispositivo' => [
            'id'         => $dispositivoId,
            'device_uid' => $deviceUid,
            'nome'       => $nome,
            'novo'       => $novoCadastro,
        ],
        'localizacao' => [
            'id'             => $localizacaoId,
            'latitude'       => $latitude,
            'longitude'      => $longitude,
            'altitude_m'     => $altitude,
            'velocidade_kmh' => $velocidade,
            'satelites'      => $satelites !== null ? (int) $satelites : null,
            'data_hora_gps'  => $dataHoraGps,
        ],
        'duplicada' => $duplicada,   // true = ponto ja existia, nada foi gravado
    ],
]);
