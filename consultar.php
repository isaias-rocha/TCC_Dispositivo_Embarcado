<?php
/* =====================================================================
   ENDPOINT 2 - CONSULTA
   GET http://<servidor>/api_arduino/consultar.php

   Retorna as localizacoes gravadas, com filtros e paginacao.

   Header obrigatorio:  X-API-Key: <API_KEY definida abaixo>

   Parametros (todos opcionais):
     device_uid = ESP32-A1B2C3D4E5F6   filtra por dispositivo
     de         = 2026-08-30           inicio do periodo (data ou data+hora)
     ate        = 2026-08-31           fim do periodo
     pagina     = 1                    padrao 1
     limite     = 50                   padrao 50, maximo 500
     ordem      = desc                 desc (mais recente) ou asc

   Exemplos:
     consultar.php?limite=10
     consultar.php?device_uid=ESP32-A1B2C3D4E5F6&de=2026-08-30&ate=2026-08-31&ordem=asc
   ===================================================================== */

// ------------------------- CONFIGURACAO -------------------------
const DB_HOST = '127.0.0.1';
const DB_PORT = 3306;
const DB_NAME = 'api_arduino';
const DB_USER = 'root';
const DB_PASS = '';                  // <-- coloque a senha do MySQL aqui

const API_KEY = 'arduino-gps-2026';  // <-- a mesma chave usada em gravar.php ('' desativa)

const TIMEZONE       = 'America/Sao_Paulo';
const DEBUG          = true;         // false em producao (esconde detalhes de erro)
const LIMITE_PADRAO  = 50;
const LIMITE_MAXIMO  = 500;

// ------------------------- INICIALIZACAO -------------------------
date_default_timezone_set(TIMEZONE);
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
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

/**
 * Converte a data recebida no filtro para "Y-m-d H:i:s".
 * Aceita "Y-m-d" (assume 00:00:00 no inicio e 23:59:59 no fim do periodo).
 */
function data_filtro(?string $valor, bool $fimDoDia): string|false|null
{
    if ($valor === null || trim($valor) === '') {
        return null;
    }

    $valor = trim($valor);

    foreach (['Y-m-d H:i:s', 'Y-m-d\TH:i:s', 'Y-m-d H:i', 'd/m/Y H:i:s'] as $formato) {
        $data = DateTime::createFromFormat($formato, $valor);

        if ($data instanceof DateTime && DateTime::getLastErrors() === false) {
            return $data->format('Y-m-d H:i:s');
        }
    }

    // Somente a data: completa a hora conforme a ponta do intervalo
    foreach (['Y-m-d', 'd/m/Y'] as $formato) {
        $data = DateTime::createFromFormat('!' . $formato, $valor);

        if ($data instanceof DateTime && DateTime::getLastErrors() === false) {
            return $data->format('Y-m-d') . ($fimDoDia ? ' 23:59:59' : ' 00:00:00');
        }
    }

    return false;   // formato invalido
}

// ------------------------- 1. METODO -------------------------
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    header('Allow: GET');
    erro(405, 'Metodo nao permitido. Use GET.');
}

// ------------------------- 2. AUTENTICACAO -------------------------
if (API_KEY !== '' && !hash_equals(API_KEY, header_http('X-API-Key'))) {
    erro(401, 'Chave de API ausente ou invalida (header X-API-Key).');
}

// ------------------------- 3. VALIDACAO DOS FILTROS -------------------------
$erros = [];

$deviceUid = trim((string) ($_GET['device_uid'] ?? ''));

if ($deviceUid !== '' && !preg_match('/^[A-Za-z0-9:_\-\.]{3,64}$/', $deviceUid)) {
    $erros['device_uid'] = 'Formato invalido.';
}

$de  = data_filtro($_GET['de']  ?? null, false);
$ate = data_filtro($_GET['ate'] ?? null, true);

if ($de === false) {
    $erros['de'] = 'Data invalida. Use Y-m-d ou Y-m-d H:i:s.';
}

if ($ate === false) {
    $erros['ate'] = 'Data invalida. Use Y-m-d ou Y-m-d H:i:s.';
}

if (is_string($de) && is_string($ate) && $de > $ate) {
    $erros['de'] = 'A data inicial deve ser anterior a data final.';
}

$limite = isset($_GET['limite']) && $_GET['limite'] !== '' ? $_GET['limite'] : LIMITE_PADRAO;
$pagina = isset($_GET['pagina']) && $_GET['pagina'] !== '' ? $_GET['pagina'] : 1;

