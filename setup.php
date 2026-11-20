<?php
require_once __DIR__.'/config/config.php';
try {
    $pdo = db();
    $count = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
    if ($count === 0) {
        $hash = password_hash('password123', PASSWORD_DEFAULT);
        $pdo->prepare("INSERT INTO users(full_name,username,email,password_hash,role) VALUES
          ('SGC Manager','manager','manager@sgc.local',?,'Manager'),
          ('Records Clerk','clerk','clerk@sgc.local',?,'Clerk')")->execute([$hash,$hash]);
    }
    $grains = ['Rice','Corn','Palay'];
    $stmt = $pdo->prepare("INSERT IGNORE INTO grain_types(grain_name,description,unit) VALUES(?,?,'kg')");
    foreach ($grains as $g) $stmt->execute([$g, 'Sample grain type']);
    foreach ($pdo->query("SELECT grain_id FROM grain_types") as $g) {
        $pdo->prepare("INSERT IGNORE INTO inventory(grain_id,on_hand_qty,reserved_qty) VALUES(?,0,0)")->execute([$g['grain_id']]);
    }
    echo '<h2>SGC setup complete.</h2><p>Manager: <b>manager</b> / <b>password123</b></p><p>Clerk: <b>clerk</b> / <b>password123</b></p><p><a href="index.php">Open the system</a></p><p>For security, delete setup.php after setup and change demo passwords.</p>';
} catch (Throwable $e) { echo 'Setup error: '.htmlspecialchars($e->getMessage()); }
