<?php
/* =====================================================================
   AURORA GPS - ENDPOINT DE CONSULTA (consultar.php)
   =====================================================================
   GET http://<servidor>/api_aurora_gps/consultar.php

   Retorna as localizações gravadas com suporte a filtros por dispositivo,
   período de datas, acionamento do botão SOS, paginação e ordenação.
   ===================================================================== */

// ---------------------------------------------------------------------
// BLOCO 1: CONFIGURAÇÕES E PARÂMETROS PADRÃO
// ---------------------------------------------------------------------
const DB_HOST = '127.0.0.1';
const DB_PORT = 3306;
const DB_NAME = 'api_aurora_gps';
const DB_USER = 'root';
const DB_PASS = '';                 // Senha do MySQL local

const API_KEY = 'aurora-gps-2026'; // Chave de autenticação esperada no Header

const TIMEZONE      = 'America/Sao_Paulo';
const DEBUG         = true;        // Defina como false em produção
const LIMITE_PADRAO = 50;
const LIMITE_MAXIMO = 500;

// ---------------------------------------------------------------------
// BLOCO 2: INICIALIZAÇÃO DE TIMEZONE, CABEÇALHOS E HELPERS
// ---------------------------------------------------------------------
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
 * Função utilitária para padronizar retornos de erro.
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
 * Lê cabeçalhos HTTP com suporte multiplataforma.
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

/**
 * Normaliza e valida strings de data/hora recebidas via query params.
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

    foreach (['Y-m-d', 'd/m/Y'] as $formato) {
        $data = DateTime::createFromFormat('!' . $formato, $valor);

        if ($data instanceof DateTime && DateTime::getLastErrors() === false) {
            return $data->format('Y-m-d') . ($fimDoDia ? ' 23:59:59' : ' 00:00:00');
        }
    }

    return false;
}

// ---------------------------------------------------------------------
// BLOCO 3: VALIDAÇÃO DO MÉTODO HTTP
// ---------------------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    header('Allow: GET');
    erro(405, 'Método não permitido. Utilize o método GET.');
}

// ---------------------------------------------------------------------
// BLOCO 4: AUTENTICAÇÃO DA API (X-API-Key)
// ---------------------------------------------------------------------
if (API_KEY !== '' && !hash_equals(API_KEY, header_http('X-API-Key'))) {
    erro(401, 'Chave de API ausente ou inválida no cabeçalho (X-API-Key).');
}

// ---------------------------------------------------------------------
// BLOCO 5: SANITIZAÇÃO E VALIDAÇÃO DOS FILTROS (QUERY PARAMS)
// ---------------------------------------------------------------------
$erros = [];

// -- Filtro por UID do Dispositivo --
$deviceUid = trim((string) ($_GET['device_uid'] ?? ''));

if ($deviceUid !== '' && !preg_match('/^[A-Za-z0-9:_\-\.]{3,64}$/', $deviceUid)) {
    $erros['device_uid'] = 'Formato inválido.';
}

// -- Filtros de Intervalo de Datas (de / ate) --
$de  = data_filtro($_GET['de']  ?? null, false);
$ate = data_filtro($_GET['ate'] ?? null, true);

if ($de === false) {
    $erros['de'] = 'Data inicial inválida. Formatos aceitos: Y-m-d ou Y-m-d H:i:s.';
}

if ($ate === false) {
    $erros['ate'] = 'Data final inválida. Formatos aceitos: Y-m-d ou Y-m-d H:i:s.';
}

if (is_string($de) && is_string($ate) && $de > $ate) {
    $erros['de'] = 'A data inicial não pode ser maior que a data final.';
}

// -- Filtro por Acionamento do Botão SOS --
$sosFiltro = null;
if (isset($_GET['sos']) && $_GET['sos'] !== '') {
    $vSos = strtolower(trim((string) $_GET['sos']));
    if (in_array($vSos, ['1', 'true'], true)) {
        $sosFiltro = 1;
    } elseif (in_array($vSos, ['0', 'false'], true)) {
        $sosFiltro = 0;
    } else {
        $erros['sos'] = 'Parâmetro SOS aceita apenas: 1, 0, true ou false.';
    }
}

// -- Paginação e Limite de Registros --
$limite = isset($_GET['limite']) && $_GET['limite'] !== '' ? $_GET['limite'] : LIMITE_PADRAO;
$pagina = isset($_GET['pagina']) && $_GET['pagina'] !== '' ? $_GET['pagina'] : 1;

if (!ctype_digit((string) $limite) || (int) $limite < 1 || (int) $limite > LIMITE_MAXIMO) {
    $erros['limite'] = sprintf('Deve ser um número inteiro de 1 a %d.', LIMITE_MAXIMO);
}

if (!ctype_digit((string) $pagina) || (int) $pagina < 1) {
    $erros['pagina'] = 'Deve ser um número inteiro maior ou igual a 1.';
}

// -- Ordenação dos Dados --
$ordemBruta = strtolower(trim((string) ($_GET['ordem'] ?? 'desc')));

if (!in_array($ordemBruta, ['asc', 'desc'], true)) {
    $erros['ordem'] = 'Aceita apenas "asc" ou "desc".';
}

if ($erros !== []) {
    erro(422, 'Inconsistência nos parâmetros da consulta.', $erros);
}

$limite       = (int) $limite;
$pagina       = (int) $pagina;
$deslocamento = ($pagina - 1) * $limite;
$ordem        = $ordemBruta === 'asc' ? 'ASC' : 'DESC';

// ---------------------------------------------------------------------
// BLOCO 6: CONEXÃO COM O BANCO DE DADOS (PDO)
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
    erro(503, 'Não foi possível conectar ao banco de dados.', DEBUG ? ['debug' => $e->getMessage()] : []);
}

// ---------------------------------------------------------------------
// BLOCO 7: CONSTRUÇÃO DINÂMICA DA CONSULTA SQL COM FILTROS
// ---------------------------------------------------------------------
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

if ($sosFiltro !== null) {
    $condicoes[]       = 'l.sos = :sos';
    $parametros['sos'] = $sosFiltro;
}

$where = $condicoes === [] ? '' : 'WHERE ' . implode(' AND ', $condicoes);

try {
    // 7.1 - Contagem total de registros correspondentes ao filtro
    $stmt = $pdo->prepare(
        'SELECT COUNT(*)
           FROM localizacoes l
           JOIN dispositivos d ON d.id = l.dispositivo_id
         ' . $where
    );
    $stmt->execute($parametros);
    $total = (int) $stmt->fetchColumn();

    // 7.2 - Seleção paginada incluindo Bateria e Alerta SOS
    $stmt = $pdo->prepare(
        'SELECT l.id,
                d.device_uid,
                d.nome AS dispositivo_nome,
                l.latitude,
                l.longitude,
                l.altitude_m,
                l.velocidade_kmh,
                l.satelites,
                l.bateria,
                l.sos,
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
    error_log('[api_aurora_gps] Erro na consulta: ' . $e->getMessage());
    erro(500, 'Falha ao realizar a busca no banco de dados.', DEBUG ? ['debug' => $e->getMessage()] : []);
}

// ---------------------------------------------------------------------
// BLOCO 8: MONTAGEM DA LISTA DE DADOS E RESPOSTA
// ---------------------------------------------------------------------
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
        'bateria'          => $linha['bateria']        !== null ? (int) $linha['bateria'] : null,
        'sos'              => (bool) $linha['sos'],
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
            'sos'        => $sosFiltro !== null ? (bool) $sosFiltro : null,
        ],
    ],
]);
