<?php
/**
 * 批量执行 SQL - 独立工具
 * 复用 db_tool.php 的 Session 连接配置，风格统一
 * 支持 MySQL / SQLite，支持粘贴 SQL 或上传 SQL 文件
 * 逐条执行，实时显示每条语句的结果
 */

session_start();

// ============================================================
// 辅助函数（与 db_tool.php 保持一致）
// ============================================================
function h($str) {
    return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8');
}
function post($key, $default = '') {
    return isset($_POST[$key]) ? (string)$_POST[$key] : $default;
}
function get($key, $default = '') {
    return isset($_GET[$key]) ? (string)$_GET[$key] : $default;
}

/** 从 Session 配置重连 MySQL */
function mysqlConnect() {
    $c = $_SESSION['mysql_config'] ?? null;
    if (!$c) return [null, '请先在主页面连接 MySQL'];
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
    if (!$c) return [null, '请先在主页面打开 SQLite 文件'];
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

// ============================================================
// 路由
// ============================================================
$tab = get('tab', 'mysql');

// 检测连接状态
if ($tab === 'sqlite') {
    $connInfo = $_SESSION['sqlite_config'] ?? null;
} else {
    $connInfo = $_SESSION['mysql_config'] ?? null;
}
$isConnected = $connInfo !== null;

// 处理执行请求
$results = [];
$sqlInput = '';
$error = '';
$skipDDL = post('skip_ddl', '1') === '1';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'batch_exec') {
    // 获取 SQL 内容
    $sqlInput = post('sql_content', '');
    if (isset($_FILES['sql_file']) && $_FILES['sql_file']['error'] === UPLOAD_ERR_OK) {
        $sqlInput = file_get_contents($_FILES['sql_file']['tmp_name']);
    }

    if (trim($sqlInput) === '') {
        $error = '请输入 SQL 语句或上传 SQL 文件';
    } elseif (!$isConnected) {
        $error = '请先在主页面连接数据库';
    } else {
        // 连接数据库
        if ($tab === 'sqlite') {
            list($pdo, $err) = sqliteConnect();
        } else {
            list($pdo, $err) = mysqlConnect();
        }

        if ($pdo) {
            // 移除 BOM
            if (substr($sqlInput, 0, 3) === chr(0xEF).chr(0xBB).chr(0xBF)) {
                $sqlInput = substr($sqlInput, 3);
            }

            // 按分号拆分语句
            $rawStatements = preg_split('/;\s*\n|;\s*$/', $sqlInput, -1, PREG_SPLIT_NO_EMPTY);
            $statements = [];
            foreach ($rawStatements as $s) {
                $s = trim($s);
                if ($s === '') continue;
                $statements[] = $s;
            }

            if (empty($statements)) {
                $error = '未检测到有效 SQL 语句（请确保每条语句以分号结尾）';
            } else {
                $successCount = 0;
                $failCount = 0;
                $skipCount = 0;
                $totalRowsAffected = 0;

                foreach ($statements as $idx => $sql) {
                    // 跳过注释
                    if (preg_match('/^--/', $sql) || preg_match('/^\/\*/', $sql)) {
                        $skipCount++;
                        $results[] = [
                            'no'   => $idx + 1,
                            'sql'  => mb_strlen($sql) > 80 ? mb_substr($sql, 0, 80) . '...' : $sql,
                            'type' => 'skip',
                            'msg'  => '注释，已跳过',
                        ];
                        continue;
                    }

                    // 跳过 DDL 语句（可选）
                    if ($skipDDL && preg_match('/^(CREATE|DROP|ALTER)\s+(TABLE|INDEX|VIEW|DATABASE|SCHEMA|TRIGGER|PROCEDURE|FUNCTION)/i', $sql)) {
                        $skipCount++;
                        $results[] = [
                            'no'   => $idx + 1,
                            'sql'  => mb_strlen($sql) > 80 ? mb_substr($sql, 0, 80) . '...' : $sql,
                            'type' => 'skip',
                            'msg'  => 'DDL 语句，已跳过',
                        ];
                        continue;
                    }

                    try {
                        $stmt = $pdo->query($sql);

                        $rowCount = 0;
                        $selectRows = [];
                        $columns = [];

                        if ($stmt && $stmt->columnCount() > 0) {
                            // SELECT / SHOW 等返回结果集的语句
                            $selectRows = $stmt->fetchAll();
                            $columns = array_keys($selectRows[0] ?? []);
                            $rowCount = count($selectRows);
                        } elseif ($stmt) {
                            $rowCount = $stmt->rowCount();
                        } else {
                            // exec-style statements (CREATE, INSERT, etc. that don't return a result set)
                            $rowCount = 0;
                        }

                        $totalRowsAffected += $rowCount;
                        $successCount++;

                        $shortSQL = mb_strlen($sql) > 100 ? mb_substr($sql, 0, 100) . '...' : $sql;
                        if ($rowCount > 0 || !empty($columns)) {
                            $results[] = [
                                'no'    => $idx + 1,
                                'sql'   => $shortSQL,
                                'type'  => 'success',
                                'msg'   => $rowCount . ' 行',
                                'rows'  => $selectRows,
                                'cols'  => $columns,
                            ];
                        } else {
                            $results[] = [
                                'no'   => $idx + 1,
                                'sql'  => $shortSQL,
                                'type' => 'success',
                                'msg'  => '执行成功',
                            ];
                        }
                    } catch (Exception $e) {
                        $failCount++;
                        $errMsg = $e->getMessage();
                        if (mb_strlen($errMsg) > 200) $errMsg = mb_substr($errMsg, 0, 200) . '...';
                        $results[] = [
                            'no'    => $idx + 1,
                            'sql'   => mb_strlen($sql) > 100 ? mb_substr($sql, 0, 100) . '...' : $sql,
                            'type'  => 'error',
                            'msg'   => $errMsg,
                        ];
                    }
                }

                // 汇总结果存入 session 供渲染使用
                $_SESSION['batch_result'] = [
                    'results'          => $results,
                    'total'            => count($statements),
                    'successCount'     => $successCount,
                    'failCount'        => $failCount,
                    'skipCount'        => $skipCount,
                    'totalRowsAffected'=> $totalRowsAffected,
                    'sqlInput'         => $sqlInput,
                    'skipDDL'          => $skipDDL,
                    'time'             => date('H:i:s'),
                ];
            }
        } else {
            $error = $err;
        }
    }

    // 存储 error 到 session
    $_SESSION['batch_error'] = $error;
    $_SESSION['batch_sql_input'] = $sqlInput;
    $_SESSION['batch_skip_ddl'] = $skipDDL;

    header('Location: ' . $_SERVER['PHP_SELF'] . '?tab=' . urlencode($tab));
    exit;
}

