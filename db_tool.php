<?php
/**
 * 单文件 PHP 数据库管理工具
 * 支持 MySQL、SQLite、MongoDB、Redis
 * 兼容 UTF8mb4，防 SQL 注入和 XSS
 * 
 * 关键设计：
 *   - Session 只存连接配置，不存连接对象，每次请求自动重连
 *   - SQL 输入框不转义，只在页面渲染时转义输出
 */

session_start();

// ============================================================
// 辅助函数
// ============================================================

/** 仅在输出到 HTML 时使用，防止 XSS */
function h($str) {
    return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8');
}

/** 从 $_POST 安全获取值，不做 HTML 转义 */
function post($key, $default = '') {
    return isset($_POST[$key]) ? (string)$_POST[$key] : $default;
}

/** 从 $_GET 安全获取值 */
function get($key, $default = '') {
    return isset($_GET[$key]) ? (string)$_GET[$key] : $default;
}

/** 设置闪存消息 */
function flash($msg, $type = 'success') {
    $_SESSION['_flash'] = ['msg' => $msg, 'type' => $type];
}

/** 获取并清除闪存消息 */
function getFlash() {
    $f = $_SESSION['_flash'] ?? null;
    unset($_SESSION['_flash']);
    return $f;
}

/** 简化版 CSV 导出 */
function exportCsv($filename, $headers, $rows) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF)); // BOM for Excel
    fputcsv($out, $headers);
    foreach ($rows as $row) {
        fputcsv($out, $row);
    }
    fclose($out);
}