if (!ctype_digit((string) $limite) || (int) $limite < 1 || (int) $limite > LIMITE_MAXIMO) {
    $erros['limite'] = sprintf('Deve ser um inteiro entre 1 e %d.', LIMITE_MAXIMO);
}

if (!ctype_digit((string) $pagina) || (int) $pagina < 1) {
    $erros['pagina'] = 'Deve ser um inteiro maior ou igual a 1.';
}

// Somente dois valores aceitos: nunca entra na SQL vindo direto do usuario
$ordemBruta = strtolower(trim((string) ($_GET['ordem'] ?? 'desc')));

if (!in_array($ordemBruta, ['asc', 'desc'], true)) {
    $erros['ordem'] = 'Use "asc" ou "desc".';
}

if ($erros !== []) {
    erro(422, 'Parametros invalidos na consulta.', $erros);
}

$limite       = (int) $limite;
$pagina       = (int) $pagina;
$deslocamento = ($pagina - 1) * $limite;
$ordem        = $ordemBruta === 'asc' ? 'ASC' : 'DESC';

// ------------------------- 4. CONSULTA NO BANCO -------------------------
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

// Monta o WHERE apenas com os filtros informados (sempre via parametros nomeados)
$condicoes  = [];
$parametros = [];

if ($deviceUid !== '') {
    $condicoes[]              = 'd.device_uid = :device_uid';
    $parametros['device_uid'] = $deviceUid;
}

if (is_string($de)) {
    $condicoes[]      = 'l.data_hora_gps >= :de';
    $parametros['de'] = $de;
}

if (is_string($ate)) {
    $condicoes[]       = 'l.data_hora_gps <= :ate';
    $parametros['ate'] = $ate;
}

$where = $condicoes === [] ? '' : 'WHERE ' . implode(' AND ', $condicoes);

try {
    // 4.1 - Total de registros que atendem ao filtro (para a paginacao)
    $stmt = $pdo->prepare(
        'SELECT COUNT(*)
           FROM localizacoes l
           JOIN dispositivos d ON d.id = l.dispositivo_id
         ' . $where
    );
    $stmt->execute($parametros);
    $total = (int) $stmt->fetchColumn();

    // 4.2 - Pagina de resultados
    $stmt = $pdo->prepare(
        'SELECT l.id,
                d.device_uid,
                d.nome AS dispositivo_nome,
                l.latitude,
                l.longitude,
                l.altitude_m,
                l.velocidade_kmh,
                l.satelites,
                l.data_hora_gps,
                l.recebido_em
           FROM localizacoes l
           JOIN dispositivos d ON d.id = l.dispositivo_id
         ' . $where . '
          ORDER BY l.data_hora_gps ' . $ordem . ', l.id ' . $ordem . '
          LIMIT :limite OFFSET :deslocamento'
    );

    foreach ($parametros as $chave => $valor) {
        $stmt->bindValue($chave, $valor);
    }

    $stmt->bindValue('limite', $limite, PDO::PARAM_INT);
    $stmt->bindValue('deslocamento', $deslocamento, PDO::PARAM_INT);
    $stmt->execute();

    $linhas = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log('[api_arduino] consultar: ' . $e->getMessage());
    erro(500, 'Falha ao consultar as localizacoes.', DEBUG ? ['debug' => $e->getMessage()] : []);
}

// ------------------------- 5. RESPOSTA -------------------------
$itens = [];

foreach ($linhas as $linha) {
    $itens[] = [
        'id'               => (int) $linha['id'],
        'device_uid'       => $linha['device_uid'],
        'dispositivo_nome' => $linha['dispositivo_nome'],
        'latitude'         => (float) $linha['latitude'],
        'longitude'        => (float) $linha['longitude'],
        'altitude_m'       => $linha['altitude_m']     !== null ? (float) $linha['altitude_m'] : null,
        'velocidade_kmh'   => $linha['velocidade_kmh'] !== null ? (float) $linha['velocidade_kmh'] : null,
        'satelites'        => $linha['satelites']      !== null ? (int) $linha['satelites'] : null,
        'data_hora_gps'    => $linha['data_hora_gps'],
        'recebido_em'      => $linha['recebido_em'],
    ];
}

responder(200, [
    'sucesso' => true,
    'dados'   => $itens,
    'meta'    => [
        'total'         => $total,
        'pagina'        => $pagina,
        'limite'        => $limite,
        'total_paginas' => (int) ceil($total / $limite),
        'ordem'         => $ordemBruta,
        'filtros'       => [
            'device_uid' => $deviceUid !== '' ? $deviceUid : null,
            'de'         => is_string($de)  ? $de  : null,
            'ate'        => is_string($ate) ? $ate : null,
        ],
    ],
]);