// 读取 session 中的结果
$batchResult = $_SESSION['batch_result'] ?? null;
$batchError  = $_SESSION['batch_error'] ?? '';
$sqlInput    = $_SESSION['batch_sql_input'] ?? '';
$skipDDL     = $_SESSION['batch_skip_ddl'] ?? true;
unset($_SESSION['batch_result'], $_SESSION['batch_error'], $_SESSION['batch_sql_input'], $_SESSION['batch_skip_ddl']);

// 连接信息用于页面标题
$dbLabel = '';
if ($isConnected) {
    if ($tab === 'sqlite') {
        $dbLabel = 'SQLite: ' . h(basename($connInfo['path']));
    } else {
        $dbLabel = 'MySQL: ' . h($connInfo['user'] . '@' . $connInfo['host'] . ':' . $connInfo['port'] . '/' . ($connInfo['dbname'] ?: '?'));
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>批量执行 SQL — DB Tool</title>
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
.flash-info{background:#e7f1ff;color:#1c7ed6;border:1px solid #bdd7f5}
.form-group{margin-bottom:14px}
.form-group label{display:block;font-size:13px;font-weight:500;color:#555;margin-bottom:4px}
.form-group textarea{width:100%;padding:10px 12px;border:1px solid #ddd;border-radius:6px;font-size:14px;font-family:"SFMono-Regular",Consolas,"Liberation Mono",Menlo,monospace;resize:vertical;min-height:180px;transition:border-color .2s}
.form-group textarea:focus{outline:none;border-color:#e94560;box-shadow:0 0 0 3px rgba(233,69,96,.1)}
.form-group input[type="file"]{width:100%;padding:8px 12px;border:1px solid #ddd;border-radius:6px;font-size:14px;font-family:inherit;background:#fafafa}
.check-group{display:flex;align-items:center;gap:8px;margin-bottom:14px}
.check-group input[type="checkbox"]{width:16px;height:16px;accent-color:#e94560;cursor:pointer}
.check-group label{cursor:pointer;font-size:13px;color:#555;user-select:none}
.btn{padding:8px 18px;border:none;border-radius:6px;font-size:14px;cursor:pointer;font-weight:500;transition:all .2s;text-decoration:none;display:inline-block}
.btn-primary{background:#e94560;color:#fff}
.btn-primary:hover{background:#d63850}
.btn-outline{background:transparent;border:1px solid #ddd;color:#555}
.btn-outline:hover{background:#f5f5f5}
.btn-sm{padding:4px 10px;font-size:12px}
.btn-danger{background:#c92a2a;color:#fff}
.btn-danger:hover{background:#a61e1e}
table{width:100%;border-collapse:collapse}
table th,table td{padding:8px 12px;text-align:left;border-bottom:1px solid #eee;font-size:13px}
table th{background:#f8f9fa;font-weight:600;color:#555;white-space:nowrap}
table tr:hover{background:#f8f9fa}
table td{word-break:break-all;max-width:500px}
.result-table td:first-child{width:50px;text-align:center;font-weight:600}
.result-success{color:#1a7d36}
.result-error{color:#c92a2a}
.result-skip{color:#888}
.result-sql{font-family:"SFMono-Regular",Consolas,"Liberation Mono",Menlo,monospace;font-size:12px;color:#555;max-width:400px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.result-msg{font-size:13px;word-break:break-word}
.summary-bar{display:flex;gap:16px;flex-wrap:wrap;padding:12px 16px;background:#f8f9fa;border-radius:6px;margin-bottom:16px;border:1px solid #e0e0e0;align-items:center}
.summary-item{font-size:14px}
.summary-item strong{font-size:16px}
.sub-result{background:#fafbfd;border:1px solid #e8e8e8;border-radius:4px;margin:4px 0;overflow:hidden}
.sub-result-header{padding:8px 12px;font-size:12px;font-family:monospace;cursor:pointer;display:flex;justify-content:space-between;align-items:center}
.sub-result-header:hover{background:#f0f2f5}
.sub-result-body{display:none;padding:0 12px 8px}
.sub-result-body.show{display:block}
.empty{padding:60px;text-align:center;color:#999;font-size:14px}
.info-bar{display:flex;align-items:center;gap:8px;margin-bottom:16px;font-size:13px;color:#888}
.info-bar .dot{width:8px;height:8px;border-radius:50%}
.dot-green{background:#2ecc71}
.dot-red{background:#e74c3c}
.flex-gap{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.mt-sm{margin-top:8px}
.mb-sm{margin-bottom:8px}
.badge{display:inline-block;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:500}
.badge-green{background:#e6f7e9;color:#1a7d36}
.badge-red{background:#ffeaea;color:#c92a2a}
.badge-blue{background:#e7f1ff;color:#1c7ed6}
.badge-gray{background:#f0f0f0;color:#888}
</style>
</head>
<body>

<div class="header">
    <h1>🗄️ DB Tool — 批量执行 SQL</h1>
    <nav>
        <a href="db_tool.php?tab=mysql"  class="<?= $tab==='mysql'?'active':'' ?>">MySQL</a>
        <a href="db_tool.php?tab=sqlite" class="<?= $tab==='sqlite'?'active':'' ?>">SQLite</a>
        <a href="batch_sql.php?tab=mysql"  style="color:#e94560;font-weight:600">批量SQL</a>
    </nav>
</div>

<div class="container">

    <!-- 连接状态 -->
    <div class="info-bar">
        <?php if ($isConnected): ?>
            <span class="dot dot-green"></span>
            <span>已连接 — <?= $dbLabel ?></span>
            <a href="db_tool.php?tab=<?= h($tab) ?>" class="btn btn-outline btn-sm" style="margin-left:12px">← 返回主页面</a>
        <?php else: ?>
            <span class="dot dot-red"></span>
            <span>未连接 — 请先在主页面连接数据库</span>
            <a href="db_tool.php?tab=<?= h($tab) ?>" class="btn btn-primary btn-sm" style="margin-left:12px">去连接</a>
        <?php endif; ?>
    </div>

    <!-- SQL 输入区 -->
    <div class="card">
        <div class="card-header">
            <span>SQL 语句（多条语句请用分号 ; 分隔）</span>
            <span style="font-weight:400;font-size:12px;color:#888">支持粘贴或上传 .sql 文件</span>
        </div>
        <div class="card-body">
            <form method="post" enctype="multipart/form-data">
                <input type="hidden" name="action" value="batch_exec">
                <div class="form-group">
                    <textarea name="sql_content" placeholder="在此粘贴 SQL 语句...&#10;&#10;支持多条语句，每条以分号 ; 结尾&#10;&#10;示例：&#10;INSERT INTO users (name, age) VALUES ('张三', 25);&#10;INSERT INTO users (name, age) VALUES ('李四', 30);&#10;UPDATE users SET age = 26 WHERE name = '张三';&#10;SELECT * FROM users WHERE age > 20;"><?= h($sqlInput) ?></textarea>
                </div>

                <div class="flex-gap mb-sm">
                    <div class="form-group" style="flex:1;margin-bottom:0">
                        <label>或上传 SQL 文件</label>
                        <input type="file" name="sql_file" accept=".sql,.txt">
                    </div>
                </div>

                <div class="check-group">
                    <input type="checkbox" name="skip_ddl" id="skip_ddl" value="1" <?= $skipDDL ? 'checked' : '' ?>>
                    <label for="skip_ddl">跳过 DDL 语句（CREATE / DROP / ALTER TABLE 等），只执行 DML（INSERT / UPDATE / DELETE / SELECT）</label>
                </div>

                <div class="flex-gap">
                    <button class="btn btn-primary" type="submit" <?= !$isConnected ? 'disabled' : '' ?>>⚡ 批量执行</button>
                    <button class="btn btn-outline" type="button" onclick="document.querySelector('textarea[name=sql_content]').value=''">清空</button>
                </div>
            </form>
        </div>
    </div>

    <!-- 错误提示 -->
    <?php if ($batchError): ?>
    <div class="flash flash-error"><?= h($batchError) ?></div>
    <?php endif; ?>

    <!-- 执行结果 -->
    <?php if ($batchResult): ?>
    <div class="card">
        <div class="card-header">
            <span>执行结果</span>
            <span style="font-weight:400;font-size:12px;color:#888"><?= h($batchResult['time']) ?></span>
        </div>
        <div class="card-body">
            <!-- 汇总条 -->
            <div class="summary-bar">
                <div class="summary-item">
                    <span style="color:#888">共 </span>
                    <strong><?= $batchResult['total'] ?></strong>
                    <span style="color:#888"> 条语句</span>
                </div>
                <div class="summary-item" style="color:#1a7d36">
                    成功 <strong><?= $batchResult['successCount'] ?></strong> 条
                </div>
                <div class="summary-item" style="color:#c92a2a">
                    失败 <strong><?= $batchResult['failCount'] ?></strong> 条
                </div>
                <?php if ($batchResult['skipCount'] > 0): ?>
                <div class="summary-item" style="color:#888">
                    跳过 <strong><?= $batchResult['skipCount'] ?></strong> 条
                </div>
                <?php endif; ?>
                <?php if ($batchResult['totalRowsAffected'] > 0): ?>
                <div class="summary-item" style="color:#1c7ed6">
                    影响 <strong><?= $batchResult['totalRowsAffected'] ?></strong> 行
                </div>
                <?php endif; ?>
            </div>

            <!-- 逐条结果 -->
            <table class="result-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>SQL 语句</th>
                        <th>结果</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($batchResult['results'] as $r): ?>
                    <?php
                        $rowClass = $r['type'] === 'error' ? 'result-error' : ($r['type'] === 'skip' ? 'result-skip' : 'result-success');
                        $icon = $r['type'] === 'error' ? '❌' : ($r['type'] === 'skip' ? '⏭' : '✅');
                    ?>
                    <tr>
                        <td class="<?= $rowClass ?>"><?= $r['no'] ?></td>
                        <td class="result-sql" title="<?= h($r['sql']) ?>"><?= h($r['sql']) ?></td>
                        <td class="result-msg">
                            <?php if (!empty($r['cols']) && !empty($r['rows'])): ?>
                                <details>
                                    <summary style="cursor:pointer;color:#1c7ed6;font-weight:500"><?= h($r['msg']) ?> ← 展开查看</summary>
                                    <div style="overflow-x:auto;margin-top:8px;max-height:300px;overflow-y:auto">
                                        <table style="font-size:11px">
                                            <thead><tr><?php foreach ($r['cols'] as $col): ?><th><?= h($col) ?></th><?php endforeach; ?></tr></thead>
                                            <tbody>
                                            <?php foreach ($r['rows'] as $row): ?>
                                            <tr><?php foreach ($r['cols'] as $col): ?><td><?= h(mb_strlen((string)($row[$col] ?? '')) > 120 ? mb_substr((string)($row[$col]), 0, 120) . '...' : ($row[$col] ?? '')) ?></td><?php endforeach; ?></tr>
                                            <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </details>
                            <?php else: ?>
                                <span class="<?= $rowClass ?>"><?= $icon ?> <?= h($r['msg']) ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <!-- 空状态 -->
    <?php if (!$batchResult && !$batchError): ?>
    <div class="empty">
        <p style="font-size:48px;margin-bottom:12px">📝</p>
        <p>在上方输入 SQL 语句或上传 .sql 文件</p>
        <p style="margin-top:8px;color:#bbb">多条语句请用分号 ; 分隔，支持 SELECT / INSERT / UPDATE / DELETE 等所有 SQL 语句</p>
    </div>
    <?php endif; ?>

</div>

</body>
</html>
