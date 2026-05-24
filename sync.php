<?php
/**
 * 全量数据同步
 * 仅同步数据表：结构 + 数据 + 触发器
 * 不同步视图、存储过程
 */
session_start();
header('Content-Type: application/json; charset=utf-8');

$action = $_POST['action'] ?? '';

/** 通用 JSON 响应 */
function jsonResponse($data) {
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/** 按配置连接 MySQL，返回 [PDO|null, error_string|null] */
function connectMySQL($config) {
    try {
        $dsn = "mysql:host={$config['host']};port={$config['port']};dbname={$config['dbname']};charset=utf8mb4";
        $pdo = new PDO($dsn, $config['user'], $config['pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        $pdo->exec("SET NAMES utf8mb4");
        return [$pdo, null];
    } catch (PDOException $e) {
        return [null, '连接失败: ' . $e->getMessage()];
    }
}

/** 从 POST 提取连接配置 */
function getConfigFromPost($prefix) {
    return [
        'host'   => $_POST[$prefix . '_host']   ?? '127.0.0.1',
        'port'   => $_POST[$prefix . '_port']   ?? '3306',
        'user'   => $_POST[$prefix . '_user']   ?? 'root',
        'pass'   => $_POST[$prefix . '_pass']   ?? '',
        'dbname' => $_POST[$prefix . '_dbname'] ?? '',
    ];
}

// ============================================================
// 列出源库所有数据表
// ============================================================
if ($action === 'list_tables') {
    $cfg = getConfigFromPost('src');
    if (empty($cfg['dbname'])) jsonResponse(['success' => false, 'message' => '请指定源数据库名']);
    list($pdo, $err) = connectMySQL($cfg);
    if ($err) jsonResponse(['success' => false, 'message' => '源库: ' . $err]);
    $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    jsonResponse(['success' => true, 'tables' => $tables]);
}

// ============================================================
// 全量同步
// ============================================================
if ($action === 'sync') {
    set_time_limit(3600);

    $srcCfg = getConfigFromPost('src');
    $tgtCfg = getConfigFromPost('tgt');

    if (empty($srcCfg['dbname']) || empty($tgtCfg['dbname'])) {
        jsonResponse(['success' => false, 'message' => '请指定源数据库和目标数据库名']);
    }
    if ($srcCfg['host'] === $tgtCfg['host'] && $srcCfg['port'] === $tgtCfg['port'] && $srcCfg['dbname'] === $tgtCfg['dbname']) {
        jsonResponse(['success' => false, 'message' => '源库与目标库不能相同']);
    }

    list($srcPdo, $err) = connectMySQL($srcCfg);
    if ($err) jsonResponse(['success' => false, 'message' => '源库: ' . $err]);

    list($tgtPdo, $err) = connectMySQL($tgtCfg);
    if ($err) jsonResponse(['success' => false, 'message' => '目标库: ' . $err]);

    // ---------- 确定要同步的表 ----------
    $mode           = $_POST['mode'] ?? 'all';
    $selectedTables = isset($_POST['tables']) ? json_decode($_POST['tables'], true) : [];

    if ($mode === 'all') {
        $tables = $srcPdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    } else {
        $tables = $selectedTables;
    }

    if (empty($tables)) {
        jsonResponse(['success' => false, 'message' => '没有可同步的表']);
    }

    $results      = [];
    $totalSuccess = 0;
    $totalFail    = 0;

    foreach ($tables as $table) {
        $tr = ['table' => $table, 'status' => 'success', 'rows' => 0, 'message' => ''];
        try {
            // 1. 获取源表 SHOW CREATE TABLE
            $cr = $srcPdo->query("SHOW CREATE TABLE `{$table}`")->fetch();
            $createSql = $cr['Create Table'] ?? '';
            if (empty($createSql)) {
                $tr['status'] = 'warning'; $tr['message'] = '无法获取建表语句';
                $results[] = $tr; continue;
            }

            // 2. 目标库：删旧表 → 建新表 → 清空兜底
            $tgtPdo->exec("DROP TABLE IF EXISTS `{$table}`");
            $tgtPdo->exec($createSql);
            $tgtPdo->exec("TRUNCATE TABLE `{$table}`");

            // 3. 获取源表列名
            $columns = $srcPdo->query("SHOW COLUMNS FROM `{$table}`")->fetchAll(PDO::FETCH_COLUMN);
            if (empty($columns)) {
                $tr['status'] = 'warning'; $tr['message'] = '表无列'; $results[] = $tr; continue;
            }

            $colList = '`' . implode('`, `', $columns) . '`';

            // 4. 分批读取源数据 → 批量 INSERT 到目标
            $batchSize = 500;
            $offset    = 0;
            $totalRows = 0;
            $pkCol     = $columns[0];

            while (true) {
                $rows = $srcPdo->query(
                    "SELECT * FROM `{$table}` ORDER BY `{$pkCol}` LIMIT {$batchSize} OFFSET {$offset}"
                )->fetchAll(PDO::FETCH_ASSOC);
                if (empty($rows)) break;

                $plhds = []; $vals = [];
                foreach ($rows as $row) {
                    $rp = [];
                    foreach ($columns as $col) {
                        $rp[]   = '?';
                        $vals[] = $row[$col] ?? null;
                    }
                    $plhds[] = '(' . implode(', ', $rp) . ')';
                }

                $insertSql = "INSERT INTO `{$table}` ({$colList}) VALUES " . implode(', ', $plhds);
                $stmt = $tgtPdo->prepare($insertSql);
                $stmt->execute($vals);

                $totalRows += count($rows);
                $offset    += $batchSize;
            }
            $tr['rows'] = $totalRows;

            // 5. 同步触发器：从源库获取触发器 SQL → 目标库执行
            try {
                $triggers = $srcPdo->query("SHOW TRIGGERS WHERE `Table` = " . $srcPdo->quote($table))->fetchAll(PDO::FETCH_ASSOC);
                foreach ($triggers as $tg) {
                    $tName = $tg['Trigger'];
                    $tgtPdo->exec("DROP TRIGGER IF EXISTS `{$tName}`");
                    $tgRow = $srcPdo->query("SHOW CREATE TRIGGER `{$tName}`")->fetch();
                    $tgSql = $tgRow['SQL Original Statement'] ?? $tgRow['Create Trigger'] ?? '';
                    if (!empty($tgSql)) $tgtPdo->exec($tgSql);
                }
            } catch (Exception $e) {
                // 触发器同步失败不阻断主流程，仅记录
                $tr['message'] = '触发器: ' . $e->getMessage();
            }

            $totalSuccess++;
        } catch (Exception $e) {
            $tr['status']  = 'error';
            $tr['message'] = $e->getMessage();
            $totalFail++;
        }
        $results[] = $tr;
    }

    jsonResponse([
        'success'       => true,
        'total_success' => $totalSuccess,
        'total_fail'    => $totalFail,
        'results'       => $results,
    ]);
}

jsonResponse(['success' => false, 'message' => '未知操作']);