/** 简化版 SQL 导出 */
function exportSql($filename, $sql) {
    header('Content-Type: application/sql; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    echo "-- 导出时间: " . date('Y-m-d H:i:s') . "\n";
    echo $sql;
}

// ============================================================
// 数据库连接辅助
// ============================================================

/** 从 Session 配置重连 MySQL */
function mysqlConnect() {
    $c = $_SESSION['mysql_config'] ?? null;
    if (!$c) return [null, '请先连接 MySQL'];
    try {
        $dsn = "mysql:host={$c['host']};port={$c['port']};dbname={$c['dbname']};charset=utf8mb4";
        $pdo = new PDO($dsn, $c['user'], $c['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $pdo->exec("SET NAMES utf8mb4");
        return [$pdo, null];
    } catch (PDOException $e) {
        return [null, 'MySQL 连接失败: ' . $e->getMessage()];
    }
}

/** 从 Session 配置重连 SQLite */
function sqliteConnect() {
    $c = $_SESSION['sqlite_config'] ?? null;
    if (!$c) return [null, '请先打开 SQLite 文件'];
    try {
        $pdo = new PDO("sqlite:{$c['path']}", null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec("PRAGMA journal_mode=WAL");
        return [$pdo, null];
    } catch (PDOException $e) {
        return [null, 'SQLite 连接失败: ' . $e->getMessage()];
    }
}

/** 从 Session 配置重连 MongoDB */
function mongoConnect() {
    $c = $_SESSION['mongo_config'] ?? null;
    if (!$c || !class_exists('MongoDB\Driver\Manager')) {
        return [null, 'MongoDB 扩展未安装或未连接'];
    }
    try {
        $uri = "mongodb://";
        if ($c['user']) $uri .= $c['user'] . ':' . $c['pass'] . '@';
        $uri .= $c['host'] . ':' . $c['port'];
        $manager = new MongoDB\Driver\Manager($uri);
        return [$manager, null];
    } catch (Exception $e) {
        return [null, 'MongoDB 连接失败: ' . $e->getMessage()];
    }
}

/** 从 Session 配置重连 Redis */
function redisConnect() {
    $c = $_SESSION['redis_config'] ?? null;
    if (!$c || !class_exists('Redis')) {
        return [null, 'Redis 扩展未安装或未连接'];
    }
    try {
        $redis = new Redis();
        $timeout = (float)($c['timeout'] ?? 3);
        if ($c['scheme'] === 'tls') {
            $redis->connect('tls://' . $c['host'], (int)$c['port'], $timeout);
        } else {
            $redis->connect($c['host'], (int)$c['port'], $timeout);
        }
        if ($c['pass']) $redis->auth($c['pass']);
        if ($c['db'] > 0) $redis->select((int)$c['db']);
        return [$redis, null];
    } catch (Exception $e) {
        return [null, 'Redis 连接失败: ' . $e->getMessage()];
    }
}

// ============================================================
// 路由 / 动作处理
// ============================================================

$action = get('action', 'home');
$tab    = get('tab', 'mysql');

// 支持 POST action 覆盖
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = post('action', $action);
}

// 断连
if ($action === 'disconnect') {
    $key = $tab . '_config';
    unset($_SESSION[$key]);
    flash('已断开连接');
    header('Location: ?tab=' . $tab);
    exit;
}

// ============================================================
// MYSQL 动作处理
// ============================================================
if ($tab === 'mysql') {
    if ($action === 'mysql_connect') {
        $config = [
            'host'   => post('host', '127.0.0.1'),
            'port'   => post('port', '3306'),
            'user'   => post('user', 'root'),
            'pass'   => post('pass', ''),
            'dbname' => post('dbname', ''),
        ];
        $saveConn = post('save_conn', '0');
        // 测试连接
        try {
            $dsn = "mysql:host={$config['host']};port={$config['port']};charset=utf8mb4";
            if ($config['dbname']) $dsn .= ";dbname={$config['dbname']}";
            new PDO($dsn, $config['user'], $config['pass'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            if ($saveConn === '1') {
                $_SESSION['mysql_config'] = $config;
                flash('MySQL 连接成功，配置已保存');
            } else {
                flash('MySQL 连接成功（未保存配置，刷新后需重新连接）');
            }
        } catch (PDOException $e) {
            flash('MySQL 连接失败: ' . $e->getMessage(), 'error');
        }
        header('Location: ?tab=mysql');
        exit;
    }

    if ($action === 'mysql_createdb') {
        $dbname = trim(post('new_dbname'));
        $charset = post('charset', 'utf8mb4');
        $collation = post('collation', 'utf8mb4_unicode_ci');
        if ($dbname && preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $dbname)) {
            // 连接到不含具体库的服务器
            $c = $_SESSION['mysql_config'] ?? null;
            if ($c) {
                try {
                    $dsn = "mysql:host={$c['host']};port={$c['port']};charset=utf8mb4";
                    $pdo = new PDO($dsn, $c['user'], $c['pass'], [
                        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    ]);
                    $pdo->exec("CREATE DATABASE `{$dbname}` CHARACTER SET {$charset} COLLATE {$collation}");
                    flash("数据库 `{$dbname}` 创建成功 (字符集: {$charset}, 排序规则: {$collation})");
                } catch (Exception $e) {
                    flash("创建数据库失败: " . $e->getMessage(), 'error');
                }
            }
        } else {
            flash("数据库名不合法", 'error');
        }
        header('Location: ?tab=mysql&sub=databases');
        exit;
    }

    if ($action === 'mysql_dropdb') {
        $dbname = post('dbname');
        $c = $_SESSION['mysql_config'] ?? null;
        if ($c && $dbname) {
            try {
                $dsn = "mysql:host={$c['host']};port={$c['port']};charset=utf8mb4";
                $pdo = new PDO($dsn, $c['user'], $c['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
                $pdo->exec("DROP DATABASE `{$dbname}`");
                // 如果删的是当前库，重置
                if ($c['dbname'] === $dbname) {
                    $_SESSION['mysql_config']['dbname'] = '';
                }
                flash("数据库 `{$dbname}` 已删除");
            } catch (Exception $e) {
                flash("删除数据库失败: " . $e->getMessage(), 'error');
            }
        }
        header('Location: ?tab=mysql&sub=databases');
        exit;
    }

    if ($action === 'mysql_use_db') {
        $_SESSION['mysql_config']['dbname'] = post('dbname');
        flash("已切换数据库");
        header('Location: ?tab=mysql');
        exit;
    }

    if ($action === 'mysql_create_table') {
        $tableName = trim(post('table_name'));
        $engine  = post('engine', 'InnoDB');
        $charset = post('charset', 'utf8mb4');
        $collation = post('collation', 'utf8mb4_unicode_ci');
        $comment = post('table_comment', '');

        if (!$tableName || !preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $tableName)) {
            flash("表名不合法", 'error');
            header('Location: ?tab=mysql&sub=create_table');
            exit;
        }

        // Collect field data
        $fieldNames  = $_POST['fld_name'] ?? [];
        $fieldTypes  = $_POST['fld_type'] ?? [];
        $fieldLens   = $_POST['fld_len'] ?? [];
        $fieldDecs   = $_POST['fld_dec'] ?? [];
        $fieldNulls  = $_POST['fld_null'] ?? [];
        $fieldPks    = $_POST['fld_pk'] ?? [];
        $fieldAIs    = $_POST['fld_ai'] ?? [];
        $fieldDefs   = $_POST['fld_default'] ?? [];
        $fieldCmts   = $_POST['fld_comment'] ?? [];

        $colDefs = [];
        $pkCols = [];
        $aiField = null;

        foreach ($fieldNames as $fi => $fn) {
            $fn = trim($fn);
            if ($fn === '') continue;
            $ft = strtoupper($fieldTypes[$fi] ?? 'VARCHAR');
            $fl = $fieldLens[$fi] ?? '';
            $fd = $fieldDecs[$fi] ?? '';
            $notNull = in_array((string)$fi, $fieldNulls, true);
            $isPk = in_array((string)$fi, $fieldPks, true);
            $isAi = in_array((string)$fi, $fieldAIs, true);
            $def = $fieldDefs[$fi] ?? '';
            $cmt = $fieldCmts[$fi] ?? '';

            // AUTO_INCREMENT 必须 NOT NULL，避免 1067 Invalid default value
            if ($isAi) {
                $notNull = true;
                $def = '';          // AI 字段不接受 DEFAULT NULL
            }

            $typeStr = $ft;
            if ($fl !== '' && in_array(strtolower($ft), ['varchar','char','int','bigint','tinyint','smallint','mediumint'])) {
                $typeStr .= "({$fl})";
            } elseif ($fl !== '' && in_array(strtolower($ft), ['decimal','float','double','numeric'])) {
                if ($fd !== '') $typeStr .= "({$fl},{$fd})";
                else $typeStr .= "({$fl})";
            } elseif ($ft === 'ENUM' && $fl !== '') {
                $typeStr .= "({$fl})";
            } elseif ($ft === 'SET' && $fl !== '') {
                $typeStr .= "({$fl})";
            }

            $colDef = "`{$fn}` {$typeStr}";
            if ($notNull) $colDef .= " NOT NULL";
            if ($def !== '') {
                if (strtoupper($def) === 'NULL') $colDef .= " DEFAULT NULL";
                elseif (strtoupper($def) === 'CURRENT_TIMESTAMP') $colDef .= " DEFAULT CURRENT_TIMESTAMP";
                else $colDef .= " DEFAULT '{$def}'";
            } elseif (!$notNull) {
                $colDef .= " DEFAULT NULL";
            }
            if ($isAi) { $colDef .= " AUTO_INCREMENT"; $aiField = $fn; }
            if ($cmt !== '') $colDef .= " COMMENT '{$cmt}'";
            if ($isPk) $pkCols[] = "`{$fn}`";

            $colDefs[] = $colDef;
        }

        if (empty($colDefs)) {
            flash("至少需要添加一个字段", 'error');
            header('Location: ?tab=mysql&sub=create_table');
            exit;
        }

        // Build CREATE TABLE SQL
        $sql = "CREATE TABLE `{$tableName}` (\n  ";
        $sql .= implode(",\n  ", $colDefs);
        if (!empty($pkCols)) {
            $sql .= ",\n  PRIMARY KEY (" . implode(', ', $pkCols) . ")";
        }
        $sql .= "\n) ENGINE={$engine} DEFAULT CHARSET={$charset} COLLATE={$collation}";
        if ($comment !== '') $sql .= " COMMENT='{$comment}'";
        $sql .= ";";

        list($pdo, $err) = mysqlConnect();
        if (!$pdo) { flash($err, 'error'); header('Location: ?tab=mysql&sub=create_table'); exit; }

        try {
            $pdo->exec($sql);

            // Create indexes
            $idxNames = $_POST['idx_name'] ?? [];
            $idxTypes = $_POST['idx_type'] ?? [];
            $idxCols  = $_POST['idx_cols'] ?? [];
            foreach ($idxNames as $ii => $in) {
                $in = trim($in);
                if ($in === '') continue;
                $it = $idxTypes[$ii] ?? 'INDEX';
                $ic = trim($idxCols[$ii] ?? '');
                if ($ic === '') continue;
                if ($it === 'UNIQUE') {
                    $pdo->exec("ALTER TABLE `{$tableName}` ADD UNIQUE `{$in}` ({$ic})");
                } elseif ($it === 'FULLTEXT') {
                    $pdo->exec("ALTER TABLE `{$tableName}` ADD FULLTEXT `{$in}` ({$ic})");
                } else {
                    $pdo->exec("ALTER TABLE `{$tableName}` ADD INDEX `{$in}` ({$ic})");
                }
            }

            flash("表 `{$tableName}` 创建成功");
            header('Location: ?tab=mysql&sub=table_data&table=' . urlencode($tableName));
        } catch (Exception $e) {
            flash("创建表失败: " . $e->getMessage(), 'error');
            header('Location: ?tab=mysql&sub=create_table');
        }
        exit;
    }

    if ($action === 'mysql_drop_table') {
        $table = post('table');
        list($pdo, $err) = mysqlConnect();
        if ($pdo && $table) {
            try {
                $pdo->exec("DROP TABLE `{$table}`");
                flash("表 `{$table}` 已删除");
            } catch (Exception $e) {
                flash("删除表失败: " . $e->getMessage(), 'error');
            }
        }
        header('Location: ?tab=mysql&sub=tables');
        exit;
    }

    if ($action === 'mysql_insert') {
        $table = post('table');
        list($pdo, $err) = mysqlConnect();
        if ($pdo && $table) {
            try {
                $columns = [];
                $placeholders = [];
                $values = [];
                foreach ($_POST as $k => $v) {
                    if (strpos($k, 'col_') === 0) {
                        $colName = substr($k, 4);
                        $columns[] = "`{$colName}`";
                        $placeholders[] = ":{$colName}";
                        $values[":{$colName}"] = $v;
                    }
                }
                if ($columns) {
                    $sql = "INSERT INTO `{$table}` (" . implode(',', $columns) . ") VALUES (" . implode(',', $placeholders) . ")";
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute($values);
                    flash("数据插入成功");
                }
            } catch (Exception $e) {
                flash("插入失败: " . $e->getMessage(), 'error');
            }
        }
        header('Location: ?tab=mysql&sub=table_data&table=' . urlencode($table));
        exit;
    }

    if ($action === 'mysql_update') {
        $table = post('table');
        $pkCol = post('pk_col');
        $pkVal = post('pk_val');
        list($pdo, $err) = mysqlConnect();
        if ($pdo && $table) {
            try {
                $sets = [];
                $values = [':__pk' => $pkVal];
                foreach ($_POST as $k => $v) {
                    if (strpos($k, 'col_') === 0) {
                        $colName = substr($k, 4);
                        $sets[] = "`{$colName}` = :set_{$colName}";
                        $values[":set_{$colName}"] = $v;
                    }
                }
                if ($sets) {
                    $sql = "UPDATE `{$table}` SET " . implode(',', $sets) . " WHERE `{$pkCol}` = :__pk";
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute($values);
                    flash("数据更新成功");
                }
            } catch (Exception $e) {
                flash("更新失败: " . $e->getMessage(), 'error');
            }
        }
        header('Location: ?tab=mysql&sub=table_data&table=' . urlencode($table));
        exit;
    }

    if ($action === 'mysql_delete_row') {
        $table = post('table');
        $pkCol = post('pk_col');
        $pkVal = post('pk_val');
        list($pdo, $err) = mysqlConnect();
        if ($pdo && $table && $pkCol !== '' && $pkVal !== '') {
            try {
                $stmt = $pdo->prepare("DELETE FROM `{$table}` WHERE `{$pkCol}` = :v");
                $stmt->execute([':v' => $pkVal]);
                flash("数据删除成功");
            } catch (Exception $e) {
                flash("删除失败: " . $e->getMessage(), 'error');
            }
        }
        header('Location: ?tab=mysql&sub=table_data&table=' . urlencode($table));
        exit;
    }

    // SQL 导出
    if ($action === 'mysql_export_sql') {
        $table = get('table');
        $type = get('type', 'table');
        list($pdo, $err) = mysqlConnect();
        if ($pdo) {
            try {
                $sqlOut = '';
                if ($type === 'table' && $table) {
                    // 导出单表
                    $stmt = $pdo->query("SHOW CREATE TABLE `{$table}`");
                    $row = $stmt->fetch();
                    $sqlOut .= "DROP TABLE IF EXISTS `{$table}`;\n";
                    $sqlOut .= $row['Create Table'] . ";\n\n";
                    $rows = $pdo->query("SELECT * FROM `{$table}`")->fetchAll();
                    foreach ($rows as $r) {
                        $vals = array_map(function($v) use ($pdo) { return $pdo->quote($v); }, array_values($r));
                        $sqlOut .= "INSERT INTO `{$table}` VALUES (" . implode(',', $vals) . ");\n";
                    }
                } elseif ($type === 'database') {
                    $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
                    foreach ($tables as $t) {
                        $stmt = $pdo->query("SHOW CREATE TABLE `{$t}`");
                        $row = $stmt->fetch();
                        $sqlOut .= "DROP TABLE IF EXISTS `{$t}`;\n";
                        $sqlOut .= $row['Create Table'] . ";\n\n";
                        $rows = $pdo->query("SELECT * FROM `{$t}`")->fetchAll();
                        foreach ($rows as $r) {
                            $vals = array_map(function($v) use ($pdo) { return $pdo->quote($v); }, array_values($r));
                            $sqlOut .= "INSERT INTO `{$t}` VALUES (" . implode(',', $vals) . ");\n";
                        }
                        $sqlOut .= "\n";
                    }
                }
                exportSql(($table ?: 'database') . '_export_' . date('YmdHis') . '.sql', $sqlOut);
                exit;
            } catch (Exception $e) {
                flash('导出失败: ' . $e->getMessage(), 'error');
                header('Location: ?tab=mysql');
                exit;
            }
        }
    }

    // CSV 导出
    if ($action === 'mysql_export_csv') {
        $table = get('table');
        list($pdo, $err) = mysqlConnect();
        if ($pdo && $table) {
            try {
                $rows = $pdo->query("SELECT * FROM `{$table}`")->fetchAll();
                if ($rows) {
                    $headers = array_keys($rows[0]);
                    exportCsv($table . '_export_' . date('YmdHis') . '.csv', $headers, $rows);
                } else {
                    // 空表也要导出表头
                    $stmt = $pdo->query("DESCRIBE `{$table}`");
                    $headers = $stmt->fetchAll(PDO::FETCH_COLUMN);
                    exportCsv($table . '_export_' . date('YmdHis') . '.csv', $headers, []);
                }
                exit;
            } catch (Exception $e) {
                flash('导出失败: ' . $e->getMessage(), 'error');
                header('Location: ?tab=mysql');
                exit;
            }
        }
    }

    // 导入
    if ($action === 'mysql_import') {
        list($pdo, $err) = mysqlConnect();
        $importMode = post('import_mode', 'table');
        if ($pdo && isset($_FILES['import_file']) && $_FILES['import_file']['error'] === UPLOAD_ERR_OK) {
            $tmp = $_FILES['import_file']['tmp_name'];
            $ext = strtolower(pathinfo($_FILES['import_file']['name'], PATHINFO_EXTENSION));
            try {
                if ($ext === 'sql') {
                    // ----- 表级导入：SQL 文件导入到指定表 -----
                    if ($importMode === 'table') {
                        $table = post('import_table');
                        if (!$table) { flash('请指定目标表名', 'error'); }
                        else {
                            $content = file_get_contents($tmp);
                            // 移除 BOM
                            if (substr($content, 0, 3) === chr(0xEF).chr(0xBB).chr(0xBF)) {
                                $content = substr($content, 3);
                            }
                            $statements = array_filter(
                                array_map('trim', preg_split('/;\\s*\\n|;\\s*$/', $content, -1, PREG_SPLIT_NO_EMPTY)),
                                function($s) { return $s !== ''; }
                            );
                            if (empty($statements)) {
                                flash('SQL 文件为空或无有效语句', 'error');
                            } else {
                                $successCount = 0;
                                $failedCount = 0;
                                $errors = [];
                                foreach ($statements as $si => $sql) {
                                    $sql = trim($sql);
                                    if ($sql === '') continue;
                                    if (preg_match('/^--/', $sql) || preg_match('/^\\/\\*/', $sql)) continue;
                                    // 表级导入：跳过 CREATE TABLE / DROP TABLE 等建表语句，只执行 INSERT
                                    if (preg_match('/^(CREATE|DROP)\s+TABLE/i', $sql)) continue;
                                    // 将 INSERT 转为 INSERT IGNORE，遇到主键重复自动跳过
                                    $sql = preg_replace('/^INSERT\s+INTO\b/i', 'INSERT IGNORE INTO', $sql);
                                    try {
                                        $pdo->exec($sql);
                                        $successCount++;
                                    } catch (Exception $e) {
                                        $failedCount++;
                                        $errMsg = $e->getMessage();
                                        if (mb_strlen($errMsg) > 120) $errMsg = mb_substr($errMsg, 0, 120) . '...';
                                        $errors[] = "第" . ($si + 1) . "条: " . $errMsg;
                                    }
                                }
                                if ($failedCount === 0) {
                                    flash("SQL 导入成功，共执行 {$successCount} 条语句到表 `{$table}`", 'success');
                                } else {
                                    $msg = "导入完成（部分成功）。成功 {$successCount} 条，失败 {$failedCount} 条。";
                                    if (!empty($errors)) {
                                        $msg .= "\n" . implode("\n", array_slice($errors, 0, 5));
                                        if (count($errors) > 5) $msg .= "\n...等" . count($errors) . "个错误";
                                    }
                                    flash($msg, $successCount > 0 ? 'warning' : 'error');
                                }
                            }
                        }
                    // ----- 数据库级导入：SQL 文件导入到数据库（原有逻辑）-----
                    } else {
                    // 用户选项
                    $tableAction = post('table_action', 'append'); // skip / drop_recreate / append
                    $pkAction    = post('pk_action', 'skip');      // skip / update / error

                    // ===== 模拟 Navicat 导入环境 =====
                    $pdo->exec("SET NAMES utf8mb4");
                    $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
                    // 关闭严格模式，兼容空字符串等不规范数据
                    try { $pdo->exec("SET sql_mode = ''"); } catch (Exception $e) {}

                    $content = file_get_contents($tmp);
                    // 移除 BOM
                    if (substr($content, 0, 3) === chr(0xEF).chr(0xBB).chr(0xBF)) {
                        $content = substr($content, 3);
                    }
                    // 按分号+换行拆分多条 SQL（也支持单独分号结尾）
                    $statements = array_filter(
                        array_map('trim', preg_split('/;\s*\n|;\s*$/', $content, -1, PREG_SPLIT_NO_EMPTY)),
                        function($s) { return $s !== ''; }
                    );

                    // 统计计数器
                    $successCount = 0;
                    $failedCount  = 0;
                    $skipCount    = 0;   // 跳过的语句（如表已存在跳过建表）
                    $skippedTables = []; // 跳过的表名列表
                    $droppedTables = []; // 被删除后重建的表名列表
                    $errors = [];

                    foreach ($statements as $si => $sql) {
                        $sql = trim($sql);
                        if ($sql === '') continue;
                        // 跳过纯注释行
                        if (preg_match('/^--/', $sql) || preg_match('/^\/\*/', $sql)) continue;

                        $isCreate = preg_match('/^CREATE\s+TABLE\s+/i', $sql);
                        $isInsert = preg_match('/^INSERT\s+/i', $sql);

                        // ---------- 建表语句处理 ----------
                        if ($isCreate) {
                            if ($tableAction === 'append') {
                                // 追加模式：完全跳过建表语句
                                if (preg_match('/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?(\w+)`?/i', $sql, $m)) {
                                    $skippedTables[] = $m[1];
                                }
                                $skipCount++;
                                continue;
                            }
                            if ($tableAction === 'drop_recreate') {
                                // 删除重建：先 DROP TABLE IF EXISTS
                                if (preg_match('/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?(\w+)`?/i', $sql, $m)) {
                                    $tname = $m[1];
                                    try { $pdo->exec("DROP TABLE IF EXISTS `{$tname}`"); } catch (Exception $e) {}
                                    $droppedTables[] = $tname;
                                }
                            }
                            // 统一替换为 CREATE TABLE IF NOT EXISTS
                            $sql = preg_replace('/CREATE\s+TABLE\s+(IF\s+NOT\s+EXISTS\s+)?/i', 'CREATE TABLE IF NOT EXISTS ', $sql, 1);
                        }

                        // ---------- INSERT 语句处理 ----------
                        if ($isInsert && $pkAction !== 'error') {
                            if ($pkAction === 'skip') {
                                // INSERT IGNORE：主键重复时静默跳过
                                $sql = preg_replace('/INSERT\s+/i', 'INSERT IGNORE ', $sql, 1);
                            } elseif ($pkAction === 'update') {
                                // ON DUPLICATE KEY UPDATE：主键重复时更新
                                if (preg_match('/INSERT\s+(?:IGNORE\s+)?INTO\s+`?(\w+)`?\s*\(([^)]+)\)/i', $sql, $m)) {
                                    $cols = array_map('trim', explode(',', $m[2]));
                                    $cleanCols = [];
                                    foreach ($cols as $c) { $cleanCols[] = trim($c, '`"\' '); }
                                    $updates = array_map(function($c) { return "`{$c}`=VALUES(`{$c}`)"; }, $cleanCols);
                                    $sql = rtrim($sql, '; ') . ' ON DUPLICATE KEY UPDATE ' . implode(', ', $updates);
                                }
                            }
                        }

                        // ---------- 执行语句 ----------
                        try {
                            $pdo->exec($sql);
                            $successCount++;
                        } catch (Exception $e) {
                            // 尝试清理数值字段空字符串后重试
                            $retry = preg_replace_callback(
                                "/,''(,|\))/",
                                function($m) { return ",0" . $m[1]; },
                                $sql
                            );
                            $retry = preg_replace_callback(
                                "/,'',/",
                                function($m) { return ",0,"; },
                                $retry
                            );
                            if ($retry !== $sql) {
                                try {
                                    $pdo->exec($retry);
                                    $successCount++;
                                    continue;
                                } catch (Exception $e2) {
                                    // retry also failed, use original error
                                }
                            }
                            $failedCount++;
                            $errMsg = $e->getMessage();
                            if (mb_strlen($errMsg) > 120) $errMsg = mb_substr($errMsg, 0, 120) . '...';
                            // 尝试提取表名/行号给更友好的错误信息
                            if (preg_match('/Duplicate entry/i', $errMsg)) {
                                $errors[] = "主键重复 #" . ($si + 1) . ": " . $errMsg;
                            } elseif (preg_match('/already exists/i', $errMsg)) {
                                $errors[] = "表已存在 #" . ($si + 1) . ": " . $errMsg;
                            } else {
                                $errors[] = "第" . ($si + 1) . "条: " . $errMsg;
                            }
                        }
                    }

                    // 恢复外键检查
                    try { $pdo->exec("SET FOREIGN_KEY_CHECKS = 1"); } catch (Exception $e) {}

                    // 拼接统计信息
                    $parts = [];
                    if ($successCount > 0) $parts[] = "成功执行 {$successCount} 条";
                    if ($skipCount > 0) {
                        $parts[] = "跳过 {$skipCount} 条" . (!empty($skippedTables) ? "（表：" . implode(', ', array_unique($skippedTables)) . "）" : "");
                    }
                    if (!empty($droppedTables)) $parts[] = "删除并重建表：" . implode(', ', array_unique($droppedTables));
                    if ($failedCount > 0) $parts[] = "失败 {$failedCount} 条";

                    if ($failedCount === 0) {
                        $msg = "导入完成！" . implode("；", $parts);
                        flash($msg, 'success');
                    } else {
                        $msg = "导入完成（部分成功）。" . implode("；", $parts) . "。";
                        if (!empty($errors)) {
                            $msg .= "\n错误详情：\n" . implode("\n", array_slice($errors, 0, 5));
                            if (count($errors) > 5) $msg .= "\n...等" . count($errors) . "个错误";
                        }
                        flash($msg, $successCount > 0 ? 'warning' : 'error');
                    }
                    } // 数据库级导入 else 块结束
                } elseif ($ext === 'csv') {
                    $table = post('import_table');
                    if (!$table) { flash('请指定目标表名', 'error'); }
                    else {
                        $handle = fopen($tmp, 'r');
                        // 跳过 BOM
                        $bom = fread($handle, 3);
                        if ($bom !== chr(0xEF).chr(0xBB).chr(0xBF)) {
                            fseek($handle, 0);
                        }
                        $headers = fgetcsv($handle);
                        if ($headers && $table) {
                            $cols = [];
                            $phs = [];
                            foreach ($headers as $h) {
                                $h = trim($h);
                                $cols[] = "`{$h}`";
                                $phs[] = ":{$h}";
                            }
                            $sql = "INSERT INTO `{$table}` (" . implode(',', $cols) . ") VALUES (" . implode(',', $phs) . ")";
                            $stmt = $pdo->prepare($sql);
                            $pdo->beginTransaction();
                            $rowCount = 0;
                            while (($row = fgetcsv($handle)) !== false) {
                                $params = [];
                                foreach ($headers as $i => $h) {
                                    $params[":" . trim($h)] = $row[$i] ?? '';
                                }
                                $stmt->execute($params);
                                $rowCount++;
                            }
                            $pdo->commit();
                            flash('CSV 导入成功，共导入 ' . $rowCount . ' 行数据到表 `' . $table . '`');
                        }
                        fclose($handle);
                    }
                } else {
                    flash('不支持的文件格式，仅支持 .sql 和 .csv', 'error');
                }
            } catch (Exception $e) {
                // 确保恢复外键检查
                if (isset($pdo)) {
                    try { $pdo->exec("SET FOREIGN_KEY_CHECKS = 1"); } catch (Exception $ex) {}
                }
                flash('导入失败: ' . $e->getMessage(), 'error');
            }
        } else {
            $errMsg = '';
            if (!$pdo) $errMsg = $err;
            elseif (!isset($_FILES['import_file']) || $_FILES['import_file']['error'] !== UPLOAD_ERR_OK) {
                $errMsg = '请选择一个有效的文件';
            }
            flash($errMsg ?: '导入失败', 'error');
        }
        header('Location: ?tab=mysql&sub=import');
        exit;
    }

    // 执行自定义 SQL
    if ($action === 'mysql_query') {
        // 注意：这里的 $rawSql 不做 HTML 转义，原样保存
        $rawSql = post('sql_query');
        $_SESSION['mysql_last_sql'] = $rawSql;
        $_SESSION['mysql_last_sql_error'] = '';
        $_SESSION['mysql_last_sql_result'] = null;
        $_SESSION['mysql_last_sql_columns'] = [];
        $_SESSION['mysql_last_affected'] = 0;

        if ($rawSql) {
            list($pdo, $err) = mysqlConnect();
            if ($pdo) {
                try {
                    $stmt = $pdo->query($rawSql);
                    if ($stmt && $stmt->columnCount() > 0) {
                        $_SESSION['mysql_last_sql_result'] = $stmt->fetchAll();
                        $_SESSION['mysql_last_sql_columns'] = array_keys($_SESSION['mysql_last_sql_result'][0] ?? []);
                    }
                    $_SESSION['mysql_last_affected'] = $stmt ? $stmt->rowCount() : 0;
                } catch (Exception $e) {
                    $_SESSION['mysql_last_sql_error'] = $e->getMessage();
                }
            } else {
                $_SESSION['mysql_last_sql_error'] = $err;
            }
        }
        header('Location: ?tab=mysql&sub=query');
        exit;
    }

    // 可视化建表 - 保存
    if ($action === 'mysql_table_design_save') {
        $table = post('table');
        $newTableName = trim(post('table_name'));
        list($pdo, $err) = mysqlConnect();
        if ($pdo && $table) {
            try {
                $pdo->beginTransaction();
                // 重命名表
                if ($newTableName && $newTableName !== $table) {
                    $pdo->exec("RENAME TABLE `{$table}` TO `{$newTableName}`");
                    $table = $newTableName;
                    $_SESSION['mysql_config']['last_table'] = $table;
                }
                // 删除已有索引（保留主键）
                $idxStmt = $pdo->query("SHOW INDEX FROM `{$table}`");
                $existingIdxs = [];
                foreach ($idxStmt as $idxRow) {
                    $existingIdxs[$idxRow['Key_name']] = $idxRow;
                }
                // 先 drop 非主键索引
                $done = [];
                foreach ($existingIdxs as $kName => $ir) {
                    if ($kName === 'PRIMARY' || isset($done[$kName])) continue;
                    $done[$kName] = true;
                    $pdo->exec("ALTER TABLE `{$table}` DROP INDEX `{$kName}`");
                }
                // 获取当前列信息
                $curColsStmt = $pdo->query("DESCRIBE `{$table}`");
                $curCols = [];
                foreach ($curColsStmt as $cc) $curCols[$cc['Field']] = $cc;

                // 处理新列定义
                $fieldNames = $_POST['fld_name'] ?? [];
                $fieldTypes = $_POST['fld_type'] ?? [];
                $fieldLens  = $_POST['fld_len'] ?? [];
                $fieldDecs  = $_POST['fld_dec'] ?? [];
                $fieldNulls = $_POST['fld_null'] ?? [];
                $fieldPks   = $_POST['fld_pk'] ?? [];
                $fieldAIs   = $_POST['fld_ai'] ?? [];
                $fieldDefs  = $_POST['fld_default'] ?? [];
                $fieldCmts  = $_POST['fld_comment'] ?? [];
                $newFields  = [];

                foreach ($fieldNames as $fi => $fn) {
                    $fn = trim($fn);
                    if ($fn === '') continue;
                    $ft = $fieldTypes[$fi] ?? 'VARCHAR';
                    $fl = $fieldLens[$fi] ?? '';
                    $fd = $fieldDecs[$fi] ?? '';
                    $nullAllowed = in_array((string)$fi, $fieldNulls, true);
                    $isPk = in_array((string)$fi, $fieldPks, true);
                    $isAi = in_array((string)$fi, $fieldAIs, true);
                    $def = $fieldDefs[$fi] ?? '';
                    $comment = $fieldCmts[$fi] ?? '';

                    // AUTO_INCREMENT 必须 NOT NULL → 强制不允许 NULL
                    if ($isAi) {
                        $nullAllowed = false;
                        $def = '';
                    }

                    $typeStr = $ft;
                    if ($fl !== '' && in_array(strtolower($ft),['varchar','char','int','bigint','tinyint','smallint','mediumint'])) {
                        $typeStr .= "({$fl})";
                    } elseif ($fl !== '' && in_array(strtolower($ft),['decimal','float','double','numeric'])) {
                        if ($fd !== '') $typeStr .= "({$fl},{$fd})";
                        else $typeStr .= "({$fl})";
                    }

                    $colDef = "`{$fn}` {$typeStr}";
                    if (!$nullAllowed) $colDef .= " NOT NULL";
                    if ($def !== '') {
                        if (strtoupper($def) === 'NULL') $colDef .= " DEFAULT NULL";
                        elseif (strtoupper($def) === 'CURRENT_TIMESTAMP') $colDef .= " DEFAULT CURRENT_TIMESTAMP";
                        else $colDef .= " DEFAULT '{$def}'";
                    } elseif ($nullAllowed) {
                        $colDef .= " DEFAULT NULL";
                    }
                    if ($isAi) $colDef .= " AUTO_INCREMENT";
                    if ($comment !== '') $colDef .= " COMMENT '{$comment}'";

                    $newFields[$fn] = [
                        'def' => $colDef,
                        'pk'  => $isPk,
                        'ai'  => $isAi,
                    ];
                }

                // 找出要删除的列
                $newNames = array_keys($newFields);
                $curNames = array_keys($curCols);
                $drops = array_diff($curNames, $newNames);

                foreach ($drops as $dn) {
                    $pdo->exec("ALTER TABLE `{$table}` DROP COLUMN `{$dn}`");
                }

                // 找出新增的列和修改的列
                $prevCol = '';
                foreach ($curNames as $cn) {
                    if (!isset($newFields[$cn])) continue; // 已删除
                    // 修改列
                    $newDef = $newFields[$cn]['def'];
                    // Always modify to ensure latest definition
                    if ($prevCol) {
                        $pdo->exec("ALTER TABLE `{$table}` MODIFY COLUMN {$newDef} AFTER `{$prevCol}`");
                    } else {
                        $pdo->exec("ALTER TABLE `{$table}` MODIFY COLUMN {$newDef} FIRST");
                    }
                    $prevCol = $cn;
                }
                // 新增列（在 newNames 但不在 curNames 中的）
                foreach ($newNames as $nn) {
                    if (!isset($curCols[$nn])) {
                        $newDef = $newFields[$nn]['def'];
                        if ($prevCol) {
                            $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN {$newDef} AFTER `{$prevCol}`");
                        } else {
                            $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN {$newDef} FIRST");
                        }
                        $prevCol = $nn;
                    }
                }

                // 重建主键：先 drop 后 add
                $newPks = [];
                foreach ($newFields as $fn => $nf) {
                    if ($nf['pk']) $newPks[] = $fn;
                }
                if ($newPks) {
                    $pkNames = array_map(function($n){ return "`{$n}`"; }, $newPks);
                    try {
                        $pdo->exec("ALTER TABLE `{$table}` DROP PRIMARY KEY");
                    } catch (Exception $e) {}
                    $pdo->exec("ALTER TABLE `{$table}` ADD PRIMARY KEY (" . implode(',', $pkNames) . ")");
                }

                // 重建索引
                $idxNames = $_POST['idx_name'] ?? [];
                $idxTypes = $_POST['idx_type'] ?? [];
                $idxCols  = $_POST['idx_cols'] ?? [];
                foreach ($idxNames as $ii => $in) {
                    $in = trim($in);
                    if ($in === '') continue;
                    $it = $idxTypes[$ii] ?? 'INDEX';
                    $ic = trim($idxCols[$ii] ?? '');
                    if ($ic === '') continue;
                    if ($it === 'UNIQUE') {
                        $pdo->exec("ALTER TABLE `{$table}` ADD UNIQUE `{$in}` ({$ic})");
                    } elseif ($it === 'FULLTEXT') {
                        $pdo->exec("ALTER TABLE `{$table}` ADD FULLTEXT `{$in}` ({$ic})");
                    } else {
                        $pdo->exec("ALTER TABLE `{$table}` ADD INDEX `{$in}` ({$ic})");
                    }
                }

                $pdo->commit();
                flash("表 `{$table}` 设计已保存");
            } catch (Exception $e) {
                if ($pdo && $pdo->inTransaction()) $pdo->rollBack();
                flash("保存失败: " . $e->getMessage(), 'error');
            }
        }
        header('Location: ?tab=mysql&sub=table_design&table=' . urlencode($table));
        exit;
    }
}

// ============================================================
// SQLITE 动作处理
// ============================================================
if ($tab === 'sqlite') {
    if ($action === 'sqlite_connect') {
        $path = post('path', '');
        if ($path && (preg_match('/\.(db|sqlite|sqlite3)$/i', $path) || post('force_open'))) {
            if (file_exists($path) || post('force_open')) {
                $_SESSION['sqlite_config'] = ['path' => $path];
                list($pdo, $err) = sqliteConnect();
                if ($err) {
                    unset($_SESSION['sqlite_config']);
                    flash($err, 'error');
                } else {
                    flash('SQLite 连接成功');
                }
            } else {
                flash('文件不存在', 'error');
            }
        } else {
            flash('请输入有效的 SQLite 文件路径', 'error');
        }
        header('Location: ?tab=sqlite');
        exit;
    }

    if ($action === 'sqlite_create_table') {
        $tableName = trim(post('new_table'));
        $sqlCreate  = trim(post('create_sql'));
        if ($tableName && preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $tableName) && $sqlCreate) {
            list($pdo, $err) = sqliteConnect();
            if ($pdo) {
                try {
                    $pdo->exec($sqlCreate);
                    flash("表 `{$tableName}` 创建成功");
                    header('Location: ?tab=sqlite&sub=tables');
                    exit;
                } catch (Exception $e) {
                    flash("创建表失败: " . $e->getMessage(), 'error');
                }
            }
        } else {
            flash("表名不合法或 SQL 为空", 'error');
        }
        header('Location: ?tab=sqlite&sub=tables');
        exit;
    }

    if ($action === 'sqlite_drop_table') {
        $table = post('table');
        list($pdo, $err) = sqliteConnect();
        if ($pdo && $table) {
            try {
                $pdo->exec("DROP TABLE IF EXISTS `{$table}`");
                flash("表 `{$table}` 已删除");
            } catch (Exception $e) {
                flash("删除表失败: " . $e->getMessage(), 'error');
            }
        }
        header('Location: ?tab=sqlite&sub=tables');
        exit;
    }

    if ($action === 'sqlite_insert') {
        $table = post('table');
        list($pdo, $err) = sqliteConnect();
        if ($pdo && $table) {
            try {
                $columns = [];
                $placeholders = [];
                $values = [];
                foreach ($_POST as $k => $v) {
                    if (strpos($k, 'col_') === 0) {
                        $colName = substr($k, 4);
                        $columns[] = "`{$colName}`";
                        $placeholders[] = ":{$colName}";
                        $values[":{$colName}"] = $v;
                    }
                }
                if ($columns) {
                    $sql = "INSERT INTO `{$table}` (" . implode(',', $columns) . ") VALUES (" . implode(',', $placeholders) . ")";
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute($values);
                    flash("数据插入成功");
                }
            } catch (Exception $e) {
                flash("插入失败: " . $e->getMessage(), 'error');
            }
        }
        header('Location: ?tab=sqlite&sub=table_data&table=' . urlencode($table));
        exit;
    }

    if ($action === 'sqlite_update') {
        $table = post('table');
        $pkCol = post('pk_col');
        $pkVal = post('pk_val');
        list($pdo, $err) = sqliteConnect();
        if ($pdo && $table) {
            try {
                $sets = [];
                $values = [':__pk' => $pkVal];
                foreach ($_POST as $k => $v) {
                    if (strpos($k, 'col_') === 0) {
                        $colName = substr($k, 4);
                        $sets[] = "`{$colName}` = :set_{$colName}";
                        $values[":set_{$colName}"] = $v;
                    }
                }
                if ($sets) {
                    $sql = "UPDATE `{$table}` SET " . implode(',', $sets) . " WHERE `{$pkCol}` = :__pk";
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute($values);
                    flash("数据更新成功");
                }
            } catch (Exception $e) {
                flash("更新失败: " . $e->getMessage(), 'error');
            }
        }
        header('Location: ?tab=sqlite&sub=table_data&table=' . urlencode($table));
        exit;
    }

    if ($action === 'sqlite_delete_row') {
        $table = post('table');
        $pkCol = post('pk_col');
        $pkVal = post('pk_val');
        list($pdo, $err) = sqliteConnect();
        if ($pdo && $table && $pkCol !== '' && $pkVal !== '') {
            try {
                $stmt = $pdo->prepare("DELETE FROM `{$table}` WHERE `{$pkCol}` = :v");
                $stmt->execute([':v' => $pkVal]);
                flash("数据删除成功");
            } catch (Exception $e) {
                flash("删除失败: " . $e->getMessage(), 'error');
            }
        }
        header('Location: ?tab=sqlite&sub=table_data&table=' . urlencode($table));
        exit;
    }

    if ($action === 'sqlite_export_csv') {
        $table = get('table');
        list($pdo, $err) = sqliteConnect();
        if ($pdo && $table) {
            try {
                $rows = $pdo->query("SELECT * FROM `{$table}`")->fetchAll();
                if ($rows) {
                    $headers = array_keys($rows[0]);
                    exportCsv($table . '_export_' . date('YmdHis') . '.csv', $headers, $rows);
                } else {
                    $stmt = $pdo->query("PRAGMA table_info(`{$table}`)");
                    $headers = array_column($stmt->fetchAll(), 'name');
                    exportCsv($table . '_export_' . date('YmdHis') . '.csv', $headers, []);
                }
                exit;
            } catch (Exception $e) {
                flash('导出失败: ' . $e->getMessage(), 'error');
                header('Location: ?tab=sqlite');
                exit;
            }
        }
    }

    if ($action === 'sqlite_export_sql') {
        $table = get('table');
        list($pdo, $err) = sqliteConnect();
        if ($pdo && $table) {
            try {
                $sqlOut = '';
                $stmt = $pdo->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='{$table}'");
                $row = $stmt->fetch();
                $sqlOut .= "DROP TABLE IF EXISTS `{$table}`;\n";
                $sqlOut .= $row['sql'] . ";\n\n";
                $rows = $pdo->query("SELECT * FROM `{$table}`")->fetchAll();
                foreach ($rows as $r) {
                    $vals = array_map(function($v) use ($pdo) { return $pdo->quote($v); }, array_values($r));
                    $sqlOut .= "INSERT INTO `{$table}` VALUES (" . implode(',', $vals) . ");\n";
                }
                exportSql($table . '_export_' . date('YmdHis') . '.sql', $sqlOut);
                exit;
            } catch (Exception $e) {
                flash('导出失败: ' . $e->getMessage(), 'error');
                header('Location: ?tab=sqlite');
                exit;
            }
        }
    }

    if ($action === 'sqlite_query') {
        $rawSql = post('sql_query');
        $_SESSION['sqlite_last_sql'] = $rawSql;
        $_SESSION['sqlite_last_sql_error'] = '';
        $_SESSION['sqlite_last_sql_result'] = null;
        $_SESSION['sqlite_last_sql_columns'] = [];

        if ($rawSql) {
            list($pdo, $err) = sqliteConnect();
            if ($pdo) {
                try {
                    $stmt = $pdo->query($rawSql);
                    if ($stmt && $stmt->columnCount() > 0) {
                        $_SESSION['sqlite_last_sql_result'] = $stmt->fetchAll();
                        $_SESSION['sqlite_last_sql_columns'] = array_keys($_SESSION['sqlite_last_sql_result'][0] ?? []);
                    }
                    $_SESSION['sqlite_last_affected'] = $stmt ? $stmt->rowCount() : 0;
                } catch (Exception $e) {
                    $_SESSION['sqlite_last_sql_error'] = $e->getMessage();
                }
            } else {
                $_SESSION['sqlite_last_sql_error'] = $err;
            }
        }
        header('Location: ?tab=sqlite&sub=query');
        exit;
    }

    if ($action === 'sqlite_import') {
        list($pdo, $err) = sqliteConnect();
        $importMode = post('import_mode', 'table');
        if ($pdo && isset($_FILES['import_file']) && $_FILES['import_file']['error'] === UPLOAD_ERR_OK) {
            $tmp = $_FILES['import_file']['tmp_name'];
            $ext = strtolower(pathinfo($_FILES['import_file']['name'], PATHINFO_EXTENSION));
            try {
                if ($ext === 'sql') {
                    // ----- 表级导入：SQL 文件导入到指定表 -----
                    if ($importMode === 'table') {
                        $table = post('import_table');
                        if (!$table) { flash('请指定目标表名', 'error'); }
                        else {
                            $content = file_get_contents($tmp);
                            // 移除 BOM
                            if (substr($content, 0, 3) === chr(0xEF).chr(0xBB).chr(0xBF)) {
                                $content = substr($content, 3);
                            }
                            $statements = array_filter(
                                array_map('trim', preg_split('/;\\s*\\n|;\\s*$/', $content, -1, PREG_SPLIT_NO_EMPTY)),
                                function($s) { return $s !== ''; }
                            );
                            if (empty($statements)) {
                                flash('SQL 文件为空或无有效语句', 'error');
                            } else {
                                $successCount = 0;
                                $failedCount = 0;
                                $errors = [];
                                foreach ($statements as $si => $sql) {
                                    $sql = trim($sql);
                                    if ($sql === '') continue;
                                    if (preg_match('/^--/', $sql) || preg_match('/^\\/\\*/', $sql)) continue;
                                    // 表级导入：跳过 CREATE TABLE / DROP TABLE 等建表语句，只执行 INSERT
                                    if (preg_match('/^(CREATE|DROP)\s+TABLE/i', $sql)) continue;
                                    // 将 INSERT 转为 INSERT IGNORE，遇到主键重复自动跳过
                                    $sql = preg_replace('/^INSERT\s+INTO\b/i', 'INSERT IGNORE INTO', $sql);
                                    try {
                                        $pdo->exec($sql);
                                        $successCount++;
                                    } catch (Exception $e) {
                                        $failedCount++;
                                        $errMsg = $e->getMessage();
                                        if (mb_strlen($errMsg) > 120) $errMsg = mb_substr($errMsg, 0, 120) . '...';
                                        $errors[] = "第" . ($si + 1) . "条: " . $errMsg;
                                    }
                                }
                                if ($failedCount === 0) {
                                    flash("SQL 导入成功，共执行 {$successCount} 条语句到表 `{$table}`", 'success');
                                } else {
                                    $msg = "导入完成（部分成功）。成功 {$successCount} 条，失败 {$failedCount} 条。";
                                    if (!empty($errors)) {
                                        $msg .= "\n" . implode("\n", array_slice($errors, 0, 5));
                                        if (count($errors) > 5) $msg .= "\n...等" . count($errors) . "个错误";
                                    }
                                    flash($msg, $successCount > 0 ? 'warning' : 'error');
                                }
                            }
                        }
                    // ----- 数据库级导入：SQL 文件导入到数据库（原有逻辑）-----
                    } else {
                    // 用户选项
                    $tableAction = post('table_action', 'append'); // skip / drop_recreate / append
                    $pkAction    = post('pk_action', 'skip');      // skip / update / error

                    $content = file_get_contents($tmp);
                    // 移除 BOM
                    if (substr($content, 0, 3) === chr(0xEF).chr(0xBB).chr(0xBF)) {
                        $content = substr($content, 3);
                    }
                    $statements = array_filter(
                        array_map('trim', preg_split('/;\s*\n|;\s*$/', $content, -1, PREG_SPLIT_NO_EMPTY)),
                        function($s) { return $s !== ''; }
                    );

                    // 统计计数器
                    $successCount  = 0;
                    $failedCount   = 0;
                    $skipCount     = 0;
                    $skippedTables = [];
                    $droppedTables = [];
                    $errors = [];

                    foreach ($statements as $si => $sql) {
                        $sql = trim($sql);
                        if ($sql === '') continue;
                        if (preg_match('/^--/', $sql) || preg_match('/^\/\*/', $sql)) continue;

                        $isCreate = preg_match('/^CREATE\s+TABLE\s+/i', $sql);
                        $isInsert = preg_match('/^INSERT\s+/i', $sql);

                        // ---------- 建表语句处理 ----------
                        if ($isCreate) {
                            if ($tableAction === 'append') {
                                if (preg_match('/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?(\w+)`?/i', $sql, $m)) {
                                    $skippedTables[] = $m[1];
                                }
                                $skipCount++;
                                continue;
                            }
                            if ($tableAction === 'drop_recreate') {
                                if (preg_match('/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?(\w+)`?/i', $sql, $m)) {
                                    $tname = $m[1];
                                    try { $pdo->exec("DROP TABLE IF EXISTS \"{$tname}\""); } catch (Exception $e) {}
                                    $droppedTables[] = $tname;
                                }
                            }
                            // 统一替换为 CREATE TABLE IF NOT EXISTS
                            $sql = preg_replace('/CREATE\s+TABLE\s+(IF\s+NOT\s+EXISTS\s+)?/i', 'CREATE TABLE IF NOT EXISTS ', $sql, 1);
                        }

                        // ---------- INSERT 语句处理 ----------
                        if ($isInsert && $pkAction !== 'error') {
                            if ($pkAction === 'skip') {
                                // SQLite: INSERT OR IGNORE
                                $sql = preg_replace('/INSERT\s+(OR\s+\w+\s+)?/i', 'INSERT OR IGNORE ', $sql, 1);
                            } elseif ($pkAction === 'update') {
                                // SQLite: INSERT OR REPLACE (删除旧行后插入新行)
                                $sql = preg_replace('/INSERT\s+(OR\s+\w+\s+)?/i', 'INSERT OR REPLACE ', $sql, 1);
                            }
                        }

                        // ---------- 执行语句 ----------
                        try {
                            $pdo->exec($sql);
                            $successCount++;
                        } catch (Exception $e) {
                            $failedCount++;
                            $errMsg = $e->getMessage();
                            if (mb_strlen($errMsg) > 120) $errMsg = mb_substr($errMsg, 0, 120) . '...';
                            if (preg_match('/UNIQUE constraint/i', $errMsg)) {
                                $errors[] = "主键冲突 #" . ($si + 1) . ": " . $errMsg;
                            } elseif (preg_match('/already exists/i', $errMsg)) {
                                $errors[] = "表已存在 #" . ($si + 1) . ": " . $errMsg;
                            } else {
                                $errors[] = "第" . ($si + 1) . "条: " . $errMsg;
                            }
                        }
                    }

                    // 拼接统计信息
                    $parts = [];
                    if ($successCount > 0) $parts[] = "成功执行 {$successCount} 条";
                    if ($skipCount > 0) {
                        $parts[] = "跳过 {$skipCount} 条" . (!empty($skippedTables) ? "（表：" . implode(', ', array_unique($skippedTables)) . "）" : "");
                    }
                    if (!empty($droppedTables)) $parts[] = "删除并重建表：" . implode(', ', array_unique($droppedTables));
                    if ($failedCount > 0) $parts[] = "失败 {$failedCount} 条";

                    if ($failedCount === 0) {
                        $msg = "导入完成！" . implode("；", $parts);
                        flash($msg, 'success');
                    } else {
                        $msg = "导入完成（部分成功）。" . implode("；", $parts) . "。";
                        if (!empty($errors)) {
                            $msg .= "\n错误详情：\n" . implode("\n", array_slice($errors, 0, 5));
                            if (count($errors) > 5) $msg .= "\n...等" . count($errors) . "个错误";
                        }
                        flash($msg, $successCount > 0 ? 'warning' : 'error');
                    }
                    } // 数据库级导入 else 块结束
                } elseif ($ext === 'csv') {
                    $table = post('import_table');
                    if (!$table) { flash('请指定目标表名', 'error'); }
                    else {
                        $handle = fopen($tmp, 'r');
                        $bom = fread($handle, 3);
                        if ($bom !== chr(0xEF).chr(0xBB).chr(0xBF)) fseek($handle, 0);
                        $headers = fgetcsv($handle);
                        if ($headers && $table) {
                            $cols = [];
                            $phs = [];
                            foreach ($headers as $h) { $h = trim($h); $cols[] = "`{$h}`"; $phs[] = ":{$h}"; }
                            $sql = "INSERT INTO `{$table}` (" . implode(',', $cols) . ") VALUES (" . implode(',', $phs) . ")";
                            $stmt = $pdo->prepare($sql);
                            $pdo->beginTransaction();
                            $rowCount = 0;
                            while (($row = fgetcsv($handle)) !== false) {
                                $params = [];
                                foreach ($headers as $i => $h) { $params[":" . trim($h)] = $row[$i] ?? ''; }
                                $stmt->execute($params);
                                $rowCount++;
                            }
                            $pdo->commit();
                            flash('CSV 导入成功，共导入 ' . $rowCount . ' 行数据到表 `' . $table . '`');
                        }
                        fclose($handle);
                    }
                } else {
                    flash('不支持的文件格式，仅支持 .sql 和 .csv', 'error');
                }
            } catch (Exception $e) {
                flash('导入失败: ' . $e->getMessage(), 'error');
            }
        } else {
            flash('请选择一个有效的文件', 'error');
        }
        header('Location: ?tab=sqlite&sub=import');
        exit;
    }

    // 导出整个数据库（所有表）
    if ($action === 'sqlite_export_all_sql') {
        list($pdo, $err) = sqliteConnect();
        if ($pdo) {
            try {
                $sqlOut = '';
                $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
                foreach ($tables as $t) {
                    $stmt = $pdo->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='{$t}'");
                    $row = $stmt->fetch();
                    $sqlOut .= "DROP TABLE IF EXISTS `{$t}`;\n";
                    $sqlOut .= $row['sql'] . ";\n\n";
                    $dataRows = $pdo->query("SELECT * FROM `{$t}`")->fetchAll();
                    foreach ($dataRows as $r) {
                        $vals = array_map(function($v) use ($pdo) { return $pdo->quote($v); }, array_values($r));
                        $sqlOut .= "INSERT INTO `{$t}` VALUES (" . implode(',', $vals) . ");\n";
                    }
                    $sqlOut .= "\n";
                }
                exportSql('database_export_' . date('YmdHis') . '.sql', $sqlOut);
                exit;
            } catch (Exception $e) {
                flash('导出失败: ' . $e->getMessage(), 'error');
                header('Location: ?tab=sqlite');
                exit;
            }
        }
    }
}

// ============================================================
// MONGODB 动作处理
// ============================================================
if ($tab === 'mongodb') {
    if ($action === 'mongo_connect') {
        $config = [
            'host' => post('host', '127.0.0.1'),
            'port' => post('port', '27017'),
            'user' => post('user', ''),
            'pass' => post('pass', ''),
        ];
        $saveConn = post('save_conn', '0');
        if (!class_exists('MongoDB\Driver\Manager')) {
            flash('MongoDB 扩展未安装', 'error');
        } else {
            try {
                $uri = "mongodb://";
                if ($config['user']) $uri .= $config['user'] . ':' . $config['pass'] . '@';
                $uri .= $config['host'] . ':' . $config['port'];
                new MongoDB\Driver\Manager($uri);
                if ($saveConn === '1') {
                    $_SESSION['mongo_config'] = $config;
                    flash('MongoDB 连接成功，配置已保存');
                } else {
                    flash('MongoDB 连接成功（未保存配置）');
                }
            } catch (Exception $e) {
                flash('MongoDB 连接失败: ' . $e->getMessage(), 'error');
            }
        }
        header('Location: ?tab=mongodb');
        exit;
    }

    if ($action === 'mongo_use_db') {
        $_SESSION['mongo_db'] = post('dbname');
        $_SESSION['mongo_coll'] = '';
        flash("已切换数据库");
        header('Location: ?tab=mongodb');
        exit;
    }

    if ($action === 'mongo_use_coll') {
        $_SESSION['mongo_coll'] = post('coll');
        flash("已切换集合");
        header('Location: ?tab=mongodb');
        exit;
    }

    if ($action === 'mongo_insert') {
        list($manager, $err) = mongoConnect();
        $db = $_SESSION['mongo_db'] ?? '';
        $coll = $_SESSION['mongo_coll'] ?? '';
        if ($manager && $db && $coll) {
            try {
                $json = post('doc_json');
                $doc = json_decode($json, true);
                if ($doc) {
                    $bulk = new MongoDB\Driver\BulkWrite;
                    $bulk->insert($doc);
                    $manager->executeBulkWrite("{$db}.{$coll}", $bulk);
                    flash("文档插入成功");
                } else {
                    flash("JSON 格式无效", 'error');
                }
            } catch (Exception $e) {
                flash("插入失败: " . $e->getMessage(), 'error');
            }
        }
        header('Location: ?tab=mongodb&sub=docs');
        exit;
    }

    if ($action === 'mongo_update') {
        list($manager, $err) = mongoConnect();
        $db = $_SESSION['mongo_db'] ?? '';
        $coll = $_SESSION['mongo_coll'] ?? '';
        if ($manager && $db && $coll) {
            try {
                $filterJson = post('filter_json');
                $updateJson = post('update_json');
                $filter = json_decode($filterJson, true);
                $update = json_decode($updateJson, true);
                if ($filter && $update) {
                    $bulk = new MongoDB\Driver\BulkWrite;
                    $bulk->update($filter, ['$set' => $update], ['multi' => false]);
                    $manager->executeBulkWrite("{$db}.{$coll}", $bulk);
                    flash("文档更新成功");
                } else {
                    flash("JSON 格式无效", 'error');
                }
            } catch (Exception $e) {
                flash("更新失败: " . $e->getMessage(), 'error');
            }
        }
        header('Location: ?tab=mongodb&sub=docs');
        exit;
    }

    if ($action === 'mongo_delete') {
        list($manager, $err) = mongoConnect();
        $db = $_SESSION['mongo_db'] ?? '';
        $coll = $_SESSION['mongo_coll'] ?? '';
        if ($manager && $db && $coll) {
            try {
                $filterJson = post('filter_json');
                $filter = json_decode($filterJson, true);
                if ($filter) {
                    $bulk = new MongoDB\Driver\BulkWrite;
                    $bulk->delete($filter, ['limit' => 1]);
                    $manager->executeBulkWrite("{$db}.{$coll}", $bulk);
                    flash("文档删除成功");
                }
            } catch (Exception $e) {
                flash("删除失败: " . $e->getMessage(), 'error');
            }
        }
        header('Location: ?tab=mongodb&sub=docs');
        exit;
    }

    // MongoDB 导出
    if ($action === 'mongo_export_json') {
        list($manager, $err) = mongoConnect();
        $db = $_SESSION['mongo_db'] ?? '';
        $coll = $_SESSION['mongo_coll'] ?? '';
        if ($manager && $db && $coll) {
            try {
                $query = new MongoDB\Driver\Query([]);
                $cursor = $manager->executeQuery("{$db}.{$coll}", $query);
                $docs = [];
                foreach ($cursor as $doc) {
                    $docs[] = json_decode(json_encode($doc), true);
                }
                header('Content-Type: application/json; charset=utf-8');
                header('Content-Disposition: attachment; filename="' . $coll . '_export_' . date('YmdHis') . '.json"');
                echo json_encode($docs, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
                exit;
            } catch (Exception $e) {
                flash('导出失败: ' . $e->getMessage(), 'error');
                header('Location: ?tab=mongodb');
                exit;
            }
        }
    }

    // MongoDB 导入
    if ($action === 'mongo_import') {
        list($manager, $err) = mongoConnect();
        $db = $_SESSION['mongo_db'] ?? '';
        $coll = $_SESSION['mongo_coll'] ?? '';
        if ($manager && $db && $coll && isset($_FILES['import_file']) && $_FILES['import_file']['error'] === UPLOAD_ERR_OK) {
            $tmp = $_FILES['import_file']['tmp_name'];
            try {
                $json = file_get_contents($tmp);
                $docs = json_decode($json, true);
                if ($docs) {
                    if (!isset($docs[0])) $docs = [$docs];
                    $bulk = new MongoDB\Driver\BulkWrite;
                    foreach ($docs as $doc) {
                        $bulk->insert($doc);
                    }
                    $manager->executeBulkWrite("{$db}.{$coll}", $bulk);
                    flash('导入成功，共导入 ' . count($docs) . ' 条文档');
                } else {
                    flash('JSON 格式无效', 'error');
                }
            } catch (Exception $e) {
                flash('导入失败: ' . $e->getMessage(), 'error');
            }
        }
        header('Location: ?tab=mongodb&sub=docs');
        exit;
    }
}

// ============================================================
// REDIS 动作处理
// ============================================================
if ($tab === 'redis') {
    if ($action === 'redis_connect') {
        $config = [
            'host'    => post('host', '127.0.0.1'),
            'port'    => post('port', '6379'),
            'pass'    => post('pass', ''),
            'db'      => (int)post('db', '0'),
            'timeout' => post('timeout', '3'),
            'scheme'  => post('scheme', 'tcp'),
        ];
        $saveConn = post('save_conn', '0');
        if (!class_exists('Redis')) {
            flash('Redis 扩展未安装', 'error');
        } else {
            try {
                $redis = new Redis();
                $timeout = (float)($config['timeout'] ?? 3);
                if ($config['scheme'] === 'tls') {
                    $redis->connect('tls://' . $config['host'], (int)$config['port'], $timeout);
                } else {
                    $redis->connect($config['host'], (int)$config['port'], $timeout);
                }
                if ($config['pass']) $redis->auth($config['pass']);
                if ($config['db'] > 0) $redis->select((int)$config['db']);
                if ($saveConn === '1') {
                    $_SESSION['redis_config'] = $config;
                    flash('Redis 连接成功，配置已保存');
                } else {
                    flash('Redis 连接成功（未保存配置）');
                }
            } catch (Exception $e) {
                flash('Redis 连接失败: ' . $e->getMessage(), 'error');
            }
        }
        header('Location: ?tab=redis');
        exit;
    }

    if ($action === 'redis_set') {
        list($redis, $err) = redisConnect();
        if ($redis) {
            $key   = post('key');
            $value = post('value');
            $ttl   = (int)post('ttl', '-1');
            if ($key) {
                $redis->set($key, $value);
                if ($ttl > 0) $redis->expire($key, $ttl);
                flash("键 `{$key}` 已设置");
            }
        }
        header('Location: ?tab=redis&sub=browse');
        exit;
    }

    if ($action === 'redis_del') {
        list($redis, $err) = redisConnect();
        if ($redis) {
            $key = post('key');
            if ($key) {
                $redis->del($key);
                flash("键 `{$key}` 已删除");
            }
        }
        header('Location: ?tab=redis&sub=browse');
        exit;
    }

    if ($action === 'redis_flush') {
        list($redis, $err) = redisConnect();
        if ($redis) {
            $redis->flushDB();
            flash("当前数据库已清空");
        }
        header('Location: ?tab=redis&sub=browse');
        exit;
    }

    if ($action === 'redis_exec') {
        list($redis, $err) = redisConnect();
        $rawCmd = post('redis_cmd');
        $_SESSION['redis_last_cmd'] = $rawCmd;
        $_SESSION['redis_last_result'] = '';
        if ($redis && $rawCmd) {
            try {
                $parts = preg_split('/\s+/', trim($rawCmd));
                $cmd = strtoupper(array_shift($parts));
                $result = $redis->rawCommand($cmd, ...$parts);
                $_SESSION['redis_last_result'] = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            } catch (Exception $e) {
                $_SESSION['redis_last_result'] = '错误: ' . $e->getMessage();
            }
        }
        header('Location: ?tab=redis&sub=command');
        exit;
    }
}

// ============================================================
// 子页面标识
// ============================================================
$sub = get('sub', 'home');

// ============================================================
// HTML 输出
// ============================================================
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>数据库管理工具</title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;background:#f0f2f5;color:#333;min-height:100vh}
.header{background:#1a1a2e;color:#fff;padding:0 24px;display:flex;align-items:center;justify-content:space-between;height:56px}
.header h1{font-size:18px;font-weight:600}
.header nav{display:flex;gap:4px}
.header nav a{color:#a0a0b8;text-decoration:none;padding:8px 16px;border-radius:6px;font-size:14px;transition:all .2s}
.header nav a:hover{color:#fff;background:rgba(255,255,255,.1)}
.header nav a.active{color:#fff;background:#e94560}
.container{max-width:1400px;margin:0 auto;padding:24px}
.card{background:#fff;border-radius:8px;box-shadow:0 1px 3px rgba(0,0,0,.08);margin-bottom:20px;overflow:hidden}
.card-header{padding:16px 20px;border-bottom:1px solid #eee;font-weight:600;font-size:15px;display:flex;align-items:center;justify-content:space-between}
.card-body{padding:20px}
.flash{padding:12px 20px;border-radius:6px;margin-bottom:16px;font-size:14px;white-space:pre-line}
.flash-success{background:#e6f7e9;color:#1a7d36;border:1px solid #b7e4c7}
.flash-error{background:#ffeaea;color:#c92a2a;border:1px solid #ffc9c9}
.flash-warning{background:#fff8e1;color:#b45309;border:1px solid #fde68a}
.form-group{margin-bottom:14px}
.form-group label{display:block;font-size:13px;font-weight:500;color:#555;margin-bottom:4px}
.form-group input,.form-group select,.form-group textarea{width:100%;padding:8px 12px;border:1px solid #ddd;border-radius:6px;font-size:14px;font-family:inherit;transition:border-color .2s}
.form-group input:focus,.form-group textarea:focus,.form-group select:focus{outline:none;border-color:#e94560;box-shadow:0 0 0 3px rgba(233,69,96,.1)}
.form-group textarea{resize:vertical;min-height:80px;font-family:"SFMono-Regular",Consolas,"Liberation Mono",Menlo,monospace}
.radio-group{display:flex;flex-direction:column;gap:6px;padding:8px 12px;background:#f8f9fa;border:1px solid #e0e0e0;border-radius:6px}
.radio-item{display:flex;align-items:center;gap:6px;font-size:13px;color:#444;cursor:pointer;font-weight:400}
.radio-item input[type="radio"]{width:auto;padding:0;margin:0;accent-color:#e94560;cursor:pointer}
.form-row{display:flex;gap:12px}
.form-row .form-group{flex:1}
.btn{padding:8px 18px;border:none;border-radius:6px;font-size:14px;cursor:pointer;font-weight:500;transition:all .2s;text-decoration:none;display:inline-block}
.btn-primary{background:#e94560;color:#fff}
.btn-primary:hover{background:#d63850}
.btn-danger{background:#c92a2a;color:#fff}
.btn-danger:hover{background:#a61e1e}
.btn-outline{background:transparent;border:1px solid #ddd;color:#555}
.btn-outline:hover{background:#f5f5f5}
.btn-sm{padding:4px 10px;font-size:12px}
.btn-xs{padding:2px 8px;font-size:11px}
table{width:100%;border-collapse:collapse}
table th,table td{padding:8px 12px;text-align:left;border-bottom:1px solid #eee;font-size:13px}
table th{background:#f8f9fa;font-weight:600;color:#555;white-space:nowrap}
table tr:hover{background:#f8f9fa}
table td{word-break:break-all;max-width:300px}

/* ========== Navicat 风格网格数据表 ========== */
.navicat-grid-wrap{max-height:65vh;overflow:auto;border:1px solid #c8ccd0;border-radius:6px;position:relative;background:#fff}
.navicat-grid{border-collapse:collapse;table-layout:auto;min-width:100%;margin:0}
.navicat-grid thead{position:sticky;top:0;z-index:10}
.navicat-grid thead th{padding:7px 10px;border:1px solid #c4c8cc;border-top:none;background:#e8ecf2;background:linear-gradient(180deg,#f4f5f7 0%,#e6eaf0 100%);color:#444;font-weight:600;font-size:12px;white-space:nowrap;text-align:left;position:relative;box-shadow:0 1px 2px rgba(0,0,0,.06)}
.navicat-grid thead th.row-num{width:48px;min-width:48px;text-align:center;background:#dde1e7;color:#777;font-weight:500;font-size:11px;box-shadow:2px 0 4px rgba(0,0,0,.04);border-right:2px solid #c8ccd2}
.navicat-grid tbody td{padding:4px 10px;border:1px solid #e2e2e2;font-size:12px;white-space:nowrap;max-width:320px;overflow:hidden;text-overflow:ellipsis;word-break:keep-all;color:#333;line-height:1.5}
.navicat-grid tbody td.row-num{text-align:center;background:#f7f8fa;color:#aaa;font-size:10px;width:48px;min-width:48px;border-right:2px solid #e2e2e2;font-variant-numeric:tabular-nums;cursor:default;user-select:none}
.navicat-grid tbody tr:nth-child(even) td{background:#fafbfd}
.navicat-grid tbody tr:nth-child(even) td.row-num{background:#f2f3f6}
.navicat-grid tbody tr:hover td{background:#dce9fb!important}
.navicat-grid tbody tr:hover td.row-num{background:#cfdef7!important;color:#556}
.navicat-grid .col-filter-btn::after{content:' ▾';font-size:9px;opacity:.7}
.navicat-grid .filter-dropdown{font-size:12px;font-weight:400}
.navicat-grid .filter-dropdown select,.navicat-grid .filter-dropdown input,.navicat-grid .filter-dropdown button{font-size:11px}
.badge{display:inline-block;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:500}
.badge-green{background:#e6f7e9;color:#1a7d36}
.badge-red{background:#ffeaea;color:#c92a2a}
.badge-blue{background:#e7f1ff;color:#1c7ed6}
.empty{padding:40px;text-align:center;color:#999;font-size:14px}
.info-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:12px;margin-bottom:16px}
.info-item{background:#f8f9fa;padding:10px 14px;border-radius:6px}
.info-item .label{font-size:11px;color:#888;text-transform:uppercase}
.info-item .value{font-size:14px;font-weight:600;color:#333;word-break:break-all}
.code-block{background:#1a1a2e;color:#e0e0e0;padding:16px;border-radius:6px;font-family:"SFMono-Regular",Consolas,"Liberation Mono",Menlo,monospace;font-size:13px;overflow-x:auto;white-space:pre-wrap;word-break:break-all}
.mr-sm{margin-right:8px}
.ml-sm{margin-left:8px}
.mb-sm{margin-bottom:8px}
.mt-sm{margin-top:8px}
.text-muted{color:#999;font-size:12px}
.modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.5);display:flex;align-items:center;justify-content:center;z-index:1000}
.modal{background:#fff;border-radius:10px;padding:24px;max-width:500px;width:90%;max-height:80vh;overflow-y:auto}
.modal h3{margin-bottom:16px}
/* 分页 */
.pagination-bar{display:flex;align-items:center;justify-content:space-between;gap:8px;flex-wrap:wrap;padding:8px 0}
.pagination-info{font-size:12px;color:#999;display:flex;align-items:center;gap:4px}
.pagination-btns{display:flex;align-items:center;gap:2px;flex-wrap:wrap}
.pagination-num{display:inline-flex;align-items:center;justify-content:center;min-width:28px;height:28px;padding:0 6px;border:1px solid #ddd;border-radius:4px;font-size:12px;color:#555;text-decoration:none;transition:all .15s}
.pagination-num:hover{background:#f0f0f0}
.pagination-num.active{background:#e94560;color:#fff;border-color:#e94560}
.pagination-dots{padding:0 4px;color:#999;font-size:12px}
/* 筛选下拉 */
.col-filter-btn{cursor:pointer;position:relative}
.col-filter-btn:hover{color:#e94560}
.col-filter-btn::after{content:' ▾';font-size:10px}
.filter-dropdown{display:none;position:absolute;top:100%;left:0;z-index:999;background:#fff;border:1px solid #ddd;border-radius:6px;box-shadow:0 4px 12px rgba(0,0,0,.15);min-width:220px;padding:10px}
.filter-dropdown.show{display:block}
.filter-dropdown select,.filter-dropdown input{width:100%;padding:6px 8px;border:1px solid #ddd;border-radius:4px;font-size:12px;margin-bottom:6px}
.filter-dropdown .filter-actions{display:flex;gap:6px}
.filter-dropdown .filter-actions button{flex:1;font-size:11px}
th{position:relative}

/* 聚合统计 */
.agg-bar{display:flex;gap:6px;align-items:flex-start;flex-wrap:wrap;padding:10px 12px;background:#f0f4ff;border-radius:6px;margin-bottom:12px;border:1px solid #d0d8ff}
.agg-bar .agg-label{font-size:12px;color:#555;font-weight:600;white-space:nowrap;margin-top:4px}
.agg-table{border-collapse:collapse;font-size:12px;width:auto}
.agg-table th,.agg-table td{padding:3px 10px;border:1px solid #d0d8ff;white-space:nowrap}
.agg-table th{background:#e0e8ff;font-weight:600;color:#444}
.agg-table .agg-col-name{font-weight:600;color:#333;background:#f5f7ff}
.agg-table .agg-val{text-align:right;color:#222}
.agg-bar .agg-result{font-size:13px;font-weight:600;color:#e94560;margin-left:8px}
.agg-bar .agg-btn{font-size:11px;padding:3px 10px}
/* 收支结余 */
.agg-ie-summary{display:flex;gap:16px;align-items:center;flex-wrap:wrap;margin-top:4px;padding:6px 10px;background:#fff;border-radius:4px;border:1px solid #c8d6ff}
.agg-ie-summary .agg-ie-item{font-size:13px;white-space:nowrap}
.agg-ie-summary .agg-ie-income strong{color:#2e7d32}
.agg-ie-summary .agg-ie-expense strong{color:#c62828}
.agg-ie-summary .agg-ie-balance strong{color:#1565c0}
.agg-ie-select{padding:2px 6px;border:1px solid #c8d6ff;border-radius:4px;font-size:11px;background:#fff;color:#555;cursor:pointer;outline:none}
.agg-ie-select:focus{border-color:#e94560;box-shadow:0 0 0 2px rgba(233,69,96,.1)}

/* 可视化建表 */
.design-tabs{display:flex;gap:0;border-bottom:2px solid #e0e0e0;margin-bottom:16px}
.design-tab{padding:8px 20px;cursor:pointer;font-size:13px;font-weight:500;color:#777;border-bottom:2px solid transparent;margin-bottom:-2px;transition:all .2s}
.design-tab:hover{color:#e94560}
.design-tab.active{color:#e94560;border-bottom-color:#e94560}
.design-panel{display:none}
.design-panel.active{display:block}
.field-row{display:flex;gap:8px;align-items:center;padding:8px 6px;border-bottom:1px solid #eee;font-size:13px}
.field-row:hover{background:#fafafa}
.field-row input,.field-row select{padding:4px 6px;border:1px solid #ddd;border-radius:3px;font-size:12px}
.field-row input[type="text"]{min-width:100px}
.field-row input[type="number"]{width:50px}
.field-row select{min-width:90px}
.field-row .col-name{width:110px}
.field-row .col-type{width:110px}
.field-row .col-len{width:55px}
.field-row .col-dec{width:50px}
.field-row .col-check{width:40px;text-align:center}
.field-row .col-comment{flex:1;min-width:80px}
.field-header{display:flex;gap:8px;align-items:center;padding:6px;background:#f8f9fa;font-weight:600;font-size:12px;color:#555;border-bottom:1px solid #ddd}
.field-header .col-name{width:110px}
.field-header .col-type{width:110px}
.field-header .col-len{width:55px}
.field-header .col-dec{width:50px}
.field-header .col-check{width:40px;text-align:center}
.field-row .col-check input[type="checkbox"]:disabled,.field-header .col-check input[type="checkbox"]:disabled{cursor:not-allowed;opacity:0.45}
.field-header .col-comment{flex:1;min-width:80px}
.field-header .col-action{width:40px}
.idx-row{display:flex;gap:8px;align-items:center;padding:8px 6px;border-bottom:1px solid #eee;font-size:13px}
.idx-row:hover{background:#fafafa}
.idx-row input,.idx-row select{padding:4px 6px;border:1px solid #ddd;border-radius:3px;font-size:12px}
.idx-row .idx-name{width:130px}
.idx-row .idx-type{width:120px}
.idx-row .idx-cols{flex:1;min-width:150px}
.idx-header{display:flex;gap:8px;align-items:center;padding:6px;background:#f8f9fa;font-weight:600;font-size:12px;color:#555;border-bottom:1px solid #ddd}
.sql-preview{background:#1a1a2e;color:#e0e0e0;padding:14px;border-radius:6px;font-family:"SFMono-Regular",Consolas,"Liberation Mono",Menlo,monospace;font-size:12px;white-space:pre-wrap;overflow-x:auto;min-height:60px}

/* checkbox toggle */
.switch-label{display:inline-flex;align-items:center;gap:6px;font-size:13px;color:#555;cursor:pointer;user-select:none;padding:6px 12px;border:1px solid #ddd;border-radius:6px;transition:all .2s}
.switch-label:hover{border-color:#e94560}
.switch-label input[type="checkbox"]{accent-color:#e94560;width:16px;height:16px;cursor:pointer}

@media(max-width:768px){
    .form-row{flex-direction:column}
    .header{padding:0 12px}
    .header h1{font-size:15px}
    .container{padding:12px}
    .card-body{padding:12px}
    .field-row{flex-wrap:wrap}
    .field-header{display:none}
}

/* ========== 可视化建表（create_table 子页面） ========== */
.tbl-info-bar{background:#f8f9fa;border:1px solid #e0e0e0;border-radius:8px;padding:16px;margin-bottom:20px}
.tbl-info-grid{display:flex;gap:14px;flex-wrap:wrap}
.tbl-info-grid .form-group{flex:1;min-width:150px}
.tbl-info-grid .form-group label{font-size:12px;color:#666;margin-bottom:3px}
.tbl-info-grid input,.tbl-info-grid select{padding:7px 10px;font-size:13px}

.section-title{display:flex;align-items:center;gap:10px;font-size:15px;font-weight:600;color:#333;margin-bottom:10px;margin-top:18px}
.section-title:first-of-type{margin-top:0}

/* 字段编辑表头 + 索引表头：统一布局 */
.cr-field-header,.cr-idx-header{display:flex;gap:6px;align-items:center;padding:8px 8px;background:#f0f0f0;font-weight:600;font-size:12px;color:#666;border:1px solid #ddd;border-radius:6px 6px 0 0}
.cr-field-row,.cr-idx-row{display:flex;gap:6px;align-items:center;padding:6px 8px;border:1px solid #ddd;border-top:none;font-size:13px;background:#fff;transition:background .15s}
.cr-field-row:hover,.cr-idx-row:hover{background:#fafafa}
.cr-field-row:last-of-type{border-radius:0 0 6px 6px}
.cr-field-row input,.cr-field-row select,.cr-idx-row input,.cr-idx-row select{box-sizing:border-box;padding:4px 6px;border:1px solid #ddd;border-radius:3px;font-size:12px;height:26px}
.cr-field-row input:focus,.cr-field-row select:focus,.cr-idx-row input:focus,.cr-idx-row select:focus{outline:none;border-color:#e94560;box-shadow:0 0 0 2px rgba(233,69,96,.08)}

/* 各列固定宽度，确保表头与数据行严格对齐 */
.cr-col-seq{width:34px;text-align:center;font-size:11px;color:#999;flex-shrink:0}
.cr-col-name{width:100px;flex-shrink:0}
.cr-col-type{width:108px;flex-shrink:0}
.cr-col-len{width:58px;flex-shrink:0}
.cr-col-len2{width:58px;flex-shrink:0}
.cr-col-dec{width:50px;flex-shrink:0}
.cr-col-chk{width:48px;text-align:center;flex-shrink:0;display:flex;align-items:center;justify-content:center;gap:2px;font-size:11px;color:#555}
.cr-col-chk input[type="checkbox"]{width:15px;height:15px;cursor:pointer;accent-color:#e94560;margin:0}
.cr-col-chk input[type="checkbox"]:disabled{cursor:not-allowed;accent-color:#aaa;opacity:0.45}
.cr-col-def{width:100px;flex-shrink:0}
.cr-col-cmt{flex:1;min-width:70px}
.cr-col-act{width:76px;flex-shrink:0;display:flex;gap:2px;justify-content:center}
.cr-col-act .btn-xs{padding:1px 5px;font-size:10px;line-height:1.4}

/* 索引列对齐（与字段列间距一致） */
.cr-idx-name{width:130px;flex-shrink:0}
.cr-idx-type{width:120px;flex-shrink:0}
.cr-idx-cols{flex:1;min-width:150px}
.cr-idx-act{width:30px;flex-shrink:0}

@media(max-width:768px){
    .cr-field-header{display:none}
    .cr-field-row{flex-wrap:wrap;padding:10px 8px}
    .cr-col-seq,.cr-col-name,.cr-col-type,.cr-col-len,.cr-col-len2,.cr-col-dec,.cr-col-chk,.cr-col-def,.cr-col-cmt,.cr-col-act{width:auto;flex:1 1 auto;min-width:70px}
    .tbl-info-grid{flex-direction:column}
    .tbl-info-grid .form-group{min-width:auto}
}
</style>
</head>
<body>

<div class="header">
    <h1>🗄️ DB Tool</h1>
    <nav>
        <a href="?tab=mysql"   class="<?= $tab==='mysql'?'active':'' ?>">MySQL</a>
        <a href="?tab=sqlite"  class="<?= $tab==='sqlite'?'active':'' ?>">SQLite</a>
        <a href="?tab=mongodb" class="<?= $tab==='mongodb'?'active':'' ?>">MongoDB</a>
        <a href="?tab=redis"   class="<?= $tab==='redis'?'active':'' ?>">Redis</a>
    </nav>
</div>

<div class="container">

<?php
$flash = getFlash();
if ($flash): ?>
<div class="flash flash-<?= h($flash['type']) ?>"><?= h($flash['msg']) ?></div>
<?php endif; ?>

<?php
// ============================================================
// MYSQL 渲染
// ============================================================
if ($tab === 'mysql'):
    $config = $_SESSION['mysql_config'] ?? null;
    $connected = !empty($config);

    if (!$connected): ?>
    <!-- MySQL 连接表单 -->
    <div class="card">
        <div class="card-header">连接 MySQL</div>
        <div class="card-body">
            <form method="post">
                <input type="hidden" name="action" value="mysql_connect">
                <div class="form-row">
                    <div class="form-group"><label>主机</label><input name="host" value="127.0.0.1" required></div>
                    <div class="form-group"><label>端口</label><input name="port" value="3306" required></div>
                </div>
                <div class="form-row">
                    <div class="form-group"><label>用户名</label><input name="user" value="root"></div>
                    <div class="form-group"><label>密码</label><input name="pass" type="password"></div>
                </div>
                <div class="form-group"><label>数据库名 (可选)</label><input name="dbname" placeholder="留空则连接后选择"></div>
                <div style="display:flex;align-items:center;gap:12px">
                    <button class="btn btn-primary" type="submit">连接</button>
                    <label class="switch-label">
                        <input type="checkbox" name="save_conn" value="1" checked>
                        <span>保存连接（下次自动重连）</span>
                    </label>
                </div>
            </form>
        </div>
    </div>

<?php else: ?>
    <!-- 已连接状态 -->
    <div class="info-grid">
        <div class="info-item"><div class="label">主机</div><div class="value"><?= h($config['host']) ?>:<?= h($config['port']) ?></div></div>
        <div class="info-item"><div class="label">用户</div><div class="value"><?= h($config['user']) ?></div></div>
        <div class="info-item"><div class="label">当前库</div><div class="value"><?= h($config['dbname'] ?: '未选择') ?></div></div>
    </div>

    <!-- 子导航 -->
    <div style="margin-bottom:16px;display:flex;gap:8px;flex-wrap:wrap">
        <a href="?tab=mysql&sub=databases" class="btn btn-outline btn-sm <?= $sub==='databases'?'active':'' ?>" style="<?= $sub==='databases'?'background:#e94560;color:#fff;border-color:#e94560':'' ?>">数据库管理</a>
        <a href="?tab=mysql&sub=tables"    class="btn btn-outline btn-sm <?= $sub==='tables'?'active':'' ?>" style="<?= $sub==='tables'?'background:#e94560;color:#fff;border-color:#e94560':'' ?>">数据表管理</a>
        <a href="?tab=mysql&sub=query"     class="btn btn-outline btn-sm <?= $sub==='query'?'active':'' ?>" style="<?= $sub==='query'?'background:#e94560;color:#fff;border-color:#e94560':'' ?>">SQL 查询</a>
        <a href="?tab=mysql&sub=import"    class="btn btn-outline btn-sm <?= $sub==='import'?'active':'' ?>" style="<?= $sub==='import'?'background:#e94560;color:#fff;border-color:#e94560':'' ?>">导入</a>
        <a href="batch_sql.php?tab=mysql" class="btn btn-outline btn-sm" style="color:#e94560;font-weight:600;border-color:#e94560">批量SQL</a>
        <a href="?tab=mysql&sub=export"    class="btn btn-outline btn-sm <?= $sub==='export'?'active':'' ?>" style="<?= $sub==='export'?'background:#e94560;color:#fff;border-color:#e94560':'' ?>">导出</a>
        <a href="javascript:void(0)" class="btn btn-outline btn-sm" onclick="openSyncModal()" style="color:#e94560;font-weight:600;border-color:#e94560">数据同步</a>
        <a href="?tab=mysql&action=disconnect" class="btn btn-outline btn-sm" style="margin-left:auto;color:#c92a2a" onclick="return confirm('确定断开连接?')">断开</a>
    </div>

    <?php
    // ---- 数据库管理页 ----
    if ($sub === 'databases'):
        $c = $_SESSION['mysql_config'];
        try {
            $dsn = "mysql:host={$c['host']};port={$c['port']};charset=utf8mb4";
            $pdoTmp = new PDO($dsn, $c['user'], $c['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $databases = $pdoTmp->query("SHOW DATABASES")->fetchAll(PDO::FETCH_COLUMN);
        } catch (Exception $e) {
            $databases = [];
            echo '<div class="flash flash-error">获取数据库列表失败：' . h($e->getMessage()) . '</div>';
        }
    ?>
    <div class="card">
        <div class="card-header">数据库列表</div>
        <div class="card-body">
            <details style="margin-bottom:16px">
                <summary style="cursor:pointer;font-weight:600;font-size:15px">+ 新建数据库</summary>
                <form method="post" style="margin-top:12px;padding:14px;background:#f8f9fa;border-radius:8px">
                <input type="hidden" name="action" value="mysql_createdb">
                <div class="form-row">
                    <div class="form-group" style="flex:2"><label>数据库名</label><input name="new_dbname" placeholder="数据库名（字母数字下划线）" required pattern="[a-zA-Z_][a-zA-Z0-9_]*"></div>
                    <div class="form-group" style="flex:1"><label>字符集</label>
                        <select name="charset">
                            <option value="utf8mb4" selected>utf8mb4 (推荐)</option>
                            <option value="utf8">utf8</option>
                            <option value="latin1">latin1</option>
                            <option value="gbk">gbk</option>
                            <option value="gb2312">gb2312</option>
                        </select>
                    </div>
                    <div class="form-group" style="flex:1"><label>排序规则</label>
                        <select name="collation">
                            <option value="utf8mb4_unicode_ci" selected>utf8mb4_unicode_ci</option>
                            <option value="utf8mb4_general_ci">utf8mb4_general_ci</option>
                            <option value="utf8mb4_bin">utf8mb4_bin</option>
                            <option value="utf8_unicode_ci">utf8_unicode_ci</option>
                            <option value="utf8_general_ci">utf8_general_ci</option>
                        </select>
                    </div>
                </div>
                <button class="btn btn-primary btn-sm" type="submit">创建数据库</button>
                </form>
            </details>
            <?php if ($databases): ?>
            <table>
                <thead><tr><th>数据库名</th><th>操作</th></tr></thead>
                <tbody>
                <?php foreach ($databases as $dbn): ?>
                <tr>
                    <td><strong><?= h($dbn) ?></strong></td>
                    <td>
                        <form method="post" style="display:inline">
                            <input type="hidden" name="action" value="mysql_use_db">
                            <input type="hidden" name="dbname" value="<?= h($dbn) ?>">
                            <button class="btn btn-outline btn-xs" type="submit">选择</button>
                        </form>
                        <form method="post" style="display:inline" onsubmit="return confirm('确定删除数据库 `<?= h($dbn) ?>`?所有数据将丢失!')">
                            <input type="hidden" name="action" value="mysql_dropdb">
                            <input type="hidden" name="dbname" value="<?= h($dbn) ?>">
                            <button class="btn btn-outline btn-xs" type="submit" style="color:#c92a2a">删除</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php else: ?>
            <div class="empty">无数据库或无法获取</div>
            <?php endif; ?>
        </div>
    </div>

    <?php elseif ($sub === 'tables'):
        if (empty($config['dbname'])) {
            echo '<div class="flash flash-error">请先在"数据库管理"中选择一个数据库</div>';
        } else {
            list($pdo, $err) = mysqlConnect();
            if ($pdo) {
                $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
            } else {
                $tables = [];
            }
    ?>
    <div class="card">
        <div class="card-header">数据库 <?= h($config['dbname']) ?> - 数据表列表</div>
        <div class="card-body">
            <div style="margin-bottom:16px">
                <a href="?tab=mysql&sub=create_table" class="btn btn-primary btn-sm">+ 新建数据表</a>
                <span class="text-muted ml-sm">通过可视化表单创建数据表</span>
            </div>
            <?php if (!empty($tables)): ?>
            <table>
                <thead><tr><th>表名</th><th>操作</th></tr></thead>
                <tbody>
                <?php foreach ($tables as $t): ?>
                <tr>
                    <td><strong><?= h($t) ?></strong></td>
                    <td>
                        <a href="?tab=mysql&sub=table_data&table=<?= urlencode($t) ?>" class="btn btn-outline btn-xs">数据</a>
                        <a href="?tab=mysql&sub=table_design&table=<?= urlencode($t) ?>" class="btn btn-outline btn-xs">设计</a>
                        <a href="?tab=mysql&sub=table_structure&table=<?= urlencode($t) ?>" class="btn btn-outline btn-xs">结构</a>
                        <a href="?tab=mysql&action=mysql_export_sql&table=<?= urlencode($t) ?>&type=table" class="btn btn-outline btn-xs">导出SQL</a>
                        <a href="?tab=mysql&action=mysql_export_csv&table=<?= urlencode($t) ?>" class="btn btn-outline btn-xs">导出CSV</a>
                        <form method="post" style="display:inline" onsubmit="return confirm('确定删除表 `<?= h($t) ?>`?')">
                            <input type="hidden" name="action" value="mysql_drop_table">
                            <input type="hidden" name="table" value="<?= h($t) ?>">
                            <button class="btn btn-outline btn-xs" style="color:#c92a2a">删除表</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php else: ?>
            <div class="empty">暂无数据表</div>
            <?php endif; ?>
        </div>
    </div>
    <?php } ?>

    <?php elseif ($sub === 'create_table'):
        if (empty($config['dbname'])) {
            echo '<div class="flash flash-error">请先在"数据库管理"中选择一个数据库</div>';
        } else {
            list($pdo, $err) = mysqlConnect();
            if ($pdo) {
                try {
                    // 获取数据库的默认字符集和排序规则
                    $dbInfo = $pdo->query("SELECT DEFAULT_CHARACTER_SET_NAME, DEFAULT_COLLATION_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = '{$config['dbname']}'")->fetch();
                    $defaultCharset = $dbInfo['DEFAULT_CHARACTER_SET_NAME'] ?? 'utf8mb4';
                    $defaultCollation = $dbInfo['DEFAULT_COLLATION_NAME'] ?? 'utf8mb4_unicode_ci';
                } catch (Exception $e) {
                    $defaultCharset = 'utf8mb4';
                    $defaultCollation = 'utf8mb4_unicode_ci';
                }
            } else {
                $defaultCharset = 'utf8mb4';
                $defaultCollation = 'utf8mb4_unicode_ci';
            }
            // 数据类型选项
            $typeOptions = ['INT','BIGINT','TINYINT','SMALLINT','MEDIUMINT','VARCHAR','CHAR','TEXT','MEDIUMTEXT','LONGTEXT',
                'DATE','DATETIME','TIMESTAMP','TIME','YEAR','FLOAT','DOUBLE','DECIMAL','BOOLEAN','JSON','ENUM','SET','BLOB','LONGBLOB'];
            // 引擎选项
            $engines = ['InnoDB', 'MyISAM', 'MEMORY', 'ARCHIVE'];
    ?>
    <div class="card">
        <div class="card-header">
            新建数据表 - <?= h($config['dbname']) ?>
            <div style="display:flex;gap:6px">
                <a href="?tab=mysql&sub=tables" class="btn btn-outline btn-xs">返回列表</a>
            </div>
        </div>
        <div class="card-body">
            <form method="post" id="createTableForm">
                <input type="hidden" name="action" value="mysql_create_table">

                <!-- ========== ① 表基本信息区 ========== -->
                <div class="tbl-info-bar">
                    <div class="tbl-info-grid">
                        <div class="form-group">
                            <label>表名 <span style="color:#e94560">*</span></label>
                            <input type="text" name="table_name" id="tblName" placeholder="表名（字母数字下划线）" required pattern="[a-zA-Z_][a-zA-Z0-9_]*" style="width:100%">
                        </div>
                        <div class="form-group">
                            <label>引擎</label>
                            <select name="engine" id="tblEngine" style="width:100%">
                                <?php foreach ($engines as $en): ?>
                                <option value="<?= $en ?>" <?= $en==='InnoDB'?'selected':'' ?>><?= $en ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>字符集</label>
                            <select name="charset" id="tblCharset" onchange="onCharsetChange()" style="width:100%">
                                <option value="utf8mb4" <?= $defaultCharset==='utf8mb4'?'selected':'' ?>>utf8mb4 (推荐)</option>
                                <option value="utf8" <?= $defaultCharset==='utf8'?'selected':'' ?>>utf8</option>
                                <option value="latin1" <?= $defaultCharset==='latin1'?'selected':'' ?>>latin1</option>
                                <option value="gbk" <?= $defaultCharset==='gbk'?'selected':'' ?>>gbk</option>
                                <option value="gb2312" <?= $defaultCharset==='gb2312'?'selected':'' ?>>gb2312</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>排序规则</label>
                            <select name="collation" id="tblCollation" style="width:100%">
                                <option value="utf8mb4_unicode_ci" <?= $defaultCollation==='utf8mb4_unicode_ci'?'selected':'' ?>>utf8mb4_unicode_ci</option>
                                <option value="utf8mb4_general_ci" <?= $defaultCollation==='utf8mb4_general_ci'?'selected':'' ?>>utf8mb4_general_ci</option>
                                <option value="utf8mb4_bin" <?= $defaultCollation==='utf8mb4_bin'?'selected':'' ?>>utf8mb4_bin</option>
                                <option value="utf8_unicode_ci" <?= $defaultCollation==='utf8_unicode_ci'?'selected':'' ?>>utf8_unicode_ci</option>
                                <option value="utf8_general_ci" <?= $defaultCollation==='utf8_general_ci'?'selected':'' ?>>utf8_general_ci</option>
                                <option value="latin1_swedish_ci" <?= $defaultCollation==='latin1_swedish_ci'?'selected':'' ?>>latin1_swedish_ci</option>
                                <option value="gbk_chinese_ci" <?= $defaultCollation==='gbk_chinese_ci'?'selected':'' ?>>gbk_chinese_ci</option>
                            </select>
                        </div>
                        <div class="form-group" style="flex:1.5">
                            <label>表注释</label>
                            <input type="text" name="table_comment" placeholder="表的说明/注释" style="width:100%">
                        </div>
                    </div>
                </div>

                <!-- ========== ② 核心字段编辑区 ========== -->
                <div class="section-title">
                    <span>字段编辑</span>
                    <span class="text-muted">（添加、删除、排序字段，设置主键、非空、自增等属性）</span>
                </div>
                <div class="cr-field-header">
                    <span class="cr-col-seq">序号</span>
                    <span class="cr-col-name">字段名 <span style="color:#e94560">*</span></span>
                    <span class="cr-col-type">数据类型</span>
                    <span class="cr-col-len">长度</span>
                    <span class="cr-col-dec">小数</span>
                    <span class="cr-col-chk" title="勾选=非空(NOT NULL)">非空</span>
                    <span class="cr-col-chk" title="主键">主键</span>
                    <span class="cr-col-chk" title="自增(仅整数类型)">自增</span>
                    <span class="cr-col-def">默认值</span>
                    <span class="cr-col-cmt">注释</span>
                    <span class="cr-col-act">操作</span>
                </div>
                <div id="crFieldRows">
                    <!-- 默认第一行：INT -->
                    <div class="cr-field-row">
                        <span class="cr-col-seq">1</span>
                        <input type="text" class="cr-col-name" name="fld_name[]" value="" placeholder="字段名" onchange="updateSeqNumbers()">
                        <select class="cr-col-type" name="fld_type[]" onchange="onCrTypeChange(this)">
                            <?php foreach ($typeOptions as $to): ?>
                            <option value="<?= $to ?>" <?= $to==='INT'?'selected':'' ?>><?= $to ?></option>
                            <?php endforeach; ?>
                        </select>
                        <input type="text" class="cr-col-len" name="fld_len[]" value="" placeholder="长度" oninput="this.value=this.value.replace(/[^\d]/g,'')">
                        <input type="text" class="cr-col-dec" name="fld_dec[]" value="" placeholder="小数" style="display:none" oninput="this.value=this.value.replace(/[^\d]/g,'')">
                        <span class="cr-col-chk"><input type="checkbox" name="fld_null[]" value="0">非空</span>
                        <span class="cr-col-chk"><input type="checkbox" name="fld_pk[]" value="0" onchange="onPkChange(this)">主键</span>
                        <span class="cr-col-chk"><input type="checkbox" name="fld_ai[]" value="0" onchange="onAiChange(this)">自增</span>
                        <input type="text" class="cr-col-def" name="fld_default[]" value="" placeholder="默认值">
                        <input type="text" class="cr-col-cmt" name="fld_comment[]" value="" placeholder="注释">
                        <span class="cr-col-act">
                            <button type="button" class="btn btn-outline btn-xs" onclick="moveCrRow(this,-1)" title="上移">▲</button>
                            <button type="button" class="btn btn-outline btn-xs" onclick="moveCrRow(this,1)" title="下移">▼</button>
                            <button type="button" class="btn btn-outline btn-xs" onclick="delCrRow(this)" title="删除" style="color:#c92a2a">✕</button>
                        </span>
                    </div>
                    <!-- 默认第二行：VARCHAR(255) -->
                    <div class="cr-field-row">
                        <span class="cr-col-seq">2</span>
                        <input type="text" class="cr-col-name" name="fld_name[]" value="" placeholder="字段名" onchange="updateSeqNumbers()">
                        <select class="cr-col-type" name="fld_type[]" onchange="onCrTypeChange(this)">
                            <?php foreach ($typeOptions as $to): ?>
                            <option value="<?= $to ?>" <?= $to==='VARCHAR'?'selected':'' ?>><?= $to ?></option>
                            <?php endforeach; ?>
                        </select>
                        <input type="text" class="cr-col-len" name="fld_len[]" value="255" placeholder="长度" oninput="this.value=this.value.replace(/[^\d]/g,'')">
                        <input type="text" class="cr-col-dec" name="fld_dec[]" value="" placeholder="小数" style="display:none" oninput="this.value=this.value.replace(/[^\d]/g,'')">
                        <span class="cr-col-chk"><input type="checkbox" name="fld_null[]" value="1">非空</span>
                        <span class="cr-col-chk"><input type="checkbox" name="fld_pk[]" value="1" onchange="onPkChange(this)">主键</span>
                        <span class="cr-col-chk"><input type="checkbox" name="fld_ai[]" value="1" onchange="onAiChange(this)">自增</span>
                        <input type="text" class="cr-col-def" name="fld_default[]" value="" placeholder="默认值">
                        <input type="text" class="cr-col-cmt" name="fld_comment[]" value="" placeholder="注释">
                        <span class="cr-col-act">
                            <button type="button" class="btn btn-outline btn-xs" onclick="moveCrRow(this,-1)" title="上移">▲</button>
                            <button type="button" class="btn btn-outline btn-xs" onclick="moveCrRow(this,1)" title="下移">▼</button>
                            <button type="button" class="btn btn-outline btn-xs" onclick="delCrRow(this)" title="删除" style="color:#c92a2a">✕</button>
                        </span>
                    </div>
                </div>
                <button type="button" class="btn btn-outline btn-sm" style="margin-top:8px" onclick="addCrFieldRow()">+ 添加字段</button>
                <script>var crFieldCounter = 2;</script>

                <!-- ========== ③ 索引管理区 ========== -->
                <div class="section-title">
                    <span>索引管理</span>
                    <span class="text-muted">（可选，可建表后再添加索引）</span>
                </div>
                <div class="cr-idx-header">
                    <span class="cr-idx-name">索引名称</span>
                    <span class="cr-idx-type">索引类型</span>
                    <span class="cr-idx-cols">索引字段 <span class="text-muted">(逗号分隔)</span></span>
                    <span class="cr-idx-act">操作</span>
                </div>
                <div id="crIdxRows"></div>
                <button type="button" class="btn btn-outline btn-sm" style="margin-top:8px" onclick="addCrIdxRow()">+ 添加索引</button>

                <!-- SQL 预览 -->
                <div class="section-title" style="margin-top:20px">
                    <span>SQL 预览</span>
                    <button type="button" class="btn btn-outline btn-xs" onclick="crGeneratePreview()" style="margin-left:auto">刷新预览</button>
                </div>
                <div class="sql-preview" id="crSqlPreview" style="min-height:80px">点击"刷新预览"查看将要执行的 CREATE TABLE SQL 语句</div>

                <!-- ========== ④ 底部操作区 ========== -->
                <div style="margin-top:24px;display:flex;gap:10px;padding-top:16px;border-top:1px solid #e0e0e0">
                    <button type="submit" class="btn btn-primary" onclick="return crValidateForm()">保存</button>
                    <button type="button" class="btn btn-outline" onclick="crGeneratePreview()">预览SQL</button>
                    <a href="?tab=mysql&sub=tables" class="btn btn-outline" style="margin-left:auto">取消</a>
                </div>
            </form>
        </div>
    </div>
    <?php } ?>

    <?php elseif ($sub === 'table_data'):
        $table = get('table');
        if (!$table) { echo '<div class="flash flash-error">未指定表名</div>'; }
        else {
            list($pdo, $err) = mysqlConnect();
            // 分页参数
            $perPage = (int)(get('perpage', '50'));
            if ($perPage < 10) $perPage = 10;
            if ($perPage > 500) $perPage = 500;
            $page = max(1, (int)get('page', '1'));
            $colIdxMap = []; // 列索引映射
            if ($pdo) {
                $colStmt = $pdo->query("DESCRIBE `{$table}`");
                $columns = $colStmt->fetchAll();
                // 识别数字类型列
                $numCols = [];
                $allCols = [];
                foreach ($columns as $i => $col) {
                    $colIdxMap[$col['Field']] = $i;
                    $allCols[] = $col['Field'];
                    $t = strtolower($col['Type']);
                    if (preg_match('/^(int|bigint|tinyint|smallint|mediumint|float|double|decimal|numeric)/',$t)) {
                        $numCols[] = $col['Field'];
                    }
                }
                // ===== 解析筛选参数 f_CI=op:val 或 f_CI=between:from:to =====
                $filters = [];
                $whereParts = [];
                $whereParams = [];
                $filterQuery = ''; // 用于拼到翻页 URL 后面
                foreach ($_GET as $k => $v) {
                    if (preg_match('/^f_(\d+)$/', $k, $m)) {
                        $ci = (int)$m[1];
                        $filters[$ci] = $v;
                        $filterQuery .= '&f_' . $ci . '=' . urlencode($v);
                        if (!isset($columns[$ci])) continue;
                        $fieldName = $columns[$ci]['Field'];
                        $parts = explode(':', $v, 3);
                        $op = $parts[0];
                        $val = $parts[1] ?? '';
                        $toVal = $parts[2] ?? '';
                        if ($op === 'between') {
                            if ($val !== '' && $toVal !== '') {
                                $whereParts[] = "`{$fieldName}` BETWEEN :f_{$ci}_from AND :f_{$ci}_to";
                                $whereParams[":f_{$ci}_from"] = $val;
                                $whereParams[":f_{$ci}_to"] = $toVal;
                            } elseif ($val !== '') {
                                $whereParts[] = "`{$fieldName}` >= :f_{$ci}_from";
                                $whereParams[":f_{$ci}_from"] = $val;
                            } elseif ($toVal !== '') {
                                $whereParts[] = "`{$fieldName}` <= :f_{$ci}_to";
                                $whereParams[":f_{$ci}_to"] = $toVal;
                            }
                        } elseif ($op === 'empty') {
                            $whereParts[] = "(`{$fieldName}` IS NULL OR `{$fieldName}` = '')";
                        } elseif ($op === 'not_empty') {
                            $whereParts[] = "(`{$fieldName}` IS NOT NULL AND `{$fieldName}` != '')";
                        } elseif ($op === 'contains') {
                            $whereParts[] = "`{$fieldName}` LIKE :f_{$ci}";
                            $whereParams[":f_{$ci}"] = "%{$val}%";
                        } elseif ($op === 'not_contains') {
                            $whereParts[] = "`{$fieldName}` NOT LIKE :f_{$ci}";
                            $whereParams[":f_{$ci}"] = "%{$val}%";
                        } elseif ($op === 'starts') {
                            $whereParts[] = "`{$fieldName}` LIKE :f_{$ci}";
                            $whereParams[":f_{$ci}"] = "{$val}%";
                        } elseif ($op === 'ends') {
                            $whereParts[] = "`{$fieldName}` LIKE :f_{$ci}";
                            $whereParams[":f_{$ci}"] = "%{$val}";
                        } elseif ($op === 'equals') {
                            $whereParts[] = "`{$fieldName}` = :f_{$ci}";
                            $whereParams[":f_{$ci}"] = $val;
                        } elseif ($op === 'not_equals') {
                            $whereParts[] = "`{$fieldName}` != :f_{$ci}";
                            $whereParams[":f_{$ci}"] = $val;
                        } elseif ($op === 'greater_than') {
                            $whereParts[] = "`{$fieldName}` > :f_{$ci}";
                            $whereParams[":f_{$ci}"] = $val;
                        } elseif ($op === 'less_than') {
                            $whereParts[] = "`{$fieldName}` < :f_{$ci}";
                            $whereParams[":f_{$ci}"] = $val;
                        } elseif ($op === 'greater_equal') {
                            $whereParts[] = "`{$fieldName}` >= :f_{$ci}";
                            $whereParams[":f_{$ci}"] = $val;
                        } elseif ($op === 'less_equal') {
                            $whereParts[] = "`{$fieldName}` <= :f_{$ci}";
                            $whereParams[":f_{$ci}"] = $val;
                        }
                    }
                }
                $whereClause = !empty($whereParts) ? ' WHERE ' . implode(' AND ', $whereParts) : '';
                // ===== 筛选解析结束 =====

                $countStmt = $pdo->prepare("SELECT COUNT(*) FROM `{$table}`" . $whereClause);
                $countStmt->execute($whereParams);
                $totalRows = $countStmt->fetchColumn();
                $totalPages = max(1, ceil($totalRows / $perPage));
                if ($page > $totalPages) $page = $totalPages;
                $offset = ($page - 1) * $perPage;
                $dataStmt = $pdo->prepare("SELECT * FROM `{$table}`" . $whereClause . " LIMIT {$perPage} OFFSET {$offset}");
                $dataStmt->execute($whereParams);
                $rows = $dataStmt->fetchAll();
                $pk = 'id';
                foreach ($columns as $col) {
                    if ($col['Key'] === 'PRI') { $pk = $col['Field']; break; }
                }
                // 聚合统计：自动对所有数值型字段计算 SUM/MAX/MIN/AVG
                $aggResults = [];
                foreach ($numCols as $nc) {
                    try {
                        $aggStmt = $pdo->prepare("SELECT SUM(`{$nc}`) AS s, MAX(`{$nc}`) AS mx, MIN(`{$nc}`) AS mn, AVG(`{$nc}`) AS a FROM `{$table}`" . $whereClause);
                        $aggStmt->execute($whereParams);
                        $r = $aggStmt->fetch();
                        $aggResults[] = ['col'=>$nc, 'sum'=>$r['s'], 'max'=>$r['mx'], 'min'=>$r['mn'], 'avg'=>$r['a']];
                    } catch (Exception $e) { $aggResults[] = ['col'=>$nc, 'error'=>$e->getMessage()]; }
                }
                // 收入/支出/结余统计：用户可手动选择数值列，支持 ?ie_col=xxx 参数
                $incomeExpense = null;
                $ieCol = null;
                // 1) 优先使用用户手动选择的列（从 URL 参数 ie_col 读取）
                if (isset($_GET['ie_col']) && in_array($_GET['ie_col'], $numCols, true)) {
                    $ieCol = $_GET['ie_col'];
                }
                // 2) 兜底：自动匹配常用金额字段名
                if (!$ieCol) {
                    foreach ($allCols as $c) {
                        $cl = strtolower($c);
                        if (in_array($cl, ['amount', 'jine', 'money', 'jin_e', '金额', 'price', 'fee', 'je', 'balance', 'total'], true)) {
                            $ieCol = $c; break;
                        }
                    }
                }
                // 3) 最终兜底：用第一个数值列
                if (!$ieCol && !empty($numCols)) { $ieCol = $numCols[0]; }
                if ($ieCol) {
                    try {
                        $ieStmt = $pdo->prepare("SELECT SUM(`{$ieCol}`) AS total, SUM(CASE WHEN `{$ieCol}` > 0 THEN `{$ieCol}` ELSE 0 END) AS income, SUM(CASE WHEN `{$ieCol}` < 0 THEN `{$ieCol}` ELSE 0 END) AS expense FROM `{$table}`" . $whereClause);
                        $ieStmt->execute($whereParams);
                        $r = $ieStmt->fetch();
                        $incomeExpense = ['col'=>$ieCol, 'income'=>$r['income'], 'expense'=>$r['expense'], 'balance'=>$r['total']];
                    } catch (Exception $e) { $incomeExpense = ['error'=>$e->getMessage()]; }
                }
            } else { $columns = []; $rows = []; $totalRows = 0; $totalPages = 1; $offset = 0; $pk = 'id'; $numCols = []; $allCols = []; $aggResults = []; $incomeExpense = null; }
    ?>
    <div class="card">
        <div class="card-header">
            表 <?= h($table) ?>
            <span class="badge badge-blue"><?= $totalRows ?> 行</span>
            <?php if ($totalRows > 0): ?>
            <span style="font-size:12px;color:#999;margin-left:8px">当前第 <?= $offset+1 ?>-<?= min($offset+$perPage, $totalRows) ?> 条</span>
            <?php endif; ?>
            <span style="margin-left:auto;display:flex;gap:6px">
                <a href="?tab=mysql&sub=table_design&table=<?= urlencode($table) ?>" class="btn btn-outline btn-xs" title="可视化设计表结构">⚙ 设计表</a>
            </span>
        </div>
        <div class="card-body">
            <!-- 新增表单 -->
            <details style="margin-bottom:16px">
                <summary style="cursor:pointer;font-weight:600;font-size:15px">+ 新增记录</summary>
                <form method="post" style="margin-top:12px;padding:12px;background:#f8f9fa;border-radius:6px">
                    <input type="hidden" name="action" value="mysql_insert">
                    <input type="hidden" name="table" value="<?= h($table) ?>">
                    <?php foreach ($columns as $col):
                        if ($col['Extra'] === 'auto_increment') continue;
                        $mysqlInsertType = strtoupper($col['Type']);
                        $isDateInsert = (strpos($mysqlInsertType, 'DATE') === 0 && strpos($mysqlInsertType, 'DATETIME') === false);
                    ?>
                    <div class="form-group"><label><?= h($col['Field']) ?> (<?= h($col['Type']) ?>)</label><input type="<?= $isDateInsert ? 'date' : 'text' ?>" name="col_<?= h($col['Field']) ?>" placeholder="<?= h($col['Field']) ?>"></div>
                    <?php endforeach; ?>
                    <button class="btn btn-primary btn-sm" type="submit">添加</button>
                </form>
            </details>

            <!-- 聚合统计栏：自动对所有数值列计算 -->
            <?php if (!empty($aggResults) || $incomeExpense): ?>
            <div class="agg-bar">
                <span class="agg-label">📊 聚合统计（基于筛选结果）</span>
                <?php if (!empty($aggResults)): ?>
                <table class="agg-table">
                    <thead><tr><th>列名</th><th>求和</th><th>最大值</th><th>最小值</th><th>平均值</th></tr></thead>
                    <tbody>
                    <?php foreach ($aggResults as $ar): ?>
                        <tr>
                            <td class="agg-col-name"><?= h($ar['col']) ?></td>
                            <?php if (isset($ar['error'])): ?>
                            <td colspan="4" style="color:#c92a2a;text-align:center">错误: <?= h($ar['error']) ?></td>
                            <?php else: ?>
                            <td class="agg-val"><?= is_numeric($ar['sum']) ? number_format((float)$ar['sum'], 2) : h($ar['sum']) ?></td>
                            <td class="agg-val"><?= is_numeric($ar['max']) ? number_format((float)$ar['max'], 2) : h($ar['max']) ?></td>
                            <td class="agg-val"><?= is_numeric($ar['min']) ? number_format((float)$ar['min'], 2) : h($ar['min']) ?></td>
                            <td class="agg-val"><?= is_numeric($ar['avg']) ? number_format((float)$ar['avg'], 2) : h($ar['avg']) ?></td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
                <?php if ($incomeExpense && !isset($incomeExpense['error'])): ?>
                <div class="agg-ie-summary">
                    <?php if (count($numCols) > 1): ?>
                    <span class="agg-ie-item" style="display:flex;align-items:center;gap:4px">
                        <span style="font-size:11px;color:#888">收支列：</span>
                        <select onchange="var u=new URL(location.href);u.searchParams.set('ie_col',this.value);u.searchParams.set('page','1');location.href=u.toString()" class="agg-ie-select">
                            <?php foreach ($numCols as $nc): ?>
                            <option value="<?= h($nc) ?>" <?= $nc === $ieCol ? 'selected' : '' ?>><?= h($nc) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </span>
                    <?php else: ?>
                    <span class="agg-ie-item" style="font-size:11px;color:#888">收支列：<?= h($ieCol) ?></span>
                    <?php endif; ?>
                    <span class="agg-ie-item agg-ie-income">💰 总收入（<?= h($incomeExpense['col']) ?>&gt;0）：<strong><?= number_format((float)$incomeExpense['income'], 2) ?></strong></span>
                    <span class="agg-ie-item agg-ie-expense">💸 总支出（<?= h($incomeExpense['col']) ?>&lt;0）：<strong><?= number_format((float)$incomeExpense['expense'], 2) ?></strong></span>
                    <span class="agg-ie-item agg-ie-balance">📈 总结余（<?= h($incomeExpense['col']) ?>）：<strong><?= number_format((float)$incomeExpense['balance'], 2) ?></strong></span>
                </div>
                <?php elseif ($incomeExpense && isset($incomeExpense['error'])): ?>
                <span style="color:#c92a2a;font-size:12px">收支统计错误: <?= h($incomeExpense['error']) ?></span>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <!-- 分页导航（上方） -->
            <?php if ($totalPages > 1): ?>
            <div class="pagination-bar">
                <div class="pagination-info">每页
                    <select onchange="location.href='?tab=mysql&sub=table_data&table=<?= urlencode($table) ?>&page=1&perpage='+this.value+'<?= addslashes($filterQuery) ?>'" style="padding:2px 6px;border:1px solid #ddd;border-radius:4px;font-size:12px">
                        <?php foreach ([10,20,50,100,200] as $pp): ?>
                        <option value="<?= $pp ?>" <?= $perPage===$pp?'selected':'' ?>><?= $pp ?></option>
                        <?php endforeach; ?>
                    </select> 条
                </div>
                <div class="pagination-btns">
                    <?php if ($page > 1): ?>
                    <a href="?tab=mysql&sub=table_data&table=<?= urlencode($table) ?>&page=1&perpage=<?= $perPage ?><?= $filterQuery ?>" class="btn btn-outline btn-xs">首页</a>
                    <a href="?tab=mysql&sub=table_data&table=<?= urlencode($table) ?>&page=<?= $page-1 ?>&perpage=<?= $perPage ?><?= $filterQuery ?>" class="btn btn-outline btn-xs">上一页</a>
                    <?php endif; ?>

                    <?php
                    $startP = max(1, $page - 2);
                    $endP = min($totalPages, $page + 2);
                    if ($startP > 1): ?>
                    <span class="pagination-dots">…</span>
                    <?php endif;
                    for ($p = $startP; $p <= $endP; $p++): ?>
                    <a href="?tab=mysql&sub=table_data&table=<?= urlencode($table) ?>&page=<?= $p ?>&perpage=<?= $perPage ?><?= $filterQuery ?>" class="pagination-num <?= $p===$page?'active':'' ?>"><?= $p ?></a>
                    <?php endfor;
                    if ($endP < $totalPages): ?>
                    <span class="pagination-dots">…</span>
                    <?php endif; ?>

                    <?php if ($page < $totalPages): ?>
                    <a href="?tab=mysql&sub=table_data&table=<?= urlencode($table) ?>&page=<?= $page+1 ?>&perpage=<?= $perPage ?>" class="btn btn-outline btn-xs">下一页</a>
                    <a href="?tab=mysql&sub=table_data&table=<?= urlencode($table) ?>&page=<?= $totalPages ?>&perpage=<?= $perPage ?>" class="btn btn-outline btn-xs">末页</a>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- 数据表（含列筛选）- Navicat 网格风格 -->
            <?php if ($rows): ?>
            <div class="navicat-grid-wrap">
            <table id="dataTable" class="navicat-grid">
                <thead><tr>
                    <th class="row-num">#</th>
                <?php foreach ($columns as $ci => $col):
                    $mysqlColType = strtoupper(preg_replace('/\(.*/', '', $col['Type']));
                    $isDateType = in_array($mysqlColType, ['DATE','DATETIME','TIMESTAMP']);
                    $isNumericType = in_array($mysqlColType, ['INT','BIGINT','TINYINT','SMALLINT','MEDIUMINT','FLOAT','DOUBLE','DECIMAL','NUMERIC']);
                    $colCat = $isDateType ? 'date' : ($isNumericType ? 'number' : 'text');
                    $inpType = $isDateType ? 'date' : ($isNumericType ? 'number' : 'text');
                ?>
                    <th class="col-filter-btn" onclick="toggleFilter(event,'filter_<?= $ci ?>')"><?= h($col['Field']) ?>
                        <div class="filter-dropdown" id="filter_<?= $ci ?>">
                            <select onchange="onFilterOpChange(this, <?= $ci ?>, '<?= $colCat ?>')">
                                <?php if ($isNumericType || $isDateType): ?>
                                <option value="equals">等于</option>
                                <option value="not_equals">不等于</option>
                                <option value="greater_than">大于</option>
                                <option value="less_than">小于</option>
                                <option value="greater_equal">大于等于</option>
                                <option value="less_equal">小于等于</option>
                                <option value="between">介于</option>
                                <?php else: ?>
                                <option value="contains">包含</option>
                                <option value="not_contains">不包含</option>
                                <option value="equals">等于</option>
                                <option value="not_equals">不等于</option>
                                <option value="starts">开头是</option>
                                <option value="ends">结尾是</option>
                                <?php endif; ?>
                                <option value="empty">为空</option>
                                <option value="not_empty">不为空</option>
                            </select>
                            <input type="<?= $inpType ?>" id="filter_input_<?= $ci ?>" placeholder="输入筛选值...">
                            <div id="filter_between_<?= $ci ?>" style="display:none;margin-top:4px">
                                <input type="<?= $isDateType?'date':'number' ?>" id="filter_between_from_<?= $ci ?>" style="margin-bottom:4px" placeholder="<?= $isDateType?'起始日期':'最小值' ?>">
                                <input type="<?= $isDateType?'date':'number' ?>" id="filter_between_to_<?= $ci ?>" placeholder="<?= $isDateType?'结束日期':'最大值' ?>">
                            </div>
                            <div class="filter-actions">
                                <button class="btn btn-outline btn-xs" onclick="clearFilter(<?= $ci ?>)">清除筛选</button>
                                <button class="btn btn-primary btn-xs" onclick="applyFilter(<?= $ci ?>);closeAllFilters();event.stopPropagation()">确定</button>
                            </div>
                        </div>
                    </th>
                <?php endforeach; ?>
                <th>操作</th></tr></thead>
                <tbody>
                <?php foreach ($rows as $rowIdx => $row):
                    $displayIdx = $offset + $rowIdx + 1;
                    $pkVal = $row[$pk] ?? '';
                ?>
                <tr data-row="<?= $rowIdx ?>">
                    <td class="row-num"><?= $displayIdx ?></td>
                    <?php foreach ($columns as $ci => $col): ?>
                    <td data-col="<?= $ci ?>"><?= h(mb_strlen($row[$col['Field']] ?? '') > 100 ? mb_substr($row[$col['Field']], 0, 100) . '...' : ($row[$col['Field']] ?? '')) ?></td>
                    <?php endforeach; ?>
                    <td style="white-space:nowrap">
                        <button class="btn btn-outline btn-xs" onclick="openEdit('<?= h($table) ?>','<?= h($pk) ?>','<?= h($pkVal) ?>',<?= htmlspecialchars(json_encode($row, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>)">编辑</button>
                        <form method="post" style="display:inline" onsubmit="return confirm('确定删除?')">
                            <input type="hidden" name="action" value="mysql_delete_row">
                            <input type="hidden" name="table" value="<?= h($table) ?>">
                            <input type="hidden" name="pk_col" value="<?= h($pk) ?>">
                            <input type="hidden" name="pk_val" value="<?= h($pkVal) ?>">
                            <button class="btn btn-outline btn-xs" style="color:#c92a2a">删除</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <div id="noFilterResult" style="display:none;padding:20px;text-align:center;color:#999;font-size:13px">没有匹配的数据</div>
            <?php else: ?>
            <div class="empty">暂无数据</div>
            <?php endif; ?>

            <!-- 分页导航（下方） -->
            <?php if ($totalPages > 1): ?>
            <div class="pagination-bar" style="margin-top:12px">
                <span style="font-size:12px;color:#999">共 <?= $totalRows ?> 条，<?= $totalPages ?> 页</span>
                <div class="pagination-btns">
                    <?php if ($page > 1): ?>
                    <a href="?tab=mysql&sub=table_data&table=<?= urlencode($table) ?>&page=1&perpage=<?= $perPage ?><?= $filterQuery ?>" class="btn btn-outline btn-xs">首页</a>
                    <a href="?tab=mysql&sub=table_data&table=<?= urlencode($table) ?>&page=<?= $page-1 ?>&perpage=<?= $perPage ?><?= $filterQuery ?>" class="btn btn-outline btn-xs">上一页</a>
                    <?php endif; ?>
                    <span class="pagination-num active"><?= $page ?> / <?= $totalPages ?></span>
                    <?php if ($page < $totalPages): ?>
                    <a href="?tab=mysql&sub=table_data&table=<?= urlencode($table) ?>&page=<?= $page+1 ?>&perpage=<?= $perPage ?><?= $filterQuery ?>" class="btn btn-outline btn-xs">下一页</a>
                    <a href="?tab=mysql&sub=table_data&table=<?= urlencode($table) ?>&page=<?= $totalPages ?>&perpage=<?= $perPage ?><?= $filterQuery ?>" class="btn btn-outline btn-xs">末页</a>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
<script>
window._mysqlColTypes = <?= json_encode(array_reduce($columns, function($map, $col) { $map[$col['Field']] = $col['Type']; return $map; }, [])) ?>;
window._currentMysqlTable = <?= json_encode($table) ?>;
window._currentMysqlPerPage = <?= $perPage ?>;
window._mysqlFilterQuery = <?= json_encode($filterQuery) ?>;
</script>
    <?php } ?>


    <?php elseif ($sub === 'table_structure'):
        $table = get('table');
        if ($table) {
            list($pdo, $err) = mysqlConnect();
            if ($pdo) {
                $colStmt = $pdo->query("DESCRIBE `{$table}`");
                $columns = $colStmt->fetchAll();
                $createStmt = $pdo->query("SHOW CREATE TABLE `{$table}`");
                $create = $createStmt->fetch();
            } else { $columns = []; $create = []; }
    ?>
    <div class="card">
        <div class="card-header">表结构: <?= h($table) ?></div>
        <div class="card-body">
            <table>
                <thead><tr><th>字段</th><th>类型</th><th>允许NULL</th><th>键</th><th>默认值</th><th>Extra</th></tr></thead>
                <tbody>
                <?php foreach ($columns as $col): ?>
                <tr>
                    <td><strong><?= h($col['Field']) ?></strong></td>
                    <td><?= h($col['Type']) ?></td>
                    <td><?= $col['Null'] === 'YES' ? '<span class="badge badge-green">YES</span>' : '<span class="badge badge-red">NO</span>' ?></td>
                    <td><?= h($col['Key'] ?: '-') ?></td>
                    <td><?= h($col['Default'] ?? 'NULL') ?></td>
                    <td><?= h($col['Extra'] ?: '-') ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php if (!empty($create)): ?>
            <h4 style="margin-top:16px;margin-bottom:4px">建表语句</h4>
            <div class="code-block"><?= h($create['Create Table']) ?></div>
            <?php endif; ?>
        </div>
    </div>
    <?php } ?>

    <?php elseif ($sub === 'table_design'):
        $table = get('table');
        if (!$table) { echo '<div class="flash flash-error">未指定表名</div>'; }
        else {
            list($pdo, $err) = mysqlConnect();
            if ($pdo) {
                $colStmt = $pdo->query("DESCRIBE `{$table}`");
                $columns = $colStmt->fetchAll();
                // 索引信息
                $idxStmt = $pdo->query("SHOW INDEX FROM `{$table}`");
                $indexesRaw = $idxStmt->fetchAll();
                // 合并索引为每个索引一条记录
                $indexes = [];
                foreach ($indexesRaw as $ir) {
                    $kn = $ir['Key_name'];
                    if (!isset($indexes[$kn])) {
                        $indexes[$kn] = ['name'=>$kn, 'columns'=>[], 'unique'=>$ir['Non_unique']==0, 'type'=>'INDEX'];
                    }
                    $indexes[$kn]['columns'][] = $ir['Column_name'];
                    if ($kn === 'PRIMARY') $indexes[$kn]['type'] = 'PRIMARY';
                    elseif ($ir['Index_type'] === 'FULLTEXT') $indexes[$kn]['type'] = 'FULLTEXT';
                    elseif ($ir['Non_unique'] == 0) $indexes[$kn]['type'] = 'UNIQUE';
                }
            } else { $columns = []; $indexes = []; }
            // 数据类型选项
            $typeOptions = ['INT','BIGINT','TINYINT','SMALLINT','VARCHAR','CHAR','TEXT','MEDIUMTEXT','LONGTEXT',
                'DATE','DATETIME','TIMESTAMP','TIME','FLOAT','DOUBLE','DECIMAL','BOOLEAN','JSON','ENUM','SET','BLOB'];
    ?>
    <div class="card">
        <div class="card-header">
            可视化设计表: <?= h($table) ?>
            <div style="display:flex;gap:6px">
                <a href="?tab=mysql&sub=table_data&table=<?= urlencode($table) ?>" class="btn btn-outline btn-xs">返回数据</a>
            </div>
        </div>
        <div class="card-body">
            <form method="post" id="designForm">
                <input type="hidden" name="action" value="mysql_table_design_save">
                <input type="hidden" name="table" value="<?= h($table) ?>">
                <div class="form-group">
                    <label>表名</label>
                    <input name="table_name" value="<?= h($table) ?>" style="max-width:300px" pattern="[a-zA-Z_][a-zA-Z0-9_]*">
                </div>

                <!-- 标签切换 -->
                <div class="design-tabs">
                    <div class="design-tab active" onclick="switchDesignTab(event,'fields')">字段</div>
                    <div class="design-tab" onclick="switchDesignTab(event,'indexes')">索引</div>
                    <div class="design-tab" onclick="switchDesignTab(event,'sql')">SQL 预览</div>
                </div>

                <!-- 字段面板 -->
                <div class="design-panel active" id="panel-fields">
                    <div class="field-header">
                        <span class="col-name">字段名</span>
                        <span class="col-type">数据类型</span>
                        <span class="col-len">长度</span>
                        <span class="col-dec">小数</span>
                        <span class="col-check" title="主键">PK</span>
                        <span class="col-check" title="非空">非空</span>
                        <span class="col-check" title="自增">自增</span>
                        <span class="col-name">默认值</span>
                        <span class="col-comment">注释</span>
                        <span class="col-action"></span>
                    </div>
                    <div id="fieldRows">
                    <?php foreach ($columns as $ci => $col):
                        $type = $col['Type'];
                        $baseType = $type; $length = ''; $decimal = '';
                        if (preg_match('/^(\w+)\((\d+)(?:,(\d+))?\)/', $type, $m)) {
                            $baseType = strtoupper($m[1]);
                            $length = $m[2];
                            $decimal = $m[3] ?? '';
                        } else {
                            $baseType = strtoupper(explode(' ', $type)[0]);
                        }
                    ?>
                    <div class="field-row">
                        <input type="text" class="col-name" name="fld_name[]" value="<?= h($col['Field']) ?>">
                        <select class="col-type" name="fld_type[]" onchange="onTypeChange(this)">
                            <?php foreach ($typeOptions as $to): ?>
                            <option value="<?= $to ?>" <?= $baseType===$to?'selected':'' ?>><?= $to ?></option>
                            <?php endforeach; ?>
                        </select>
                        <input type="number" class="col-len" name="fld_len[]" value="<?= h($length) ?>" min="0">
                        <input type="number" class="col-dec" name="fld_dec[]" value="<?= h($decimal) ?>" min="0">
                        <span class="col-check"><input type="checkbox" name="fld_pk[]" value="<?= $ci ?>" <?= $col['Key']==='PRI'?'checked':'' ?> onchange="onPkChange(this)"></span>
                        <span class="col-check"><input type="checkbox" name="fld_null[]" value="<?= $ci ?>" <?= $col['Null']==='YES'?'':'checked' ?>></span>
                        <span class="col-check"><input type="checkbox" name="fld_ai[]" value="<?= $ci ?>" <?= $col['Extra']==='auto_increment'?'checked':'' ?> onchange="onAiChange(this)" <?= in_array($baseType,['INT','BIGINT','TINYINT','SMALLINT','MEDIUMINT']) ? '' : 'disabled' ?>></span>
                        <input type="text" class="col-name" name="fld_default[]" value="<?= h($col['Default'] ?? '') ?>" placeholder="NULL">
                        <input type="text" class="col-comment" name="fld_comment[]" placeholder="字段注释">
                        <button type="button" class="btn btn-outline btn-xs" style="color:#c92a2a" onclick="this.closest('.field-row').remove()" title="删除字段">✕</button>
                    </div>
                    <?php endforeach; ?>
                    </div>
                    <script>var fieldRowCounter = <?= count($columns) ?>;</script>
                    <button type="button" class="btn btn-outline btn-sm" style="margin-top:8px" onclick="addFieldRow()">+ 添加字段</button>
                </div>

                <!-- 索引面板 -->
                <div class="design-panel" id="panel-indexes">
                    <div class="idx-header">
                        <span class="idx-name">索引名称</span>
                        <span class="idx-type">索引类型</span>
                        <span class="idx-cols">索引列（逗号分隔）</span>
                        <span class="col-action"></span>
                    </div>
                    <div id="idxRows">
                    <?php foreach ($indexes as $idx): if ($idx['type'] === 'PRIMARY') continue; ?>
                    <div class="idx-row">
                        <input type="text" class="idx-name" name="idx_name[]" value="<?= h($idx['name']) ?>">
                        <select class="idx-type" name="idx_type[]">
                            <option value="INDEX" <?= $idx['type']==='INDEX'?'selected':'' ?>>普通索引</option>
                            <option value="UNIQUE" <?= $idx['type']==='UNIQUE'?'selected':'' ?>>唯一索引</option>
                            <option value="FULLTEXT" <?= $idx['type']==='FULLTEXT'?'selected':'' ?>>全文索引</option>
                        </select>
                        <input type="text" class="idx-cols" name="idx_cols[]" value="<?= h(implode(',',$idx['columns'])) ?>" placeholder="字段名,字段名2">
                        <button type="button" class="btn btn-outline btn-xs" style="color:#c92a2a" onclick="this.closest('.idx-row').remove()">✕</button>
                    </div>
                    <?php endforeach; ?>
                    </div>
                    <button type="button" class="btn btn-outline btn-sm" style="margin-top:8px" onclick="addIdxRow()">+ 添加索引</button>
                </div>

                <!-- SQL 预览 -->
                <div class="design-panel" id="panel-sql">
                    <div class="sql-preview" id="sqlPreview">点击"刷新预览"查看将要执行的 SQL 语句</div>
                    <button type="button" class="btn btn-outline btn-sm" style="margin-top:8px" onclick="generatePreview()">刷新预览</button>
                </div>

                <div style="margin-top:20px;display:flex;gap:8px">
                    <button type="submit" class="btn btn-primary" onclick="return confirm('确定保存表结构修改？此操作会删除不存在的列和索引！')">保存设计</button>
                    <a href="?tab=mysql&sub=table_data&table=<?= urlencode($table) ?>" class="btn btn-outline">取消</a>
                </div>
            </form>
        </div>
    </div>
    <?php } ?>

    <?php elseif ($sub === 'query'): ?>
    <div class="card">
        <div class="card-header">执行 SQL 查询</div>
        <div class="card-body">
            <form method="post">
                <input type="hidden" name="action" value="mysql_query">
                <div class="form-group">
                    <textarea name="sql_query" style="min-height:120px;font-family:monospace" placeholder="输入 SQL 语句...&#10;支持 &lt; &gt; = 等所有 SQL 运算符&#10;例如: SELECT * FROM users WHERE age > 18 AND score < 60"><?= h($_SESSION['mysql_last_sql'] ?? '') ?></textarea>
                </div>
                <button class="btn btn-primary" type="submit">执行</button>
            </form>

            <?php if (isset($_SESSION['mysql_last_sql_error']) && $_SESSION['mysql_last_sql_error']): ?>
            <div class="flash flash-error" style="margin-top:12px"><?= h($_SESSION['mysql_last_sql_error']) ?></div>
            <?php endif; ?>

            <?php if (isset($_SESSION['mysql_last_sql_result']) && $_SESSION['mysql_last_sql_result']): ?>
            <div style="margin-top:12px">
                <span class="badge badge-blue"><?= count($_SESSION['mysql_last_sql_result']) ?> 行</span>
            </div>
            <div style="overflow-x:auto;margin-top:8px">
            <table>
                <thead><tr>
                    <?php foreach ($_SESSION['mysql_last_sql_columns'] as $col): ?>
                    <th><?= h($col) ?></th>
                    <?php endforeach; ?>
                </tr></thead>
                <tbody>
                <?php foreach ($_SESSION['mysql_last_sql_result'] as $r): ?>
                <tr>
                    <?php foreach ($_SESSION['mysql_last_sql_columns'] as $col): ?>
                    <td><?= h(mb_strlen((string)($r[$col] ?? '')) > 150 ? mb_substr((string)($r[$col]), 0, 150) . '...' : ($r[$col] ?? '')) ?></td>
                    <?php endforeach; ?>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <?php elseif (isset($_SESSION['mysql_last_affected']) && $_SESSION['mysql_last_affected'] > 0 && !isset($_SESSION['mysql_last_sql_error'])): ?>
            <div style="margin-top:12px"><span class="badge badge-green">影响行数: <?= $_SESSION['mysql_last_affected'] ?></span></div>
            <?php endif; ?>

            <?php
            // 清理一次，防止刷新后重复显示
            unset($_SESSION['mysql_last_sql'], $_SESSION['mysql_last_sql_error'], $_SESSION['mysql_last_sql_result'], $_SESSION['mysql_last_sql_columns'], $_SESSION['mysql_last_affected']);
            ?>
        </div>
    </div>

    <?php elseif ($sub === 'import'): ?>
    <div class="card">
        <div class="card-header">导入数据 —— 导入到数据库</div>
        <div class="card-body">
            <p class="text-muted mb-sm">上传 .sql 文件（可包含多条建表/插入语句），导入到当前数据库 <strong><?= h($config['dbname']) ?></strong></p>
            <form method="post" enctype="multipart/form-data">
                <input type="hidden" name="action" value="mysql_import">
                <input type="hidden" name="import_mode" value="database">
                <div class="form-group"><label>选择 SQL 文件</label><input type="file" name="import_file" accept=".sql" required></div>

                <div class="form-group">
                    <label>遇到已存在的表时：</label>
                    <div class="radio-group">
                        <label class="radio-item"><input type="radio" name="table_action" value="skip"> 跳过（仅执行表结构，不重复建表）</label>
                        <label class="radio-item"><input type="radio" name="table_action" value="drop_recreate"> 删除并重建（DROP TABLE IF EXISTS 再 CREATE TABLE）</label>
                        <label class="radio-item"><input type="radio" name="table_action" value="append" checked> 追加数据（忽略建表语句，仅执行INSERT）</label>
                    </div>
                </div>

                <div class="form-group">
                    <label>遇到主键重复时：</label>
                    <div class="radio-group">
                        <label class="radio-item"><input type="radio" name="pk_action" value="skip" checked> 跳过该行（INSERT IGNORE，保留已有数据）</label>
                        <label class="radio-item"><input type="radio" name="pk_action" value="update"> 更新该行（ON DUPLICATE KEY UPDATE）</label>
                        <label class="radio-item"><input type="radio" name="pk_action" value="error"> 报错终止（保留原始行为）</label>
                    </div>
                </div>

                <button class="btn btn-primary" type="submit">导入到数据库</button>
            </form>
        </div>
    </div>
    <div class="card">
        <div class="card-header">导入数据 —— 导入到表</div>
        <div class="card-body">
            <p class="text-muted mb-sm">选择导入格式，将数据导入到指定表中</p>
            <form method="post" enctype="multipart/form-data">
                <input type="hidden" name="action" value="mysql_import">
                <input type="hidden" name="import_mode" value="table">
                <div class="form-group">
                    <label>导入格式：</label>
                    <div class="radio-group">
                        <label class="radio-item"><input type="radio" name="table_import_format" value="csv" checked onchange="document.getElementById('mysqlTableFile').accept='.csv';document.getElementById('mysqlTableFileLabel').textContent='选择 CSV 文件（首行必须为列名）'"> CSV 文件（首行必须为列名）</label>
                        <label class="radio-item"><input type="radio" name="table_import_format" value="sql" onchange="document.getElementById('mysqlTableFile').accept='.sql';document.getElementById('mysqlTableFileLabel').textContent='选择 SQL 文件（含 INSERT 语句）'"> SQL 文件（含 INSERT 语句）</label>
                    </div>
                </div>
                <div class="form-group"><label id="mysqlTableFileLabel">选择 CSV 文件（首行必须为列名）</label><input type="file" name="import_file" id="mysqlTableFile" accept=".csv" required></div>
                <div class="form-group"><label>目标表名</label><input name="import_table" placeholder="例如: users" required></div>
                <button class="btn btn-primary" type="submit">导入到表</button>
            </form>
        </div>
    </div>

    <?php elseif ($sub === 'export'):
        if (empty($config['dbname'])) {
            echo '<div class="flash flash-error">请先在"数据库管理"中选择一个数据库</div>';
        } else {
            list($pdo, $err) = mysqlConnect();
            $tables = [];
            if ($pdo) {
                $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
            }
    ?>
    <div class="card">
        <div class="card-header">导出 —— 整个数据库</div>
        <div class="card-body">
            <p class="text-muted mb-sm">将数据库 <strong><?= h($config['dbname']) ?></strong> 中所有表的结构 + 数据导出为一个 .sql 文件</p>
            <a href="?tab=mysql&action=mysql_export_sql&type=database" class="btn btn-primary">导出整个数据库 (SQL)</a>
        </div>
    </div>
    <div class="card">
        <div class="card-header">导出 —— 单个表</div>
        <div class="card-body">
            <?php if ($tables): ?>
            <p class="text-muted mb-sm">选择要导出的表，可选 SQL 或 CSV 格式</p>
            <table>
                <thead><tr><th>表名</th><th>操作</th></tr></thead>
                <tbody>
                <?php foreach ($tables as $t): ?>
                <tr>
                    <td><strong><?= h($t) ?></strong></td>
                    <td>
                        <a href="?tab=mysql&action=mysql_export_sql&table=<?= urlencode($t) ?>&type=table" class="btn btn-outline btn-sm">导出 SQL</a>
                        <a href="?tab=mysql&action=mysql_export_csv&table=<?= urlencode($t) ?>" class="btn btn-outline btn-sm">导出 CSV</a>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php else: ?>
            <div class="empty">暂无数据表</div>
            <?php endif; ?>
        </div>
    </div>
    <?php } ?>

    <?php else: // 默认首页: 数据库概览
        if (!empty($config['dbname'])):
            list($pdo, $err) = mysqlConnect();
            if ($pdo):
                $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
                // 版本
                $ver = $pdo->query("SELECT VERSION()")->fetchColumn();
    ?>
    <div class="card">
        <div class="card-header">数据库概览 - <?= h($config['dbname']) ?></div>
        <div class="card-body">
            <div class="info-grid">
                <div class="info-item"><div class="label">服务器版本</div><div class="value"><?= h($ver) ?></div></div>
                <div class="info-item"><div class="label">表数量</div><div class="value"><?= count($tables) ?></div></div>
                <div class="info-item"><div class="label">字符集</div><div class="value">utf8mb4</div></div>
            </div>
            <a href="?tab=mysql&action=mysql_export_sql&type=database" class="btn btn-outline btn-sm">导出整个数据库 SQL</a>
        </div>
    </div>
    <?php endif; endif; ?>
    <?php endif; ?>
<?php endif; ?>

<?php endif; // MYSQL END ?>

<?php
// ============================================================
// SQLITE 渲染
// ============================================================
if ($tab === 'sqlite'):
    $config = $_SESSION['sqlite_config'] ?? null;
    $connected = !empty($config);

    if (!$connected): ?>
    <div class="card">
        <div class="card-header">打开 SQLite 数据库</div>
        <div class="card-body">
            <form method="post">
                <input type="hidden" name="action" value="sqlite_connect">
                <div class="form-group"><label>SQLite 文件路径</label><input name="path" placeholder="例如: /data/mydb.sqlite 或 C:\data\mydb.db" required></div>
                <div class="form-group"><label><input type="checkbox" name="force_open" value="1"> 强制打开（文件不存在时自动创建）</label></div>
                <button class="btn btn-primary" type="submit">打开</button>
            </form>
        </div>
    </div>
<?php else: ?>
    <div class="info-grid">
        <div class="info-item"><div class="label">文件路径</div><div class="value"><?= h($config['path']) ?></div></div>
        <div class="info-item"><div class="label">文件大小</div><div class="value"><?= file_exists($config['path']) ? number_format(filesize($config['path']) / 1024, 2) . ' KB' : 'N/A' ?></div></div>
    </div>

    <div style="margin-bottom:16px;display:flex;gap:8px;flex-wrap:wrap">
        <a href="?tab=sqlite&sub=tables" class="btn btn-outline btn-sm <?= $sub==='tables'?'active':'' ?>" style="<?= $sub==='tables'?'background:#e94560;color:#fff;border-color:#e94560':'' ?>">数据表管理</a>
        <a href="?tab=sqlite&sub=query"  class="btn btn-outline btn-sm <?= $sub==='query'?'active':'' ?>" style="<?= $sub==='query'?'background:#e94560;color:#fff;border-color:#e94560':'' ?>">SQL 查询</a>
        <a href="?tab=sqlite&sub=import" class="btn btn-outline btn-sm <?= $sub==='import'?'active':'' ?>" style="<?= $sub==='import'?'background:#e94560;color:#fff;border-color:#e94560':'' ?>">导入</a>
        <a href="batch_sql.php?tab=sqlite" class="btn btn-outline btn-sm" style="color:#e94560;font-weight:600;border-color:#e94560">批量SQL</a>
        <a href="?tab=sqlite&sub=export" class="btn btn-outline btn-sm <?= $sub==='export'?'active':'' ?>" style="<?= $sub==='export'?'background:#e94560;color:#fff;border-color:#e94560':'' ?>">导出</a>
        <a href="?tab=sqlite&action=disconnect" class="btn btn-outline btn-sm" style="margin-left:auto;color:#c92a2a" onclick="return confirm('确定断开?')">断开</a>
    </div>

    <?php
    if ($sub === 'tables'):
        list($pdo, $err) = sqliteConnect();
        $tables = [];
        if ($pdo) {
            $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
        }
    ?>
    <div class="card">
        <div class="card-header">数据表列表</div>
        <div class="card-body">
            <form method="post" style="margin-bottom:16px">
                <input type="hidden" name="action" value="sqlite_create_table">
                <div class="form-row" style="align-items:flex-end">
                    <div class="form-group"><label>表名</label><input name="new_table" placeholder="表名" required pattern="[a-zA-Z_][a-zA-Z0-9_]*"></div>
                    <div class="form-group" style="flex:2"><label>建表 SQL</label><textarea name="create_sql" placeholder="CREATE TABLE xxx (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT)" required style="min-height:40px"></textarea></div>
                    <button class="btn btn-primary btn-sm" type="submit" style="height:fit-content">创建表</button>
                </div>
            </form>
            <?php if ($tables): ?>
            <table>
                <thead><tr><th>表名</th><th>操作</th></tr></thead>
                <tbody>
                <?php foreach ($tables as $t): ?>
                <tr>
                    <td><strong><?= h($t) ?></strong></td>
                    <td>
                        <a href="?tab=sqlite&sub=table_data&table=<?= urlencode($t) ?>" class="btn btn-outline btn-xs">数据</a>
                        <a href="?tab=sqlite&action=sqlite_export_sql&table=<?= urlencode($t) ?>" class="btn btn-outline btn-xs">导出SQL</a>
                        <a href="?tab=sqlite&action=sqlite_export_csv&table=<?= urlencode($t) ?>" class="btn btn-outline btn-xs">导出CSV</a>
                        <form method="post" style="display:inline" onsubmit="return confirm('确定删除表 `<?= h($t) ?>`?')">
                            <input type="hidden" name="action" value="sqlite_drop_table">
                            <input type="hidden" name="table" value="<?= h($t) ?>">
                            <button class="btn btn-outline btn-xs" style="color:#c92a2a">删除</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php else: ?>
            <div class="empty">暂无数据表</div>
            <?php endif; ?>
        </div>
    </div>

    <?php elseif ($sub === 'table_data'):
        $table = get('table');
        if ($table):
            list($pdo, $err) = sqliteConnect();
            // 分页参数
            $perPage = (int)(get('perpage', '50'));
            if ($perPage < 10) $perPage = 10;
            if ($perPage > 500) $perPage = 500;
            $page = max(1, (int)get('page', '1'));
            $numCols = [];
            $allColsSqlite = [];
            if ($pdo) {
                $colStmt = $pdo->query("PRAGMA table_info(`{$table}`)");
                $columns = $colStmt->fetchAll();
                foreach ($columns as $ci => $col) {
                    $allColsSqlite[] = $col['name'];
                    $t = strtolower($col['type']);
                    if (preg_match('/^(int|integer|bigint|tinyint|smallint|float|double|decimal|numeric|real)/',$t)) {
                        $numCols[] = $col['name'];
                    }
                }
                // ===== 解析筛选参数（SQLite）=====
                $filtersSqlite = [];
                $wherePartsS = [];
                $whereParamsS = [];
                $filterQuery = '';
                foreach ($_GET as $k => $v) {
                    if (preg_match('/^f_(\d+)$/', $k, $m)) {
                        $ci = (int)$m[1];
                        $filtersSqlite[$ci] = $v;
                        $filterQuery .= '&f_' . $ci . '=' . urlencode($v);
                        if (!isset($columns[$ci])) continue;
                        $fieldName = $columns[$ci]['name'];
                        $parts = explode(':', $v, 3);
                        $op = $parts[0];
                        $val = $parts[1] ?? '';
                        $toVal = $parts[2] ?? '';
                        if ($op === 'between') {
                            if ($val !== '' && $toVal !== '') {
                                $wherePartsS[] = "\"{$fieldName}\" BETWEEN :f_{$ci}_from AND :f_{$ci}_to";
                                $whereParamsS[":f_{$ci}_from"] = $val;
                                $whereParamsS[":f_{$ci}_to"] = $toVal;
                            } elseif ($val !== '') {
                                $wherePartsS[] = "\"{$fieldName}\" >= :f_{$ci}_from";
                                $whereParamsS[":f_{$ci}_from"] = $val;
                            } elseif ($toVal !== '') {
                                $wherePartsS[] = "\"{$fieldName}\" <= :f_{$ci}_to";
                                $whereParamsS[":f_{$ci}_to"] = $toVal;
                            }
                        } elseif ($op === 'empty') {
                            $wherePartsS[] = "(\"{$fieldName}\" IS NULL OR \"{$fieldName}\" = '')";
                        } elseif ($op === 'not_empty') {
                            $wherePartsS[] = "(\"{$fieldName}\" IS NOT NULL AND \"{$fieldName}\" != '')";
                        } elseif ($op === 'contains') {
                            $wherePartsS[] = "\"{$fieldName}\" LIKE :f_{$ci}";
                            $whereParamsS[":f_{$ci}"] = "%{$val}%";
                        } elseif ($op === 'not_contains') {
                            $wherePartsS[] = "\"{$fieldName}\" NOT LIKE :f_{$ci}";
                            $whereParamsS[":f_{$ci}"] = "%{$val}%";
                        } elseif ($op === 'starts') {
                            $wherePartsS[] = "\"{$fieldName}\" LIKE :f_{$ci}";
                            $whereParamsS[":f_{$ci}"] = "{$val}%";
                        } elseif ($op === 'ends') {
                            $wherePartsS[] = "\"{$fieldName}\" LIKE :f_{$ci}";
                            $whereParamsS[":f_{$ci}"] = "%{$val}";
                        } elseif ($op === 'equals') {
                            $wherePartsS[] = "\"{$fieldName}\" = :f_{$ci}";
                            $whereParamsS[":f_{$ci}"] = $val;
                        } elseif ($op === 'not_equals') {
                            $wherePartsS[] = "\"{$fieldName}\" != :f_{$ci}";
                            $whereParamsS[":f_{$ci}"] = $val;
                        } elseif ($op === 'greater_than') {
                            $wherePartsS[] = "\"{$fieldName}\" > :f_{$ci}";
                            $whereParamsS[":f_{$ci}"] = $val;
                        } elseif ($op === 'less_than') {
                            $wherePartsS[] = "\"{$fieldName}\" < :f_{$ci}";
                            $whereParamsS[":f_{$ci}"] = $val;
                        } elseif ($op === 'greater_equal') {
                            $wherePartsS[] = "\"{$fieldName}\" >= :f_{$ci}";
                            $whereParamsS[":f_{$ci}"] = $val;
                        } elseif ($op === 'less_equal') {
                            $wherePartsS[] = "\"{$fieldName}\" <= :f_{$ci}";
                            $whereParamsS[":f_{$ci}"] = $val;
                        }
                    }
                }
                $whereClauseS = !empty($wherePartsS) ? ' WHERE ' . implode(' AND ', $wherePartsS) : '';
                // ===== 筛选解析结束 =====

                $countStmt = $pdo->prepare("SELECT COUNT(*) FROM `{$table}`" . $whereClauseS);
                $countStmt->execute($whereParamsS);
                $totalRows = $countStmt->fetchColumn();
                $totalPages = max(1, ceil($totalRows / $perPage));
                if ($page > $totalPages) $page = $totalPages;
                $offset = ($page - 1) * $perPage;
                $dataStmt = $pdo->prepare("SELECT * FROM `{$table}`" . $whereClauseS . " LIMIT {$perPage} OFFSET {$offset}");
                $dataStmt->execute($whereParamsS);
                $rows = $dataStmt->fetchAll();
                $pk = 'id';
                foreach ($columns as $col) { if ($col['pk'] == 1) { $pk = $col['name']; break; } }
                // 聚合统计：自动对所有数值型字段计算 SUM/MAX/MIN/AVG
                $aggResults = [];
                foreach ($numCols as $nc) {
                    try {
                        $aggStmt = $pdo->prepare("SELECT SUM(\"{$nc}\") AS s, MAX(\"{$nc}\") AS mx, MIN(\"{$nc}\") AS mn, AVG(\"{$nc}\") AS a FROM `{$table}`" . $whereClauseS);
                        $aggStmt->execute($whereParamsS);
                        $r = $aggStmt->fetch();
                        $aggResults[] = ['col'=>$nc, 'sum'=>$r['s'], 'max'=>$r['mx'], 'min'=>$r['mn'], 'avg'=>$r['a']];
                    } catch (Exception $e) { $aggResults[] = ['col'=>$nc, 'error'=>$e->getMessage()]; }
                }
                // 收入/支出/结余统计：用户可手动选择数值列，支持 ?ie_col=xxx 参数
                $incomeExpense = null;
                $ieCol = null;
                // 1) 优先使用用户手动选择的列（从 URL 参数 ie_col 读取）
                if (isset($_GET['ie_col']) && in_array($_GET['ie_col'], $numCols, true)) {
                    $ieCol = $_GET['ie_col'];
                }
                // 2) 兜底：自动匹配常用金额字段名
                if (!$ieCol) {
                    foreach ($allColsSqlite as $c) {
                        $cl = strtolower($c);
                        if (in_array($cl, ['amount', 'jine', 'money', 'jin_e', '金额', 'price', 'fee', 'je', 'balance', 'total'], true)) {
                            $ieCol = $c; break;
                        }
                    }
                }
                // 3) 最终兜底：用第一个数值列
                if (!$ieCol && !empty($numCols)) { $ieCol = $numCols[0]; }
                if ($ieCol) {
                    try {
                        $ieStmt = $pdo->prepare("SELECT SUM(\"{$ieCol}\") AS total, SUM(CASE WHEN \"{$ieCol}\" > 0 THEN \"{$ieCol}\" ELSE 0 END) AS income, SUM(CASE WHEN \"{$ieCol}\" < 0 THEN \"{$ieCol}\" ELSE 0 END) AS expense FROM `{$table}`" . $whereClauseS);
                        $ieStmt->execute($whereParamsS);
                        $r = $ieStmt->fetch();
                        $incomeExpense = ['col'=>$ieCol, 'income'=>$r['income'], 'expense'=>$r['expense'], 'balance'=>$r['total']];
                    } catch (Exception $e) { $incomeExpense = ['error'=>$e->getMessage()]; }
                }
            } else { $columns = []; $rows = []; $totalRows = 0; $totalPages = 1; $offset = 0; $pk = 'id'; $aggResults = []; $allColsSqlite = []; $incomeExpense = null; }
    ?>
    <div class="card">
        <div class="card-header">
            表 <?= h($table) ?>
            <span class="badge badge-blue"><?= $totalRows ?> 行</span>
            <?php if ($totalRows > 0): ?>
            <span style="font-size:12px;color:#999;margin-left:8px">当前第 <?= $offset+1 ?>-<?= min($offset+$perPage, $totalRows) ?> 条</span>
            <?php endif; ?>
        </div>
        <div class="card-body">
            <details style="margin-bottom:16px">
                <summary style="cursor:pointer;font-weight:600">+ 新增记录</summary>
                <form method="post" style="margin-top:12px;padding:12px;background:#f8f9fa;border-radius:6px">
                    <input type="hidden" name="action" value="sqlite_insert">
                    <input type="hidden" name="table" value="<?= h($table) ?>">
                    <?php foreach ($columns as $col): if ($col['pk'] == 1) continue;
                        $sqliteInsertType = strtoupper($col['type'] ?? '');
                        $isDateInsertS = (strpos($sqliteInsertType, 'DATE') === 0 && strpos($sqliteInsertType, 'DATETIME') === false);
                    ?>
                    <div class="form-group"><label><?= h($col['name']) ?> (<?= h($col['type']) ?>)</label><input type="<?= $isDateInsertS ? 'date' : 'text' ?>" name="col_<?= h($col['name']) ?>"></div>
                    <?php endforeach; ?>
                    <button class="btn btn-primary btn-sm" type="submit">添加</button>
                </form>
            </details>

            <!-- 聚合统计栏：自动对所有数值列计算 -->
            <?php if (!empty($aggResults) || $incomeExpense): ?>
            <div class="agg-bar">
                <span class="agg-label">📊 聚合统计（基于筛选结果）</span>
                <?php if (!empty($aggResults)): ?>
                <table class="agg-table">
                    <thead><tr><th>列名</th><th>求和</th><th>最大值</th><th>最小值</th><th>平均值</th></tr></thead>
                    <tbody>
                    <?php foreach ($aggResults as $ar): ?>
                        <tr>
                            <td class="agg-col-name"><?= h($ar['col']) ?></td>
                            <?php if (isset($ar['error'])): ?>
                            <td colspan="4" style="color:#c92a2a;text-align:center">错误: <?= h($ar['error']) ?></td>
                            <?php else: ?>
                            <td class="agg-val"><?= is_numeric($ar['sum']) ? number_format((float)$ar['sum'], 2) : h($ar['sum']) ?></td>
                            <td class="agg-val"><?= is_numeric($ar['max']) ? number_format((float)$ar['max'], 2) : h($ar['max']) ?></td>
                            <td class="agg-val"><?= is_numeric($ar['min']) ? number_format((float)$ar['min'], 2) : h($ar['min']) ?></td>
                            <td class="agg-val"><?= is_numeric($ar['avg']) ? number_format((float)$ar['avg'], 2) : h($ar['avg']) ?></td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
                <?php if ($incomeExpense && !isset($incomeExpense['error'])): ?>
                <div class="agg-ie-summary">
                    <?php if (count($numCols) > 1): ?>
                    <span class="agg-ie-item" style="display:flex;align-items:center;gap:4px">
                        <span style="font-size:11px;color:#888">收支列：</span>
                        <select onchange="var u=new URL(location.href);u.searchParams.set('ie_col',this.value);u.searchParams.set('page','1');location.href=u.toString()" class="agg-ie-select">
                            <?php foreach ($numCols as $nc): ?>
                            <option value="<?= h($nc) ?>" <?= $nc === $ieCol ? 'selected' : '' ?>><?= h($nc) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </span>
                    <?php else: ?>
                    <span class="agg-ie-item" style="font-size:11px;color:#888">收支列：<?= h($ieCol) ?></span>
                    <?php endif; ?>
                    <span class="agg-ie-item agg-ie-income">💰 总收入（<?= h($incomeExpense['col']) ?>&gt;0）：<strong><?= number_format((float)$incomeExpense['income'], 2) ?></strong></span>
                    <span class="agg-ie-item agg-ie-expense">💸 总支出（<?= h($incomeExpense['col']) ?>&lt;0）：<strong><?= number_format((float)$incomeExpense['expense'], 2) ?></strong></span>
                    <span class="agg-ie-item agg-ie-balance">📈 总结余（<?= h($incomeExpense['col']) ?>）：<strong><?= number_format((float)$incomeExpense['balance'], 2) ?></strong></span>
                </div>
                <?php elseif ($incomeExpense && isset($incomeExpense['error'])): ?>
                <span style="color:#c92a2a;font-size:12px">收支统计错误: <?= h($incomeExpense['error']) ?></span>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <!-- 分页导航（上方） -->
            <?php if ($totalPages > 1): ?>
            <div class="pagination-bar">
                <div class="pagination-info">每页
                    <select onchange="location.href='?tab=sqlite&sub=table_data&table=<?= urlencode($table) ?>&page=1&perpage='+this.value+'<?= addslashes($filterQuery) ?>'" style="padding:2px 6px;border:1px solid #ddd;border-radius:4px;font-size:12px">
                        <?php foreach ([10,20,50,100,200] as $pp): ?>
                        <option value="<?= $pp ?>" <?= $perPage===$pp?'selected':'' ?>><?= $pp ?></option>
                        <?php endforeach; ?>
                    </select> 条
                </div>
                <div class="pagination-btns">
                    <?php if ($page > 1): ?>
                    <a href="?tab=sqlite&sub=table_data&table=<?= urlencode($table) ?>&page=1&perpage=<?= $perPage ?><?= $filterQuery ?>" class="btn btn-outline btn-xs">首页</a>
                    <a href="?tab=sqlite&sub=table_data&table=<?= urlencode($table) ?>&page=<?= $page-1 ?>&perpage=<?= $perPage ?><?= $filterQuery ?>" class="btn btn-outline btn-xs">上一页</a>
                    <?php endif; ?>

                    <?php
                    $startP = max(1, $page - 2);
                    $endP = min($totalPages, $page + 2);
                    if ($startP > 1): ?>
                    <span class="pagination-dots">…</span>
                    <?php endif;
                    for ($p = $startP; $p <= $endP; $p++): ?>
                    <a href="?tab=sqlite&sub=table_data&table=<?= urlencode($table) ?>&page=<?= $p ?>&perpage=<?= $perPage ?><?= $filterQuery ?>" class="pagination-num <?= $p===$page?'active':'' ?>"><?= $p ?></a>
                    <?php endfor;
                    if ($endP < $totalPages): ?>
                    <span class="pagination-dots">…</span>
                    <?php endif; ?>

                    <?php if ($page < $totalPages): ?>
                    <a href="?tab=sqlite&sub=table_data&table=<?= urlencode($table) ?>&page=<?= $page+1 ?>&perpage=<?= $perPage ?>" class="btn btn-outline btn-xs">下一页</a>
                    <a href="?tab=sqlite&sub=table_data&table=<?= urlencode($table) ?>&page=<?= $totalPages ?>&perpage=<?= $perPage ?>" class="btn btn-outline btn-xs">末页</a>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($rows): ?>
            <div class="navicat-grid-wrap">
            <table id="dataTableSqlite" class="navicat-grid">
                <thead><tr>
                    <th class="row-num">#</th>
                <?php foreach ($columns as $ci => $col):
                    $sqliteColType = strtoupper(preg_replace('/\(.*/', '', $col['type'] ?? ''));
                    $isDateTypeS = in_array($sqliteColType, ['DATE','DATETIME','TIMESTAMP']);
                    $isNumericTypeS = in_array($sqliteColType, ['INT','INTEGER','BIGINT','TINYINT','SMALLINT','MEDIUMINT','FLOAT','DOUBLE','DECIMAL','NUMERIC','REAL']);
                    $colCatS = $isDateTypeS ? 'date' : ($isNumericTypeS ? 'number' : 'text');
                    $inpTypeS = $isDateTypeS ? 'date' : ($isNumericTypeS ? 'number' : 'text');
                ?>
                    <th class="col-filter-btn" onclick="toggleFilterS(event,'sfilter_<?= $ci ?>')"><?= h($col['name']) ?>
                        <div class="filter-dropdown" id="sfilter_<?= $ci ?>">
                            <select onchange="onFilterOpChangeS(this, <?= $ci ?>, '<?= $colCatS ?>')">
                                <?php if ($isNumericTypeS || $isDateTypeS): ?>
                                <option value="equals">等于</option>
                                <option value="not_equals">不等于</option>
                                <option value="greater_than">大于</option>
                                <option value="less_than">小于</option>
                                <option value="greater_equal">大于等于</option>
                                <option value="less_equal">小于等于</option>
                                <option value="between">介于</option>
                                <?php else: ?>
                                <option value="contains">包含</option>
                                <option value="not_contains">不包含</option>
                                <option value="equals">等于</option>
                                <option value="not_equals">不等于</option>
                                <option value="starts">开头是</option>
                                <option value="ends">结尾是</option>
                                <?php endif; ?>
                                <option value="empty">为空</option>
                                <option value="not_empty">不为空</option>
                            </select>
                            <input type="<?= $inpTypeS ?>" id="sfilter_input_<?= $ci ?>" placeholder="输入筛选值...">
                            <div id="sfilter_between_<?= $ci ?>" style="display:none;margin-top:4px">
                                <input type="<?= $isDateTypeS?'date':'number' ?>" id="sfilter_between_from_<?= $ci ?>" style="margin-bottom:4px" placeholder="<?= $isDateTypeS?'起始日期':'最小值' ?>">
                                <input type="<?= $isDateTypeS?'date':'number' ?>" id="sfilter_between_to_<?= $ci ?>" placeholder="<?= $isDateTypeS?'结束日期':'最大值' ?>">
                            </div>
                            <div class="filter-actions">
                                <button class="btn btn-outline btn-xs" onclick="clearFilterS(<?= $ci ?>)">清除筛选</button>
                                <button class="btn btn-primary btn-xs" onclick="applyFilterS(<?= $ci ?>);closeAllFiltersS();event.stopPropagation()">确定</button>
                            </div>
                        </div>
                    </th>
                <?php endforeach; ?>
                <th>操作</th></tr></thead>
                <tbody>
                <?php foreach ($rows as $rowIdx => $row):
                    $displayIdx = $offset + $rowIdx + 1;
                    $pkVal = $row[$pk] ?? '';
                ?>
                <tr data-row="<?= $rowIdx ?>">
                    <td class="row-num"><?= $displayIdx ?></td>
                    <?php foreach ($columns as $ci => $col): ?>
                    <td data-col="<?= $ci ?>"><?= h(mb_strlen($row[$col['name']] ?? '') > 100 ? mb_substr($row[$col['name']], 0, 100) . '...' : ($row[$col['name']] ?? '')) ?></td>
                    <?php endforeach; ?>
                    <td style="white-space:nowrap">
                        <button class="btn btn-outline btn-xs" onclick="openEditSqlite('<?= h($table) ?>','<?= h($pk) ?>','<?= h($pkVal) ?>',<?= htmlspecialchars(json_encode($row, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>)">编辑</button>
                        <form method="post" style="display:inline" onsubmit="return confirm('确定删除?')">
                            <input type="hidden" name="action" value="sqlite_delete_row">
                            <input type="hidden" name="table" value="<?= h($table) ?>">
                            <input type="hidden" name="pk_col" value="<?= h($pk) ?>">
                            <input type="hidden" name="pk_val" value="<?= h($pkVal) ?>">
                            <button class="btn btn-outline btn-xs" style="color:#c92a2a">删除</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <div id="noFilterResultS" style="display:none;padding:20px;text-align:center;color:#999;font-size:13px">没有匹配的数据</div>
            <?php else: ?>
            <div class="empty">暂无数据</div>
            <?php endif; ?>

            <!-- 分页导航（下方） -->
            <?php if ($totalPages > 1): ?>
            <div class="pagination-bar" style="margin-top:12px">
                <span style="font-size:12px;color:#999">共 <?= $totalRows ?> 条，<?= $totalPages ?> 页</span>
                <div class="pagination-btns">
                    <?php if ($page > 1): ?>
                    <a href="?tab=sqlite&sub=table_data&table=<?= urlencode($table) ?>&page=1&perpage=<?= $perPage ?><?= $filterQuery ?>" class="btn btn-outline btn-xs">首页</a>
                    <a href="?tab=sqlite&sub=table_data&table=<?= urlencode($table) ?>&page=<?= $page-1 ?>&perpage=<?= $perPage ?><?= $filterQuery ?>" class="btn btn-outline btn-xs">上一页</a>
                    <?php endif; ?>
                    <span class="pagination-num active"><?= $page ?> / <?= $totalPages ?></span>
                    <?php if ($page < $totalPages): ?>
                    <a href="?tab=sqlite&sub=table_data&table=<?= urlencode($table) ?>&page=<?= $page+1 ?>&perpage=<?= $perPage ?><?= $filterQuery ?>" class="btn btn-outline btn-xs">下一页</a>
                    <a href="?tab=sqlite&sub=table_data&table=<?= urlencode($table) ?>&page=<?= $totalPages ?>&perpage=<?= $perPage ?><?= $filterQuery ?>" class="btn btn-outline btn-xs">末页</a>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
<script>
window._sqliteColTypes = <?= json_encode(array_reduce($columns, function($map, $col) { $map[$col['name']] = $col['type'] ?? ''; return $map; }, [])) ?>;
window._currentSqliteTable = <?= json_encode($table) ?>;
window._currentSqlitePerPage = <?= $perPage ?>;
window._sqliteFilterQuery = <?= json_encode($filterQuery) ?>;
</script>
    <?php endif; ?>

    <?php elseif ($sub === 'query'): ?>
    <div class="card">
        <div class="card-header">执行 SQL 查询</div>
        <div class="card-body">
            <form method="post">
                <input type="hidden" name="action" value="sqlite_query">
                <div class="form-group">
                    <textarea name="sql_query" style="min-height:120px;font-family:monospace" placeholder="输入 SQL 语句...&#10;例如: SELECT * FROM users WHERE age > 18"><?= h($_SESSION['sqlite_last_sql'] ?? '') ?></textarea>
                </div>
                <button class="btn btn-primary" type="submit">执行</button>
            </form>
            <?php if (isset($_SESSION['sqlite_last_sql_error']) && $_SESSION['sqlite_last_sql_error']): ?>
            <div class="flash flash-error" style="margin-top:12px"><?= h($_SESSION['sqlite_last_sql_error']) ?></div>
            <?php endif; ?>
            <?php if (isset($_SESSION['sqlite_last_sql_result']) && $_SESSION['sqlite_last_sql_result']): ?>
            <div style="margin-top:12px"><span class="badge badge-blue"><?= count($_SESSION['sqlite_last_sql_result']) ?> 行</span></div>
            <div style="overflow-x:auto;margin-top:8px">
            <table>
                <thead><tr><?php foreach ($_SESSION['sqlite_last_sql_columns'] as $col): ?><th><?= h($col) ?></th><?php endforeach; ?></tr></thead>
                <tbody>
                <?php foreach ($_SESSION['sqlite_last_sql_result'] as $r): ?>
                <tr><?php foreach ($_SESSION['sqlite_last_sql_columns'] as $col): ?><td><?= h(mb_strlen((string)($r[$col] ?? '')) > 150 ? mb_substr((string)($r[$col]), 0, 150) . '...' : ($r[$col] ?? '')) ?></td><?php endforeach; ?></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <?php endif; ?>
            <?php
            unset($_SESSION['sqlite_last_sql'], $_SESSION['sqlite_last_sql_error'], $_SESSION['sqlite_last_sql_result'], $_SESSION['sqlite_last_sql_columns'], $_SESSION['sqlite_last_affected']);
            ?>
        </div>
    </div>

    <?php elseif ($sub === 'import'): ?>
    <div class="card">
        <div class="card-header">导入数据 —— 导入到数据库</div>
        <div class="card-body">
            <p class="text-muted mb-sm">上传 .sql 文件，导入到当前 SQLite 数据库 <strong><?= h($config['path']) ?></strong></p>
            <form method="post" enctype="multipart/form-data">
                <input type="hidden" name="action" value="sqlite_import">
                <input type="hidden" name="import_mode" value="database">
                <div class="form-group"><label>选择 SQL 文件</label><input type="file" name="import_file" accept=".sql" required></div>

                <div class="form-group">
                    <label>遇到已存在的表时：</label>
                    <div class="radio-group">
                        <label class="radio-item"><input type="radio" name="table_action" value="skip"> 跳过（仅执行表结构，不重复建表）</label>
                        <label class="radio-item"><input type="radio" name="table_action" value="drop_recreate"> 删除并重建（DROP TABLE IF EXISTS 再 CREATE TABLE）</label>
                        <label class="radio-item"><input type="radio" name="table_action" value="append" checked> 追加数据（忽略建表语句，仅执行INSERT）</label>
                    </div>
                </div>

                <div class="form-group">
                    <label>遇到主键重复时：</label>
                    <div class="radio-group">
                        <label class="radio-item"><input type="radio" name="pk_action" value="skip" checked> 跳过该行（INSERT OR IGNORE，保留已有数据）</label>
                        <label class="radio-item"><input type="radio" name="pk_action" value="update"> 更新该行（INSERT OR REPLACE，删除旧行再插入）</label>
                        <label class="radio-item"><input type="radio" name="pk_action" value="error"> 报错终止（保留原始行为）</label>
                    </div>
                </div>

                <button class="btn btn-primary" type="submit">导入到数据库</button>
            </form>
        </div>
    </div>
    <div class="card">
        <div class="card-header">导入数据 —— 导入到表</div>
        <div class="card-body">
            <p class="text-muted mb-sm">选择导入格式，将数据导入到指定表中</p>
            <form method="post" enctype="multipart/form-data">
                <input type="hidden" name="action" value="sqlite_import">
                <input type="hidden" name="import_mode" value="table">
                <div class="form-group">
                    <label>导入格式：</label>
                    <div class="radio-group">
                        <label class="radio-item"><input type="radio" name="table_import_format" value="csv" checked onchange="document.getElementById('sqliteTableFile').accept='.csv';document.getElementById('sqliteTableFileLabel').textContent='选择 CSV 文件（首行必须为列名）'"> CSV 文件（首行必须为列名）</label>
                        <label class="radio-item"><input type="radio" name="table_import_format" value="sql" onchange="document.getElementById('sqliteTableFile').accept='.sql';document.getElementById('sqliteTableFileLabel').textContent='选择 SQL 文件（含 INSERT 语句）'"> SQL 文件（含 INSERT 语句）</label>
                    </div>
                </div>
                <div class="form-group"><label id="sqliteTableFileLabel">选择 CSV 文件（首行必须为列名）</label><input type="file" name="import_file" id="sqliteTableFile" accept=".csv" required></div>
                <div class="form-group"><label>目标表名</label><input name="import_table" placeholder="例如: users" required></div>
                <button class="btn btn-primary" type="submit">导入到表</button>
            </form>
        </div>
    </div>

    <?php elseif ($sub === 'export'):
        list($pdo, $err) = sqliteConnect();
        $tables = [];
        if ($pdo) {
            $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
        }
    ?>
    <div class="card">
        <div class="card-header">导出 —— 整个数据库</div>
        <div class="card-body">
            <p class="text-muted mb-sm">将 SQLite 数据库 <strong><?= h($config['path']) ?></strong> 中所有表的结构 + 数据导出为一个 .sql 文件</p>
            <a href="?tab=sqlite&action=sqlite_export_all_sql" class="btn btn-primary">导出整个数据库 (SQL)</a>
        </div>
    </div>
    <div class="card">
        <div class="card-header">导出 —— 单个表</div>
        <div class="card-body">
            <?php if ($tables): ?>
            <p class="text-muted mb-sm">选择要导出的表，可选 SQL 或 CSV 格式</p>
            <table>
                <thead><tr><th>表名</th><th>操作</th></tr></thead>
                <tbody>
                <?php foreach ($tables as $t): ?>
                <tr>
                    <td><strong><?= h($t) ?></strong></td>
                    <td>
                        <a href="?tab=sqlite&action=sqlite_export_sql&table=<?= urlencode($t) ?>" class="btn btn-outline btn-sm">导出 SQL</a>
                        <a href="?tab=sqlite&action=sqlite_export_csv&table=<?= urlencode($t) ?>" class="btn btn-outline btn-sm">导出 CSV</a>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php else: ?>
            <div class="empty">暂无数据表</div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
<?php endif; ?>
<?php endif; // SQLITE END ?>

<?php
// ============================================================
// MONGODB 渲染
// ============================================================
if ($tab === 'mongodb'):
    $config = $_SESSION['mongo_config'] ?? null;
    $connected = !empty($config) && class_exists('MongoDB\Driver\Manager');

    if (!$connected): ?>
    <div class="card">
        <div class="card-header">连接 MongoDB</div>
        <div class="card-body">
            <?php if (!class_exists('MongoDB\Driver\Manager')): ?>
            <div class="flash flash-error">MongoDB PHP 扩展未安装。请安装 mongodb 扩展。</div>
            <?php endif; ?>
            <form method="post">
                <input type="hidden" name="action" value="mongo_connect">
                <div class="form-row">
                    <div class="form-group"><label>主机</label><input name="host" value="127.0.0.1" required></div>
                    <div class="form-group"><label>端口</label><input name="port" value="27017" required></div>
                </div>
                <div class="form-row">
                    <div class="form-group"><label>用户名</label><input name="user"></div>
                    <div class="form-group"><label>密码</label><input name="pass" type="password"></div>
                </div>
                <div style="display:flex;align-items:center;gap:12px">
                    <button class="btn btn-primary" type="submit">连接</button>
                    <label class="switch-label">
                        <input type="checkbox" name="save_conn" value="1" checked>
                        <span>保存连接</span>
                    </label>
                </div>
            </form>
        </div>
    </div>
<?php else:
    $mongoDb   = $_SESSION['mongo_db'] ?? '';
    $mongoColl = $_SESSION['mongo_coll'] ?? '';

    // 获取数据库列表
    list($manager, $err) = mongoConnect();
    $dbList = [];
    $collList = [];
    if ($manager) {
        try {
            $cmd = new MongoDB\Driver\Command(['listDatabases' => 1]);
            $cursor = $manager->executeCommand('admin', $cmd);
            foreach ($cursor as $dbInfo) {
                $dbList[] = $dbInfo->name;
            }
        } catch (Exception $e) { $dbList = []; }

        if ($mongoDb) {
            try {
                $cmd = new MongoDB\Driver\Command(['listCollections' => 1]);
                $cursor = $manager->executeCommand($mongoDb, $cmd);
                foreach ($cursor as $collInfo) {
                    $collList[] = $collInfo->name;
                }
            } catch (Exception $e) { $collList = []; }
        }
    }
?>
    <div style="margin-bottom:16px;display:flex;gap:8px;flex-wrap:wrap">
        <a href="?tab=mongodb&sub=docs" class="btn btn-outline btn-sm <?= $sub==='docs'?'active':'' ?>" style="<?= $sub==='docs'?'background:#e94560;color:#fff;border-color:#e94560':'' ?>">文档操作</a>
        <a href="?tab=mongodb&action=disconnect" class="btn btn-outline btn-sm" style="margin-left:auto;color:#c92a2a" onclick="return confirm('确定断开?')">断开</a>
    </div>

    <!-- DB 选择 -->
    <div class="card">
        <div class="card-header">选择数据库</div>
        <div class="card-body">
            <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px">
                <?php foreach ($dbList as $dbn): ?>
                <form method="post" style="display:inline">
                    <input type="hidden" name="action" value="mongo_use_db">
                    <input type="hidden" name="dbname" value="<?= h($dbn) ?>">
                    <button class="btn <?= $mongoDb===$dbn ? 'btn-primary' : 'btn-outline' ?> btn-sm" type="submit"><?= h($dbn) ?></button>
                </form>
                <?php endforeach; ?>
            </div>
            <?php if ($mongoDb): ?>
            <span class="badge badge-green">当前库: <?= h($mongoDb) ?></span>
            <?php if ($collList): ?>
            <div style="margin-top:12px">
                <strong>集合:</strong>
                <?php foreach ($collList as $cn): ?>
                <form method="post" style="display:inline">
                    <input type="hidden" name="action" value="mongo_use_coll">
                    <input type="hidden" name="coll" value="<?= h($cn) ?>">
                    <button class="btn <?= $mongoColl===$cn ? 'btn-primary' : 'btn-outline' ?> btn-xs" type="submit"><?= h($cn) ?></button>
                </form>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($sub === 'docs' && $mongoDb && $mongoColl):
        // 获取文档列表
        $docs = [];
        if ($manager) {
            try {
                $query = new MongoDB\Driver\Query([], ['limit' => 50]);
                $cursor = $manager->executeQuery("{$mongoDb}.{$mongoColl}", $query);
                foreach ($cursor as $doc) {
                    $docs[] = json_decode(json_encode($doc), true);
                }
            } catch (Exception $e) { $docs = []; }
        }
    ?>
    <!-- 插入文档 -->
    <div class="card">
        <div class="card-header">插入文档 - <?= h($mongoColl) ?></div>
        <div class="card-body">
            <form method="post">
                <input type="hidden" name="action" value="mongo_insert">
                <div class="form-group"><label>JSON 文档</label><textarea name="doc_json" style="min-height:100px" placeholder='{"name": "test", "value": 123}' required></textarea></div>
                <button class="btn btn-primary btn-sm" type="submit">插入</button>
            </form>
        </div>
    </div>

    <!-- 更新文档 -->
    <div class="card">
        <div class="card-header">更新文档</div>
        <div class="card-body">
            <form method="post">
                <input type="hidden" name="action" value="mongo_update">
                <div class="form-group"><label>筛选条件 JSON</label><textarea name="filter_json" style="min-height:40px" placeholder='{"name": "test"}' required></textarea></div>
                <div class="form-group"><label>更新字段 JSON</label><textarea name="update_json" style="min-height:40px" placeholder='{"value": 456}' required></textarea></div>
                <button class="btn btn-primary btn-sm" type="submit">更新</button>
            </form>
        </div>
    </div>

    <!-- 删除文档 -->
    <div class="card">
        <div class="card-header">删除文档</div>
        <div class="card-body">
            <form method="post" onsubmit="return confirm('确定删除?')">
                <input type="hidden" name="action" value="mongo_delete">
                <div class="form-group"><label>筛选条件 JSON</label><textarea name="filter_json" style="min-height:40px" placeholder='{"_id": "..."}' required></textarea></div>
                <button class="btn btn-danger btn-sm" type="submit">删除</button>
            </form>
        </div>
    </div>

    <!-- 文档列表 + 导入导出 -->
    <div class="card">
        <div class="card-header">
            集合 <?= h($mongoColl) ?> - 文档列表
            <span>
                <a href="?tab=mongodb&action=mongo_export_json" class="btn btn-outline btn-xs">导出 JSON</a>
            </span>
        </div>
        <div class="card-body">
            <!-- 导入 -->
            <form method="post" enctype="multipart/form-data" style="margin-bottom:12px;display:flex;gap:8px;align-items:flex-end">
                <input type="hidden" name="action" value="mongo_import">
                <div class="form-group" style="margin-bottom:0"><label style="font-size:12px">导入 JSON 文件</label><input type="file" name="import_file" accept=".json" required style="font-size:12px"></div>
                <button class="btn btn-outline btn-xs" type="submit">导入</button>
            </form>

            <?php if ($docs): ?>
            <div style="overflow-x:auto">
            <table>
                <thead><tr><th>#</th><th>_id</th><th>内容</th></tr></thead>
                <tbody>
                <?php $idx = 0; foreach ($docs as $doc): $idx++; ?>
                <tr>
                    <td><?= $idx ?></td>
                    <td><?= h(json_encode($doc['_id'] ?? '', JSON_UNESCAPED_UNICODE)) ?></td>
                    <td style="max-width:500px"><code style="font-size:11px"><?= h(mb_strlen(json_encode($doc, JSON_UNESCAPED_UNICODE)) > 200 ? mb_substr(json_encode($doc, JSON_UNESCAPED_UNICODE), 0, 200) . '...' : json_encode($doc, JSON_UNESCAPED_UNICODE)) ?></code></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <?php else: ?>
            <div class="empty">暂无文档</div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
<?php endif; ?>
<?php endif; // MONGODB END ?>

<?php
// ============================================================
// REDIS 渲染
// ============================================================
if ($tab === 'redis'):
    $config = $_SESSION['redis_config'] ?? null;
    $connected = !empty($config) && class_exists('Redis');

    if (!$connected): ?>
    <div class="card">
        <div class="card-header">连接 Redis</div>
        <div class="card-body">
            <?php if (!class_exists('Redis')): ?>
            <div class="flash flash-error">Redis PHP 扩展未安装。请安装 redis 扩展。</div>
            <?php endif; ?>
            <form method="post">
                <input type="hidden" name="action" value="redis_connect">
                <div class="form-row">
                    <div class="form-group"><label>方案</label><select name="scheme"><option value="tcp">TCP</option><option value="tls">TLS</option></select></div>
                    <div class="form-group"><label>主机</label><input name="host" value="127.0.0.1" required></div>
                    <div class="form-group"><label>端口</label><input name="port" value="6379" required></div>
                </div>
                <div class="form-row">
                    <div class="form-group"><label>密码</label><input name="pass" type="password"></div>
                    <div class="form-group"><label>数据库编号</label><input name="db" value="0"></div>
                    <div class="form-group"><label>超时(秒)</label><input name="timeout" value="3"></div>
                </div>
                <div style="display:flex;align-items:center;gap:12px">
                    <button class="btn btn-primary" type="submit">连接</button>
                    <label class="switch-label">
                        <input type="checkbox" name="save_conn" value="1" checked>
                        <span>保存连接</span>
                    </label>
                </div>
            </form>
        </div>
    </div>
<?php else: ?>
    <div class="info-grid">
        <div class="info-item"><div class="label">主机</div><div class="value"><?= h($config['host']) ?>:<?= h($config['port']) ?></div></div>
        <div class="info-item"><div class="label">数据库</div><div class="value">DB<?= h($config['db']) ?></div></div>
    </div>

    <div style="margin-bottom:16px;display:flex;gap:8px;flex-wrap:wrap">
        <a href="?tab=redis&sub=browse"  class="btn btn-outline btn-sm <?= $sub==='browse'?'active':'' ?>" style="<?= $sub==='browse'?'background:#e94560;color:#fff;border-color:#e94560':'' ?>">键浏览</a>
        <a href="?tab=redis&sub=command" class="btn btn-outline btn-sm <?= $sub==='command'?'active':'' ?>" style="<?= $sub==='command'?'background:#e94560;color:#fff;border-color:#e94560':'' ?>">命令执行</a>
        <a href="?tab=redis&action=disconnect" class="btn btn-outline btn-sm" style="margin-left:auto;color:#c92a2a" onclick="return confirm('确定断开?')">断开</a>
    </div>

    <?php if ($sub === 'browse'):
        list($redis, $err) = redisConnect();
        $keys = [];
        $keyCount = 0;
        if ($redis) {
            try {
                $allKeys = $redis->keys('*');
                $keyCount = count($allKeys);
                $keys = array_slice($allKeys, 0, 200);
            } catch (Exception $e) {}
        }
    ?>
    <div class="card">
        <div class="card-header">设置键值</div>
        <div class="card-body">
            <form method="post">
                <input type="hidden" name="action" value="redis_set">
                <div class="form-row">
                    <div class="form-group"><label>键</label><input name="key" required></div>
                    <div class="form-group"><label>值</label><input name="value" required></div>
                    <div class="form-group" style="flex:0.3"><label>TTL(秒, -1=永不过期)</label><input name="ttl" value="-1"></div>
                </div>
                <button class="btn btn-primary btn-sm" type="submit">设置</button>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-header">键列表 <span class="badge badge-blue"><?= $keyCount ?> 个键</span>
            <form method="post" style="display:inline" onsubmit="return confirm('确定清空当前数据库所有键?')">
                <input type="hidden" name="action" value="redis_flush">
                <button class="btn btn-outline btn-xs" style="color:#c92a2a" type="submit">清空数据库</button>
            </form>
        </div>
        <div class="card-body">
            <?php if ($keys): ?>
            <table>
                <thead><tr><th>键</th><th>类型</th><th>值 (截取)</th><th>TTL</th><th>操作</th></tr></thead>
                <tbody>
                <?php foreach ($keys as $k):
                    $type = $redis->type($k);
                    $ttl = $redis->ttl($k);
                    $val = '';
                    try {
                        switch ($type) {
                            case Redis::REDIS_STRING: $val = $redis->get($k); break;
                            case Redis::REDIS_LIST: $val = json_encode($redis->lRange($k, 0, 9), JSON_UNESCAPED_UNICODE); break;
                            case Redis::REDIS_SET: $val = json_encode($redis->sMembers($k), JSON_UNESCAPED_UNICODE); break;
                            case Redis::REDIS_HASH: $val = json_encode($redis->hGetAll($k), JSON_UNESCAPED_UNICODE); break;
                            case Redis::REDIS_ZSET: $val = json_encode($redis->zRange($k, 0, -1, true), JSON_UNESCAPED_UNICODE); break;
                            default: $val = '(binary/unknown)';
                        }
                    } catch (Exception $e) { $val = '(error reading)'; }
                ?>
                <tr>
                    <td><strong><?= h($k) ?></strong></td>
                    <td><span class="badge badge-green"><?= h($type) ?></span></td>
                    <td style="max-width:300px"><code style="font-size:11px"><?= h(mb_strlen((string)$val) > 150 ? mb_substr((string)$val, 0, 150) . '...' : (string)$val) ?></code></td>
                    <td><?= $ttl < 0 ? '<span class="badge badge-blue">永久</span>' : h($ttl) . 's' ?></td>
                    <td style="white-space:nowrap">
                        <form method="post" style="display:inline" onsubmit="return confirm('确定删除键 `<?= h($k) ?>`?')">
                            <input type="hidden" name="action" value="redis_del">
                            <input type="hidden" name="key" value="<?= h($k) ?>">
                            <button class="btn btn-outline btn-xs" style="color:#c92a2a">删除</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php else: ?>
            <div class="empty">暂无键</div>
            <?php endif; ?>
        </div>
    </div>

    <?php elseif ($sub === 'command'): ?>
    <div class="card">
        <div class="card-header">执行 Redis 命令</div>
        <div class="card-body">
            <form method="post">
                <input type="hidden" name="action" value="redis_exec">
                <div class="form-group">
                    <textarea name="redis_cmd" style="min-height:100px;font-family:monospace" placeholder="输入 Redis 命令...&#10;例如:&#10;SET mykey myvalue&#10;GET mykey&#10;KEYS *&#10;INFO"><?= h($_SESSION['redis_last_cmd'] ?? '') ?></textarea>
                </div>
                <button class="btn btn-primary" type="submit">执行</button>
            </form>
            <?php if (isset($_SESSION['redis_last_result']) && $_SESSION['redis_last_result'] !== ''): ?>
            <div class="code-block" style="margin-top:12px"><?= h($_SESSION['redis_last_result']) ?></div>
            <?php endif; ?>
            <?php unset($_SESSION['redis_last_cmd'], $_SESSION['redis_last_result']); ?>
        </div>
    </div>
    <?php endif; ?>
<?php endif; ?>
<?php endif; // REDIS END ?>

</div>

<!-- 编辑模态框 (MySQL) -->
<div id="editModal" class="modal-overlay" style="display:none">
    <div class="modal">
        <h3>编辑记录</h3>
        <form method="post" id="editForm">
            <input type="hidden" name="action" value="mysql_update">
            <input type="hidden" name="table" id="edit_table">
            <input type="hidden" name="pk_col" id="edit_pk_col">
            <input type="hidden" name="pk_val" id="edit_pk_val">
            <div id="editFields"></div>
            <div style="display:flex;gap:8px;margin-top:16px">
                <button class="btn btn-primary btn-sm" type="submit">保存</button>
                <button class="btn btn-outline btn-sm" type="button" onclick="closeEdit()">取消</button>
            </div>
        </form>
    </div>
</div>

<!-- 编辑模态框 (SQLite) -->
<div id="editModalSqlite" class="modal-overlay" style="display:none">
    <div class="modal">
        <h3>编辑记录 (SQLite)</h3>
        <form method="post" id="editFormSqlite">
            <input type="hidden" name="action" value="sqlite_update">
            <input type="hidden" name="table" id="edit_table_s">
            <input type="hidden" name="pk_col" id="edit_pk_col_s">
            <input type="hidden" name="pk_val" id="edit_pk_val_s">
            <div id="editFieldsSqlite"></div>
            <div style="display:flex;gap:8px;margin-top:16px">
                <button class="btn btn-primary btn-sm" type="submit">保存</button>
                <button class="btn btn-outline btn-sm" type="button" onclick="closeEditSqlite()">取消</button>
            </div>
        </form>
    </div>
</div>

<script>
function openEdit(table, pkCol, pkVal, rowData) {
    document.getElementById('edit_table').value = table;
    document.getElementById('edit_pk_col').value = pkCol;
    document.getElementById('edit_pk_val').value = pkVal;
    var html = '';
    for (var k in rowData) {
        var ct = (window._mysqlColTypes && window._mysqlColTypes[k]) ? window._mysqlColTypes[k].toUpperCase() : '';
        var isDate = (ct === 'DATE');
        html += '<div class="form-group"><label>' + escapeHtml(k) + '</label><input type="' + (isDate ? 'date' : 'text') + '" name="col_' + escapeHtml(k) + '" value="' + escapeHtml(rowData[k] || '') + '"></div>';
    }
    document.getElementById('editFields').innerHTML = html;
    document.getElementById('editModal').style.display = 'flex';
}
function closeEdit() { document.getElementById('editModal').style.display = 'none'; }

function openEditSqlite(table, pkCol, pkVal, rowData) {
    document.getElementById('edit_table_s').value = table;
    document.getElementById('edit_pk_col_s').value = pkCol;
    document.getElementById('edit_pk_val_s').value = pkVal;
    var html = '';
    for (var k in rowData) {
        var ct = (window._sqliteColTypes && window._sqliteColTypes[k]) ? window._sqliteColTypes[k].toUpperCase() : '';
        var isDate = (ct === 'DATE');
        html += '<div class="form-group"><label>' + escapeHtml(k) + '</label><input type="' + (isDate ? 'date' : 'text') + '" name="col_' + escapeHtml(k) + '" value="' + escapeHtml(rowData[k] || '') + '"></div>';
    }
    document.getElementById('editFieldsSqlite').innerHTML = html;
    document.getElementById('editModalSqlite').style.display = 'flex';
}
function closeEditSqlite() { document.getElementById('editModalSqlite').style.display = 'none'; }

// 点击模态框遮罩关闭
document.addEventListener('click', function(e) {
    if (e.target.id === 'editModal') closeEdit();
    if (e.target.id === 'editModalSqlite') closeEditSqlite();
});

function escapeHtml(str) {
    var div = document.createElement('div');
    div.appendChild(document.createTextNode(str));
    return div.innerHTML;
}

// ========== 列筛选功能 ==========
var activeFilters = {};

// colType: 'date' | 'number' | 'text'
function onFilterOpChange(sel, ci, colType) {
    var inp = document.getElementById('filter_input_' + ci);
    var bet = document.getElementById('filter_between_' + ci);
    if (!inp) return;
    var op = sel.value;
    // 重置状态
    if (inp) { inp.style.display = (op === 'between' || op === 'empty' || op === 'not_empty') ? 'none' : ''; inp.value = ''; }
    if (bet) bet.style.display = 'none';
    if (op === 'between') {
        inp.style.display = 'none';
        if (bet) { bet.style.display = ''; }
    } else if (op === 'empty' || op === 'not_empty') {
        inp.style.display = 'none';
    } else {
        inp.style.display = '';
    }
}

function onFilterOpChangeS(sel, ci, colType) {
    var inp = document.getElementById('sfilter_input_' + ci);
    var bet = document.getElementById('sfilter_between_' + ci);
    if (!inp) return;
    var op = sel.value;
    if (inp) { inp.style.display = (op === 'between' || op === 'empty' || op === 'not_empty') ? 'none' : ''; inp.value = ''; }
    if (bet) bet.style.display = 'none';
    if (op === 'between') {
        inp.style.display = 'none';
        if (bet) { bet.style.display = ''; }
    } else if (op === 'empty' || op === 'not_empty') {
        inp.style.display = 'none';
    } else {
        inp.style.display = '';
    }
}

function toggleFilter(e, id) {
    e.stopPropagation();
    // 先关闭所有下拉
    document.querySelectorAll('#dataTable .filter-dropdown').forEach(function(d){ d.classList.remove('show'); });
    var dd = document.getElementById(id);
    if (dd) { dd.classList.toggle('show'); }
}

function toggleFilterS(e, id) {
    e.stopPropagation();
    document.querySelectorAll('#dataTableSqlite .filter-dropdown').forEach(function(d){ d.classList.remove('show'); });
    var dd = document.getElementById(id);
    if (dd) { dd.classList.toggle('show'); }
}

// closeAllFilters 只关闭下拉弹窗，不重置已应用的筛选
function closeAllFilters() {
    document.querySelectorAll('#dataTable .filter-dropdown').forEach(function(d){ d.classList.remove('show'); });
}

function closeAllFiltersS() {
    document.querySelectorAll('#dataTableSqlite .filter-dropdown').forEach(function(d){ d.classList.remove('show'); });
}

// 通用匹配函数：支持字符串/数值/日期混合比较
function matchFilter(cellText, f) {
    var ct = cellText;
    var ctLower = ct.toLowerCase();
    var fv = (f.val || '').toLowerCase();

    if (f.op === 'contains')          { return ctLower.indexOf(fv) !== -1; }
    else if (f.op === 'not_contains') { return ctLower.indexOf(fv) === -1; }
    else if (f.op === 'equals')       { return ctLower === fv; }
    else if (f.op === 'not_equals')   { return ctLower !== fv; }
    else if (f.op === 'starts')       { return ctLower.indexOf(fv) === 0; }
    else if (f.op === 'ends')         { return ctLower.endsWith ? ctLower.endsWith(fv) : ctLower.lastIndexOf(fv) === ctLower.length - fv.length; }

    // 数值比较运算符
    else if (f.op === 'greater_than')  { return parseFloat(ct) > parseFloat(f.val); }
    else if (f.op === 'less_than')     { return parseFloat(ct) < parseFloat(f.val); }
    else if (f.op === 'greater_equal') { return parseFloat(ct) >= parseFloat(f.val); }
    else if (f.op === 'less_equal')    { return parseFloat(ct) <= parseFloat(f.val); }

    // 介于：日期用字符串字典序比较（天然支持YYYY-MM-DD），纯数值用数值比较
    else if (f.op === 'between') {
        if (f.val === '__BETWEEN__') {
            var from = f.from || '';
            var to = f.to || '';
            // 判断是否为日期格式：YYYY-MM-DD 或 YYYY-MM-DD HH:MM:SS
            var dateRe = /^\d{4}-\d{2}-\d{2}/;
            var isDateLike = dateRe.test(from) || dateRe.test(to) || dateRe.test(ct);
            if (!isDateLike) {
                var nCell = parseFloat(ct);
                var nFrom = parseFloat(from);
                var nTo = parseFloat(to);
                if (!isNaN(nCell) && !isNaN(nFrom) && !isNaN(nTo)) {
                    return (from === '' || nCell >= nFrom) && (to === '' || nCell <= nTo);
                }
            }
            return (from === '' || ct >= from) && (to === '' || ct <= to);
        }
        return true;
    }

    else if (f.op === 'empty')     { return ct.trim() === ''; }
    else if (f.op === 'not_empty') { return ct.trim() !== ''; }
    return true;
}

// ========== 服务端筛选：收集条件通过URL跳转，PHP执行WHERE查询 ==========

function buildMysqlFilterParams() {
    var params = '';
    var ddList = document.querySelectorAll('#dataTable .filter-dropdown');
    ddList.forEach(function(dd) {
        var ciMatch = dd.id.match(/^filter_(\d+)$/);
        if (!ciMatch) return;
        var ci = ciMatch[1];
        var sel = dd.querySelector('select');
        if (!sel) return;
        var op = sel.value;
        if (!op) return;
        if (op === 'between') {
            var bf = dd.querySelector('input[id^="filter_between_from_"]');
            var bt = dd.querySelector('input[id^="filter_between_to_"]');
            if (bf && bt && (bf.value.trim() !== '' || bt.value.trim() !== '')) {
                params += '&f_' + ci + '=between:' + encodeURIComponent(bf.value.trim()) + ':' + encodeURIComponent(bt.value.trim());
            }
        } else if (op === 'empty' || op === 'not_empty') {
            params += '&f_' + ci + '=' + op + ':';
        } else {
            var inp = document.getElementById('filter_input_' + ci);
            var val = inp ? inp.value.trim() : '';
            if (val !== '') {
                params += '&f_' + ci + '=' + op + ':' + encodeURIComponent(val);
            }
        }
    });
    return params;
}

function applyFilter(ci) {
    var dd = document.getElementById('filter_' + ci);
    if (!dd) return;
    var sel = dd.querySelector('select');
    if (!sel) return;
    var op = sel.value;
    // Validate input
    if (op !== 'empty' && op !== 'not_empty') {
        if (op === 'between') {
            var bf = document.getElementById('filter_between_from_' + ci);
            var bt = document.getElementById('filter_between_to_' + ci);
            if (!bf || !bt || (bf.value.trim() === '' && bt.value.trim() === '')) {
                alert('请输入筛选范围值'); return;
            }
        } else {
            var inp = document.getElementById('filter_input_' + ci);
            if (!inp || inp.value.trim() === '') {
                alert('请输入筛选值'); return;
            }
        }
    }
    // Build all filter params from DOM and redirect to page 1
    var url = '?tab=mysql&sub=table_data&table=' + encodeURIComponent(window._currentMysqlTable || '');
    url += '&page=1&perpage=' + (window._currentMysqlPerPage || 50);
    url += buildMysqlFilterParams();
    closeAllFilters();
    location.href = url;
}
function applyAllFilters() {
    // No-op: filtering is now server-side via URL params
}

function clearFilter(ci) {
    // Build URL keeping all filter params EXCEPT this column's
    var params = '';
    var ddList = document.querySelectorAll('#dataTable .filter-dropdown');
    ddList.forEach(function(dd) {
        var m = dd.id.match(/^filter_(\d+)$/);
        if (!m || m[1] === '' + ci) return;
        var fci = m[1];
        var sel = dd.querySelector('select');
        if (!sel) return;
        var op = sel.value;
        if (!op) return;
        if (op === 'between') {
            var bf = dd.querySelector('input[id^="filter_between_from_"]');
            var bt = dd.querySelector('input[id^="filter_between_to_"]');
            if (bf && bt && (bf.value.trim() !== '' || bt.value.trim() !== '')) {
                params += '&f_' + fci + '=between:' + encodeURIComponent(bf.value.trim()) + ':' + encodeURIComponent(bt.value.trim());
            }
        } else if (op === 'empty' || op === 'not_empty') {
            params += '&f_' + fci + '=' + op + ':';
        } else {
            var inp = document.getElementById('filter_input_' + fci);
            var val = inp ? inp.value.trim() : '';
            if (val !== '') {
                params += '&f_' + fci + '=' + op + ':' + encodeURIComponent(val);
            }
        }
    });
    var url = '?tab=mysql&sub=table_data&table=' + encodeURIComponent(window._currentMysqlTable || '');
    url += '&page=1&perpage=' + (window._currentMysqlPerPage || 50);
    url += params;
    closeAllFilters();
    location.href = url;
}

// ========== SQLite 服务端筛选 ==========

function buildSqliteFilterParams() {
    var params = '';
    var ddList = document.querySelectorAll('#dataTableSqlite .filter-dropdown');
    ddList.forEach(function(dd) {
        var ciMatch = dd.id.match(/^sfilter_(\d+)$/);
        if (!ciMatch) return;
        var ci = ciMatch[1];
        var sel = dd.querySelector('select');
        if (!sel) return;
        var op = sel.value;
        if (!op) return;
        if (op === 'between') {
            var bf = dd.querySelector('input[id^="sfilter_between_from_"]');
            var bt = dd.querySelector('input[id^="sfilter_between_to_"]');
            if (bf && bt && (bf.value.trim() !== '' || bt.value.trim() !== '')) {
                params += '&f_' + ci + '=between:' + encodeURIComponent(bf.value.trim()) + ':' + encodeURIComponent(bt.value.trim());
            }
        } else if (op === 'empty' || op === 'not_empty') {
            params += '&f_' + ci + '=' + op + ':';
        } else {
            var inp = document.getElementById('sfilter_input_' + ci);
            var val = inp ? inp.value.trim() : '';
            if (val !== '') {
                params += '&f_' + ci + '=' + op + ':' + encodeURIComponent(val);
            }
        }
    });
    return params;
}

function applyFilterS(ci) {
    var dd = document.getElementById('sfilter_' + ci);
    if (!dd) return;
    var sel = dd.querySelector('select');
    if (!sel) return;
    var op = sel.value;
    if (op !== 'empty' && op !== 'not_empty') {
        if (op === 'between') {
            var bf = document.getElementById('sfilter_between_from_' + ci);
            var bt = document.getElementById('sfilter_between_to_' + ci);
            if (!bf || !bt || (bf.value.trim() === '' && bt.value.trim() === '')) {
                alert('请输入筛选范围值'); return;
            }
        } else {
            var inp = document.getElementById('sfilter_input_' + ci);
            if (!inp || inp.value.trim() === '') {
                alert('请输入筛选值'); return;
            }
        }
    }
    var url = '?tab=sqlite&sub=table_data&table=' + encodeURIComponent(window._currentSqliteTable || '');
    url += '&page=1&perpage=' + (window._currentSqlitePerPage || 50);
    url += buildSqliteFilterParams();
    closeAllFiltersS();
    location.href = url;
}

function clearFilterS(ci) {
    var params = '';
    var ddList = document.querySelectorAll('#dataTableSqlite .filter-dropdown');
    ddList.forEach(function(dd) {
        var m = dd.id.match(/^sfilter_(\d+)$/);
        if (!m || m[1] === '' + ci) return;
        var fci = m[1];
        var sel = dd.querySelector('select');
        if (!sel) return;
        var op = sel.value;
        if (!op) return;
        if (op === 'between') {
            var bf = dd.querySelector('input[id^="sfilter_between_from_"]');
            var bt = dd.querySelector('input[id^="sfilter_between_to_"]');
            if (bf && bt && (bf.value.trim() !== '' || bt.value.trim() !== '')) {
                params += '&f_' + fci + '=between:' + encodeURIComponent(bf.value.trim()) + ':' + encodeURIComponent(bt.value.trim());
            }
        } else if (op === 'empty' || op === 'not_empty') {
            params += '&f_' + fci + '=' + op + ':';
        } else {
            var inp = document.getElementById('sfilter_input_' + fci);
            var val = inp ? inp.value.trim() : '';
            if (val !== '') {
                params += '&f_' + fci + '=' + op + ':' + encodeURIComponent(val);
            }
        }
    });
    var url = '?tab=sqlite&sub=table_data&table=' + encodeURIComponent(window._currentSqliteTable || '');
    url += '&page=1&perpage=' + (window._currentSqlitePerPage || 50);
    url += params;
    closeAllFiltersS();
    location.href = url;
}

// 点击页面其他地方关闭筛选下拉（MySQL）
document.addEventListener('click', function(e) {
    if (!e.target.closest('.filter-dropdown') && !e.target.closest('.col-filter-btn')) {
        closeAllFilters();
    }
});

// 点击页面其他地方关闭筛选下拉（SQLite）- separate handler to avoid conflict
document.addEventListener('click', function(e2) {
    if (!e2.target.closest('.filter-dropdown') && !e2.target.closest('.col-filter-btn')) {
        closeAllFiltersS();
    }
});

// ========== 聚合统计（已改为服务端自动计算，无需手动触发）==========
// doAgg / doAggSqlite 已移除，聚合统计现在是自动的

// ========== 可视化建表 ==========
function switchDesignTab(evt, tab) {
    document.querySelectorAll('.design-tab').forEach(function(t){ t.classList.remove('active'); });
    document.querySelectorAll('.design-panel').forEach(function(p){ p.classList.remove('active'); });
    evt.target.classList.add('active');
    document.getElementById('panel-' + tab).classList.add('active');
}

function addFieldRow() {
    var container = document.getElementById('fieldRows');
    var idx = fieldRowCounter++;
    var typeOptions = ['INT','BIGINT','TINYINT','SMALLINT','VARCHAR','CHAR','TEXT','MEDIUMTEXT','LONGTEXT',
        'DATE','DATETIME','TIMESTAMP','TIME','FLOAT','DOUBLE','DECIMAL','BOOLEAN','JSON','ENUM','SET','BLOB'];
    var opts = typeOptions.map(function(t){ return '<option value="'+t+'">'+t+'</option>'; }).join('');
    var html = '<div class="field-row">' +
        '<input type="text" class="col-name" name="fld_name[]" value="" placeholder="字段名">' +
        '<select class="col-type" name="fld_type[]" onchange="onTypeChange(this)">' + opts + '</select>' +
        '<input type="number" class="col-len" name="fld_len[]" value="" min="0" placeholder="长度">' +
        '<input type="number" class="col-dec" name="fld_dec[]" value="" min="0" placeholder="小数">' +
        '<span class="col-check"><input type="checkbox" name="fld_pk[]" value="' + idx + '" onchange="onPkChange(this)"></span>' +
        '<span class="col-check"><input type="checkbox" name="fld_null[]" value="' + idx + '" checked></span>' +
        '<span class="col-check"><input type="checkbox" name="fld_ai[]" value="' + idx + '" onchange="onAiChange(this)"></span>' +
        '<input type="text" class="col-name" name="fld_default[]" placeholder="NULL">' +
        '<input type="text" class="col-comment" name="fld_comment[]" placeholder="注释">' +
        '<button type="button" class="btn btn-outline btn-xs" style="color:#c92a2a" onclick="this.closest(\'.field-row\').remove()">✕</button>' +
        '</div>';
    container.insertAdjacentHTML('beforeend', html);
    updateAiVisibility(container.lastElementChild);
}

function addIdxRow() {
    var container = document.getElementById('idxRows');
    var html = '<div class="idx-row">' +
        '<input type="text" class="idx-name" name="idx_name[]" placeholder="索引名">' +
        '<select class="idx-type" name="idx_type[]">' +
        '<option value="INDEX">普通索引</option>' +
        '<option value="UNIQUE">唯一索引</option>' +
        '<option value="FULLTEXT">全文索引</option>' +
        '</select>' +
        '<input type="text" class="idx-cols" name="idx_cols[]" placeholder="列1,列2">' +
        '<button type="button" class="btn btn-outline btn-xs" style="color:#c92a2a" onclick="this.closest(\'.idx-row\').remove()">✕</button>' +
        '</div>';
    container.insertAdjacentHTML('beforeend', html);
}

function onTypeChange(sel) {
    var row = sel.closest('.field-row');
    if (!row) return;
    var lenInp = row.querySelector('.col-len');
    var decInp = row.querySelector('.col-dec');
    var type = sel.value.toUpperCase();
    // Show/hide length/decimal based on type
    var needsLen = ['VARCHAR','CHAR','INT','BIGINT','TINYINT','SMALLINT','MEDIUMINT'];
    var needsDec = ['DECIMAL','FLOAT','DOUBLE','NUMERIC'];
    lenInp.style.display = (needsLen.indexOf(type) >= 0 || needsDec.indexOf(type) >= 0) ? '' : 'none';
    decInp.style.display = (needsDec.indexOf(type) >= 0) ? '' : 'none';
    // 自增可见性：由统一函数管理
    updateAiVisibility(row);
}

function generatePreview() {
    var form = document.getElementById('designForm');
    var tableName = form.querySelector('input[name="table_name"]').value;
    if (!tableName) { document.getElementById('sqlPreview').textContent = '请先输入表名'; return; }

    var fieldNames = form.querySelectorAll('input[name="fld_name[]"]');
    var fieldTypes = form.querySelectorAll('select[name="fld_type[]"]');
    var fieldLens  = form.querySelectorAll('input[name="fld_len[]"]');
    var fieldDecs  = form.querySelectorAll('input[name="fld_dec[]"]');
    var fieldNulls = form.querySelectorAll('input[name="fld_null[]"]');
    var fieldPks   = form.querySelectorAll('input[name="fld_pk[]"]');
    var fieldAIs   = form.querySelectorAll('input[name="fld_ai[]"]');
    var fieldDefs  = form.querySelectorAll('input[name="fld_default[]"]');
    var fieldCmts  = form.querySelectorAll('input[name="fld_comment[]"]');

    var cols = [];
    var pkCols = [];
    for (var i = 0; i < fieldNames.length; i++) {
        var fn = fieldNames[i].value.trim();
        if (!fn) continue;
        var ft = fieldTypes[i].value;
        var fl = fieldLens[i].value;
        var fd = fieldDecs[i].value;
        var nn = fieldNulls[i] ? fieldNulls[i].checked : false;
        var pk = fieldPks[i] ? fieldPks[i].checked : false;
        var ai = fieldAIs[i] ? fieldAIs[i].checked : false;
        var def = fieldDefs[i].value;
        var cmt = fieldCmts[i].value;

        // AUTO_INCREMENT 必须 NOT NULL → 强制覆盖
        if (ai) { nn = true; def = ''; }

        var typeStr = ft;
        if (fl && ['VARCHAR','CHAR','INT','BIGINT','TINYINT','SMALLINT','MEDIUMINT'].indexOf(ft) >= 0) {
            typeStr += '(' + fl + ')';
        } else if (fl && ['DECIMAL','FLOAT','DOUBLE','NUMERIC'].indexOf(ft) >= 0) {
            if (fd) typeStr += '(' + fl + ',' + fd + ')';
            else typeStr += '(' + fl + ')';
        }
        var colDef = '  `' + fn + '` ' + typeStr;
        if (nn) colDef += ' NOT NULL';
        if (def !== '') {
            if (def.toUpperCase() === 'NULL') colDef += ' DEFAULT NULL';
            else if (def.toUpperCase() === 'CURRENT_TIMESTAMP') colDef += ' DEFAULT CURRENT_TIMESTAMP';
            else colDef += " DEFAULT '" + def + "'";
        } else if (!nn) {
            colDef += ' DEFAULT NULL';
        }
        if (ai) colDef += ' AUTO_INCREMENT';
        if (cmt) colDef += " COMMENT '" + cmt + "'";
        if (pk) pkCols.push('`' + fn + '`');
        cols.push(colDef);
    }

    var sql = 'CREATE TABLE `' + tableName + '` (\n';
    sql += cols.join(',\n');
    if (pkCols.length) sql += ',\n  PRIMARY KEY (' + pkCols.join(',') + ')';
    sql += '\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;';

    // Indexes
    var idxNames = form.querySelectorAll('input[name="idx_name[]"]');
    var idxTypes = form.querySelectorAll('select[name="idx_type[]"]');
    var idxCols  = form.querySelectorAll('input[name="idx_cols[]"]');
    for (var j = 0; j < idxNames.length; j++) {
        var iname = idxNames[j].value.trim();
        if (!iname) continue;
        var itype = idxTypes[j].value;
        var icols = idxCols[j].value.trim();
        if (!icols) continue;
        var idxType = itype === 'UNIQUE' ? 'UNIQUE INDEX' : (itype === 'FULLTEXT' ? 'FULLTEXT INDEX' : 'INDEX');
        sql += '\nCREATE ' + idxType + ' `' + iname + '` ON `' + tableName + '` (' + icols + ');';
    }

    document.getElementById('sqlPreview').textContent = sql;
}

// ========== 可视化建表（create_table 子页面） ==========
var crFieldCounter = 2;

/** 初始化字段行自增勾选框的可见性（页面加载 + 动态添加后调用） */
function initCrAiVisibility() {
    var rows = document.querySelectorAll('#crFieldRows .cr-field-row');
    rows.forEach(function(row) {
        updateAiVisibility(row);
    });
}
/** 设计表视图（table_design 子页面）的初始化 */
function initDesignAiVisibility() {
    var rows = document.querySelectorAll('#fieldRows .field-row');
    rows.forEach(function(row) {
        updateAiVisibility(row);
    });
}

/** 自增勾选框始终可见，但仅对整数类型可勾选（非整数类型置灰禁用） */
function updateAiVisibility(row) {
    if (!row) return;
    var typeSel = row.querySelector('select[name="fld_type[]"]');
    var aiCb = row.querySelector('input[name="fld_ai[]"]');
    if (!aiCb) return;
    var isInt = typeSel && isIntType(typeSel.value.toUpperCase());
    if (isInt) {
        aiCb.disabled = false;
        if (aiCb.parentElement) aiCb.parentElement.style.opacity = '';
        if (aiCb.parentElement) aiCb.parentElement.style.cursor = '';
    } else {
        aiCb.disabled = true;
        aiCb.checked = false;
        if (aiCb.parentElement) aiCb.parentElement.style.opacity = '0.45';
        if (aiCb.parentElement) aiCb.parentElement.style.cursor = 'not-allowed';
    }
}

function addCrFieldRow() {
    var container = document.getElementById('crFieldRows');
    if (!container) return;
    var idx = crFieldCounter++;
    var types = ['INT','BIGINT','TINYINT','SMALLINT','MEDIUMINT','VARCHAR','CHAR','TEXT','MEDIUMTEXT','LONGTEXT',
        'DATE','DATETIME','TIMESTAMP','TIME','YEAR','FLOAT','DOUBLE','DECIMAL','BOOLEAN','JSON','ENUM','SET','BLOB','LONGBLOB'];
    var opts = types.map(function(t){ return '<option value="'+t+'">'+t+'</option>'; }).join('');
    var html = '<div class="cr-field-row">' +
        '<span class="cr-col-seq"></span>' +
        '<input type="text" class="cr-col-name" name="fld_name[]" value="" placeholder="字段名">' +
        '<select class="cr-col-type" name="fld_type[]" onchange="onCrTypeChange(this)">' + opts + '</select>' +
        '<input type="text" class="cr-col-len" name="fld_len[]" value="" placeholder="长度" oninput="this.value=this.value.replace(/[^\\d]/g,\'\')">' +
        '<input type="text" class="cr-col-dec" name="fld_dec[]" value="" placeholder="小数" style="display:none" oninput="this.value=this.value.replace(/[^\\d]/g,\'\')">' +
        '<span class="cr-col-chk"><input type="checkbox" name="fld_null[]" value="' + idx + '">非空</span>' +
        '<span class="cr-col-chk"><input type="checkbox" name="fld_pk[]" value="' + idx + '" onchange="onPkChange(this)">主键</span>' +
        '<span class="cr-col-chk"><input type="checkbox" name="fld_ai[]" value="' + idx + '" onchange="onAiChange(this)">自增</span>' +
        '<input type="text" class="cr-col-def" name="fld_default[]" value="" placeholder="默认值">' +
        '<input type="text" class="cr-col-cmt" name="fld_comment[]" value="" placeholder="注释">' +
        '<span class="cr-col-act">' +
        '<button type="button" class="btn btn-outline btn-xs" onclick="moveCrRow(this,-1)" title="上移">▲</button>' +
        '<button type="button" class="btn btn-outline btn-xs" onclick="moveCrRow(this,1)" title="下移">▼</button>' +
        '<button type="button" class="btn btn-outline btn-xs" onclick="delCrRow(this)" title="删除" style="color:#c92a2a">✕</button>' +
        '</span></div>';
    container.insertAdjacentHTML('beforeend', html);
    updateAiVisibility(container.lastElementChild);
    updateSeqNumbers();
}

function delCrRow(btn) {
    var row = btn.closest('.cr-field-row');
    if (!row) return;
    var rows = row.parentElement.querySelectorAll('.cr-field-row');
    if (rows.length <= 1) { alert('至少保留一个字段'); return; }
    row.remove();
    reindexCheckboxes();
    updateSeqNumbers();
}

function moveCrRow(btn, dir) {
    var row = btn.closest('.cr-field-row');
    if (!row) return;
    var parent = row.parentElement;
    var rows = parent.querySelectorAll('.cr-field-row');
    var idx = Array.prototype.indexOf.call(rows, row);
    if (dir < 0 && idx > 0) {
        parent.insertBefore(row, rows[idx-1]);
    } else if (dir > 0 && idx < rows.length - 1) {
        parent.insertBefore(rows[idx+1], row);
    }
    reindexCheckboxes();
    updateSeqNumbers();
}

function reindexCheckboxes() {
    var rows = document.querySelectorAll('#crFieldRows .cr-field-row');
    rows.forEach(function(row, i){
        var cbs = row.querySelectorAll('input[type="checkbox"]');
        cbs.forEach(function(cb){ cb.value = i; });
    });
}

function updateSeqNumbers() {
    var seqs = document.querySelectorAll('#crFieldRows .cr-col-seq');
    seqs.forEach(function(s, i){ s.textContent = i + 1; });
}

function isIntType(type) {
    return ['INT','BIGINT','TINYINT','SMALLINT','MEDIUMINT'].indexOf(type) >= 0;
}

function onCrTypeChange(sel) {
    var row = sel.closest('.cr-field-row');
    if (!row) return;
    var lenInp = row.querySelector('.cr-col-len');
    var decInp = row.querySelector('.cr-col-dec');
    var type = sel.value.toUpperCase();

    // 显示"长度"列：VARCHAR/CHAR/INT类/DECIMAL等都需要
    var needsLen = ['VARCHAR','CHAR','INT','BIGINT','TINYINT','SMALLINT','MEDIUMINT','DECIMAL','FLOAT','DOUBLE','NUMERIC','ENUM','SET'];
    if (needsLen.indexOf(type) >= 0) {
        lenInp.style.display = '';
        if (type === 'VARCHAR' || type === 'CHAR') { lenInp.placeholder = '长度'; }
        else if (type === 'DECIMAL') { lenInp.placeholder = '总长度'; }
        else if (isIntType(type)) { lenInp.placeholder = '显示宽度'; }
        else { lenInp.placeholder = '长度'; }
    } else {
        lenInp.style.display = 'none';
        lenInp.value = '';
    }

    // 显示"小数"列：DECIMAL/FLOAT/DOUBLE/NUMERIC
    if (['DECIMAL','FLOAT','DOUBLE','NUMERIC'].indexOf(type) >= 0) {
        decInp.style.display = '';
    } else {
        decInp.style.display = 'none';
        decInp.value = '';
    }

    // 自增复选框可见性：由统一函数管理
    updateAiVisibility(row);
}

function onPkChange(cb) {
    var row = cb.closest('.field-row') || cb.closest('.cr-field-row');
    if (!row) return;
    updateAiVisibility(row);
}

function onAiChange(cb) {
    if (cb.checked) {
        var row = cb.closest('.field-row') || cb.closest('.cr-field-row');
        if (!row) return;
        // 仅整数类型允许勾选自增，非整数类型直接拒绝
        var typeSel = row.querySelector('select[name="fld_type[]"]');
        if (typeSel && !isIntType(typeSel.value.toUpperCase())) {
            cb.checked = false;
            return;
        }
        var pkCb = row.querySelector('input[name="fld_pk[]"]');
        if (pkCb) pkCb.checked = true;
        // AUTO_INCREMENT 必须 NOT NULL → 自动勾选非空
        var nullCb = row.querySelector('input[name="fld_null[]"]');
        if (nullCb) nullCb.checked = true;

        // 一张表只允许一个自增字段 → 取消其他所有行的自增
        var container = row.closest('#fieldRows') || row.closest('#crFieldRows');
        if (container) {
            var allRows = container.querySelectorAll('.field-row, .cr-field-row');
            for (var r = 0; r < allRows.length; r++) {
                var otherAi = allRows[r].querySelector('input[name="fld_ai[]"]');
                if (otherAi && otherAi !== cb) {
                    otherAi.checked = false;
                }
            }
        }
    }
}

function addCrIdxRow() {
    var container = document.getElementById('crIdxRows');
    if (!container) return;
    var html = '<div class="cr-idx-row">' +
        '<input type="text" class="cr-idx-name" name="idx_name[]" placeholder="索引名">' +
        '<select class="cr-idx-type" name="idx_type[]">' +
        '<option value="INDEX">普通索引</option>' +
        '<option value="UNIQUE">唯一索引</option>' +
        '<option value="FULLTEXT">全文索引</option>' +
        '</select>' +
        '<input type="text" class="cr-idx-cols" name="idx_cols[]" placeholder="字段1,字段2">' +
        '<button type="button" class="btn btn-outline btn-xs" onclick="this.closest(\'.cr-idx-row\').remove()" title="删除" style="color:#c92a2a">✕</button>' +
        '</div>';
    container.insertAdjacentHTML('beforeend', html);
}

function crGeneratePreview() {
    var form = document.getElementById('createTableForm');
    if (!form) return;
    var tableName = form.querySelector('input[name="table_name"]').value.trim();
    if (!tableName) { document.getElementById('crSqlPreview').textContent = '请先输入表名'; return; }

    var engine = form.querySelector('select[name="engine"]').value;
    var charset = form.querySelector('select[name="charset"]').value;
    var collation = form.querySelector('select[name="collation"]').value;
    var comment = form.querySelector('input[name="table_comment"]').value.trim();

    var fieldNames = form.querySelectorAll('input[name="fld_name[]"]');
    var fieldTypes = form.querySelectorAll('select[name="fld_type[]"]');
    var fieldLens  = form.querySelectorAll('input[name="fld_len[]"]');
    var fieldDecs  = form.querySelectorAll('input[name="fld_dec[]"]');
    var fieldNulls = form.querySelectorAll('input[name="fld_null[]"]');
    var fieldPks   = form.querySelectorAll('input[name="fld_pk[]"]');
    var fieldAIs   = form.querySelectorAll('input[name="fld_ai[]"]');
    var fieldDefs  = form.querySelectorAll('input[name="fld_default[]"]');
    var fieldCmts  = form.querySelectorAll('input[name="fld_comment[]"]');

    var cols = [];
    var pkCols = [];
    for (var i = 0; i < fieldNames.length; i++) {
        var fn = fieldNames[i].value.trim();
        if (!fn) continue;
        var ft = fieldTypes[i].value;
        var fl = fieldLens[i].value;
        var fd = fieldDecs[i].value;
        var nn = fieldNulls[i] ? fieldNulls[i].checked : false; // checked = NOT NULL
        var pk = fieldPks[i] ? fieldPks[i].checked : false;
        var ai = fieldAIs[i] ? fieldAIs[i].checked : false;
        var def = fieldDefs[i].value.trim();
        var cmt = fieldCmts[i].value.trim();

        // AUTO_INCREMENT 必须 NOT NULL → 强制覆盖
        if (ai) { nn = true; def = ''; }

        var typeStr = ft;
        if (fl && ['VARCHAR','CHAR'].indexOf(ft) >= 0) {
            typeStr += '(' + fl + ')';
        } else if (fl && isIntType(ft)) {
            typeStr += '(' + fl + ')';
        } else if (fl && ['DECIMAL','FLOAT','DOUBLE','NUMERIC'].indexOf(ft) >= 0) {
            if (fd) typeStr += '(' + fl + ',' + fd + ')';
            else typeStr += '(' + fl + ')';
        } else if (fl && ['ENUM','SET'].indexOf(ft) >= 0) {
            typeStr += '(' + fl + ')';
        }
        var colDef = '  `' + fn + '` ' + typeStr;
        if (nn) colDef += ' NOT NULL';
        if (def !== '') {
            if (def.toUpperCase() === 'NULL') colDef += ' DEFAULT NULL';
            else if (def.toUpperCase() === 'CURRENT_TIMESTAMP') colDef += ' DEFAULT CURRENT_TIMESTAMP';
            else colDef += " DEFAULT '" + def.replace(/'/g,"\\'") + "'";
        } else if (!nn) {
            colDef += ' DEFAULT NULL';
        }
        if (ai) colDef += ' AUTO_INCREMENT';
        if (cmt) colDef += " COMMENT '" + cmt.replace(/'/g,"\\'") + "'";
        if (pk) pkCols.push('`' + fn + '`');
        cols.push(colDef);
    }

    if (cols.length === 0) { document.getElementById('crSqlPreview').textContent = '请至少添加一个字段'; return; }

    var sql = 'CREATE TABLE `' + tableName + '` (\n';
    sql += cols.join(',\n');
    if (pkCols.length) sql += ',\n  PRIMARY KEY (' + pkCols.join(',') + ')';
    sql += '\n) ENGINE=' + engine + ' DEFAULT CHARSET=' + charset + ' COLLATE=' + collation;
    if (comment) sql += " COMMENT='" + comment.replace(/'/g,"\\'") + "'";
    sql += ';';

    // Indexes
    var idxNames = form.querySelectorAll('input[name="idx_name[]"]');
    var idxTypes = form.querySelectorAll('select[name="idx_type[]"]');
    var idxCols  = form.querySelectorAll('input[name="idx_cols[]"]');
    for (var j = 0; j < idxNames.length; j++) {
        var iname = idxNames[j].value.trim();
        if (!iname) continue;
        var itype = idxTypes[j].value;
        var icols = idxCols[j].value.trim();
        if (!icols) continue;
        var idxType = itype === 'UNIQUE' ? 'UNIQUE INDEX' : (itype === 'FULLTEXT' ? 'FULLTEXT INDEX' : 'INDEX');
        sql += '\nCREATE ' + idxType + ' `' + iname + '` ON `' + tableName + '` (' + icols + ');';
    }

    document.getElementById('crSqlPreview').textContent = sql;
}

// 字符集与排序规则联动
function onCharsetChange() {
    var cs = document.getElementById('tblCharset');
    var col = document.getElementById('tblCollation');
    if (!cs || !col) return;
    var charset = cs.value;
    var map = {
        'utf8mb4': ['utf8mb4_unicode_ci','utf8mb4_general_ci','utf8mb4_bin'],
        'utf8': ['utf8_unicode_ci','utf8_general_ci','utf8_bin'],
        'latin1': ['latin1_swedish_ci','latin1_general_ci','latin1_bin'],
        'gbk': ['gbk_chinese_ci','gbk_bin'],
        'gb2312': ['gb2312_chinese_ci','gb2312_bin']
    };
    col.innerHTML = '';
    var opts = map[charset] || ['utf8mb4_unicode_ci'];
    opts.forEach(function(o){
        col.innerHTML += '<option value="' + o + '">' + o + '</option>';
    });
}

function crValidateForm() {
    var form = document.getElementById('createTableForm');
    if (!form) return true;
    var tableName = form.querySelector('input[name="table_name"]').value.trim();
    if (!tableName) { alert('请输入表名'); return false; }
    var fields = form.querySelectorAll('input[name="fld_name[]"]');
    var hasField = false;
    fields.forEach(function(f){ if (f.value.trim() !== '') hasField = true; });
    if (!hasField) { alert('至少需要添加一个字段'); return false; }
    return true;
}

// Initialize charset/collation on page load, and AI checkbox visibility
(function() {
    if (document.getElementById('tblCharset')) {
        onCharsetChange();
    }
    if (document.getElementById('crFieldRows')) {
        initCrAiVisibility();
    }
    if (document.getElementById('fieldRows')) {
        initDesignAiVisibility();
    }
})();
</script>

<!-- 数据同步弹窗 -->
<?php $syncCfg = $_SESSION['mysql_config'] ?? []; ?>
<div id="syncModal" class="modal-overlay" style="display:none">
<div class="modal" style="max-width:820px;width:92%;max-height:90vh;padding:20px 24px">
<h3>全量数据同步</h3>
<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:16px">
<div>
<div style="font-weight:600;margin-bottom:8px;font-size:14px;color:#333">源数据库</div>
<div class="form-group"><label>主机</label><input id="sync_src_host" value="<?= h($syncCfg['host'] ?? '127.0.0.1') ?>"></div>
<div class="form-group"><label>端口</label><input id="sync_src_port" value="<?= h($syncCfg['port'] ?? '3306') ?>"></div>
<div class="form-group"><label>用户名</label><input id="sync_src_user" value="<?= h($syncCfg['user'] ?? 'root') ?>"></div>
<div class="form-group"><label>密码</label><input type="password" id="sync_src_pass" value="<?= h($syncCfg['pass'] ?? '') ?>"></div>
<div class="form-group"><label>数据库名</label><input id="sync_src_dbname" value="<?= h($syncCfg['dbname'] ?? '') ?>" placeholder="源库名"></div>
</div>
<div>
<div style="font-weight:600;margin-bottom:8px;font-size:14px;color:#333">目标数据库</div>
<div class="form-group"><label>主机</label><input id="sync_tgt_host" value="<?= h($syncCfg['host'] ?? '127.0.0.1') ?>"></div>
<div class="form-group"><label>端口</label><input id="sync_tgt_port" value="<?= h($syncCfg['port'] ?? '3306') ?>"></div>
<div class="form-group"><label>用户名</label><input id="sync_tgt_user" value="<?= h($syncCfg['user'] ?? 'root') ?>"></div>
<div class="form-group"><label>密码</label><input type="password" id="sync_tgt_pass"></div>
<div class="form-group"><label>数据库名</label><input id="sync_tgt_dbname" placeholder="目标库名"></div>
</div>
</div>
<button class="btn btn-primary btn-sm" onclick="loadSyncTables()" id="syncLoadBtn">加载数据表</button>
<div id="syncTablesWrap" style="display:none;margin-top:12px;max-height:240px;overflow-y:auto;border:1px solid #eee;border-radius:6px;padding:10px">
<div style="font-size:13px;color:#666;margin-bottom:6px">选择要同步的表（默认全选）：</div>
<label style="margin-bottom:4px;display:block;font-size:13px;cursor:pointer"><input type="checkbox" id="syncCheckAll" onchange="toggleSyncAll()" checked> 全选 / 取消全选</label>
<div id="syncTablesList" style="display:grid;grid-template-columns:repeat(3,1fr);gap:2px 10px;font-size:13px"></div>
</div>
<div id="syncActions" style="display:none;margin-top:14px;display:flex;gap:8px;align-items:center">
<button class="btn btn-primary btn-sm" onclick="startSync('all')" id="syncAllBtn">整库同步</button>
<button class="btn btn-outline btn-sm" onclick="startSync('selected')" id="syncSelBtn">同步选中表</button>
<span id="syncStatus" style="font-size:13px;color:#666;margin-left:8px"></span>
</div>
<div style="display:flex;gap:8px;margin-top:16px;justify-content:flex-end">
<button class="btn btn-outline btn-sm" onclick="closeSyncModal()">关闭</button>
</div>
</div>
</div>

<script>
function openSyncModal(){ document.getElementById('syncModal').style.display='flex'; }
function closeSyncModal(){
    document.getElementById('syncModal').style.display='none';
    document.getElementById('syncTablesWrap').style.display='none';
    document.getElementById('syncActions').style.display='none';
    document.getElementById('syncStatus').textContent='';
    document.getElementById('syncTablesList').innerHTML='';
}
function toggleSyncAll(){
    var c=document.getElementById('syncCheckAll').checked;
    document.querySelectorAll('.sync-tbl-cb').forEach(function(b){b.checked=c;});
}
function loadSyncTables(){
    var s=document.getElementById('syncStatus'); s.textContent='加载中...';
    var fd=new FormData();
    fd.append('action','list_tables');
    fd.append('src_host',document.getElementById('sync_src_host').value);
    fd.append('src_port',document.getElementById('sync_src_port').value);
    fd.append('src_user',document.getElementById('sync_src_user').value);
    fd.append('src_pass',document.getElementById('sync_src_pass').value);
    fd.append('src_dbname',document.getElementById('sync_src_dbname').value);
    fetch('sync.php',{method:'POST',body:fd}).then(function(r){return r.json();}).then(function(d){
        if(!d.success){s.textContent=d.message;return;}
        var html='';
        d.tables.forEach(function(t){
            html+='<label style="display:block;cursor:pointer;margin:1px 0"><input type="checkbox" class="sync-tbl-cb" value="'+escapeHtml(t)+'" checked> '+escapeHtml(t)+'</label>';
        });
        document.getElementById('syncTablesList').innerHTML=html;
        document.getElementById('syncTablesWrap').style.display='block';
        document.getElementById('syncActions').style.display='flex';
        document.getElementById('syncCheckAll').checked=true;
        s.textContent='共 '+d.tables.length+' 张表';
    }).catch(function(e){s.textContent='请求失败: '+e.message;});
}
function startSync(mode){
    var s=document.getElementById('syncStatus'),
        btnA=document.getElementById('syncAllBtn'),
        btnS=document.getElementById('syncSelBtn');
    s.textContent='同步中...'; btnA.disabled=true; btnS.disabled=true;
    var tables=[];
    if(mode==='selected'){
        document.querySelectorAll('.sync-tbl-cb:checked').forEach(function(b){tables.push(b.value);});
        if(tables.length===0){s.textContent='请至少勾选一张表'; btnA.disabled=false; btnS.disabled=false; return;}
    }
    var fd=new FormData();
    fd.append('action','sync');
    fd.append('mode',mode);
    if(mode==='selected') fd.append('tables',JSON.stringify(tables));
    var fields=['src_host','src_port','src_user','src_pass','src_dbname','tgt_host','tgt_port','tgt_user','tgt_pass','tgt_dbname'];
    fields.forEach(function(f){fd.append(f,document.getElementById('sync_'+f).value);});
    fetch('sync.php',{method:'POST',body:fd}).then(function(r){return r.json();}).then(function(d){
        if(!d.success){s.textContent='失败: '+d.message; btnA.disabled=false; btnS.disabled=false; return;}
        var msg='完成! 成功 '+d.total_success+' 张, 失败 '+d.total_fail+' 张';
        if(d.results) d.results.forEach(function(r){msg+='\n'+r.table+': '+(r.status==='success'?r.rows+' 行':r.message);});
        s.textContent=msg; s.style.whiteSpace='pre-line';
        btnA.disabled=false; btnS.disabled=false;
    }).catch(function(e){s.textContent='请求失败: '+e.message; btnA.disabled=false; btnS.disabled=false;});
}
document.addEventListener('click',function(e){if(e.target.id==='syncModal')closeSyncModal();});
</script>

</body>
</html>
