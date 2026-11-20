<?php
require_once __DIR__.'/config/config.php';

function page_title(string $p): string {
    return ['dashboard'=>'Dashboard','farmers'=>'Farmers','deliveries'=>'Deliveries','inventory'=>'Inventory',
    'buyers'=>'Buyers','orders'=>'Orders','reports'=>'Reports','users'=>'User Management','grains'=>'Grain Types',
    'login'=>'Login','register'=>'Buyer Registration'][$p] ?? 'SGC Cooperative';
}
$page = $_GET['page'] ?? (logged_in() ? 'dashboard' : 'login');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'login') {
            $st = db()->prepare("SELECT * FROM users WHERE username=? AND active=1 LIMIT 1");
            $st->execute([trim($_POST['username'] ?? '')]); $u=$st->fetch();
            if ($u && password_verify($_POST['password'] ?? '', $u['password_hash'])) {
                session_regenerate_id(true);
                $_SESSION['user']=['user_id'=>$u['user_id'],'name'=>$u['full_name'],'role'=>$u['role'],'buyer_id'=>$u['buyer_id']];
                redirect('dashboard');
            }
            flash('Invalid username or password.','error'); redirect('login');
        }
        if ($action === 'register_buyer') {
            $pdo=db(); $pdo->beginTransaction();
            $pdo->prepare("INSERT INTO buyers(business_name,contact_person,address,contact_number,email) VALUES(?,?,?,?,?)")
                ->execute([trim($_POST['business_name']),trim($_POST['contact_person']),trim($_POST['address']),trim($_POST['contact_number']),trim($_POST['email'])]);
            $bid=(int)$pdo->lastInsertId();
            $username=trim($_POST['username']);
            if (strlen($_POST['password']) < 8) throw new RuntimeException('Password must be at least 8 characters.');
            $pdo->prepare("INSERT INTO users(buyer_id,full_name,username,email,password_hash,role) VALUES(?,?,?,?,?,'Buyer')")
                ->execute([$bid,trim($_POST['contact_person']),$username,trim($_POST['email']),password_hash($_POST['password'],PASSWORD_DEFAULT)]);
            $pdo->commit(); flash('Buyer account created. You can now log in.'); redirect('login');
        }
        require_login();
        $pdo=db();
        if ($action==='save_farmer') {
            require_roles(['Manager','Clerk']);
            $id=(int)($_POST['farmer_id']??0);
            if ($id) $pdo->prepare("UPDATE farmers SET full_name=?,address=?,contact_number=? WHERE farmer_id=?")->execute([trim($_POST['full_name']),trim($_POST['address']),trim($_POST['contact_number']),$id]);
            else $pdo->prepare("INSERT INTO farmers(full_name,address,contact_number) VALUES(?,?,?)")->execute([trim($_POST['full_name']),trim($_POST['address']),trim($_POST['contact_number'])]);
            flash('Farmer record saved.'); redirect('farmers');
        }
        if ($action==='save_grain') {
            require_roles(['Manager']);
            $pdo->prepare("INSERT INTO grain_types(grain_name,description,unit) VALUES(?,?,?)")->execute([trim($_POST['grain_name']),trim($_POST['description']),trim($_POST['unit'])]);
            $gid=(int)$pdo->lastInsertId(); $pdo->prepare("INSERT INTO inventory(grain_id) VALUES(?)")->execute([$gid]);
            flash('Grain type added.'); redirect('grains');
        }
        if ($action==='save_buyer') {
            require_roles(['Manager','Clerk']);
            $pdo->prepare("INSERT INTO buyers(business_name,contact_person,address,contact_number,email) VALUES(?,?,?,?,?)")->execute([trim($_POST['business_name']),trim($_POST['contact_person']),trim($_POST['address']),trim($_POST['contact_number']),trim($_POST['email'])]);
            flash('Buyer record saved.'); redirect('buyers');
        }
        if ($action==='save_delivery') {
            require_roles(['Manager','Clerk']);
            $qty=(float)$_POST['quantity']; if($qty<=0) throw new RuntimeException('Quantity must be greater than zero.');
            $pdo->beginTransaction();
            $pdo->prepare("INSERT INTO deliveries(farmer_id,grain_id,recorded_by,quantity,status,notes,delivery_date) VALUES(?,?,?,?, 'Accepted',?,?)")
                ->execute([(int)$_POST['farmer_id'],(int)$_POST['grain_id'],current_user_id(),$qty,trim($_POST['notes']),$_POST['delivery_date'] ?: date('Y-m-d H:i:s')]);
            $did=(int)$pdo->lastInsertId();
            $pdo->prepare("UPDATE inventory SET on_hand_qty=on_hand_qty+? WHERE grain_id=?")->execute([$qty,(int)$_POST['grain_id']]);
            $pdo->prepare("INSERT INTO inventory_transactions(grain_id,reference_id,reference_type,transaction_type,quantity,recorded_by,remarks) VALUES(?,?,'Delivery','Delivery Accepted',?,?,?)")
                ->execute([(int)$_POST['grain_id'],$did,$qty,current_user_id(),'Farmer delivery']);
            $pdo->commit(); flash('Delivery recorded and inventory updated.'); redirect('deliveries');
        }
        if ($action==='create_order') {
            $buyerId = role()==='Buyer' ? (int)($_SESSION['user']['buyer_id']??0) : (int)$_POST['buyer_id'];
            if(!$buyerId) throw new RuntimeException('Select a valid buyer account.');
            $grainIds=$_POST['grain_id']??[]; $qtys=$_POST['quantity']??[];
            $items=[];
            foreach($grainIds as $i=>$gid){$q=(float)($qtys[$i]??0); if((int)$gid>0 && $q>0)$items[(int)$gid]=($items[(int)$gid]??0)+$q;}
            if(!$items) throw new RuntimeException('Add at least one grain item with a quantity.');
            $pdo->beginTransaction();
            $ref='SGC-'.date('ymd').'-'.strtoupper(bin2hex(random_bytes(3)));
            $source=role()==='Buyer'?'Online':'Clerk';
            $pdo->prepare("INSERT INTO orders(buyer_id,created_by,order_reference,order_source,status,notes) VALUES(?,?,?,?, 'Pending',?)")
                ->execute([$buyerId,role()==='Buyer'?null:current_user_id(),$ref,$source,trim($_POST['notes']??'')]);
            $oid=(int)$pdo->lastInsertId();
            foreach($items as $gid=>$q){
                $pdo->prepare("INSERT INTO order_items(order_id,grain_id,quantity) VALUES(?,?,?)")->execute([$oid,$gid,$q]);
            }
            $pdo->commit(); flash('Order submitted. Reference: '.$ref); redirect(role()==='Buyer'?'orders':'orders');
        }
        if ($action==='order_status') {
            require_roles(['Manager','Clerk']);
            $oid=(int)$_POST['order_id']; $new=$_POST['status'];
            $pdo->beginTransaction();
            $st=$pdo->prepare("SELECT * FROM orders WHERE order_id=? FOR UPDATE"); $st->execute([$oid]); $o=$st->fetch();
            if(!$o) throw new RuntimeException('Order not found.');
            if($o['status']==='Pending' && $new==='Approved'){
                $it=$pdo->prepare("SELECT * FROM order_items WHERE order_id=?");$it->execute([$oid]);
                foreach($it as $item){
                    $s=$pdo->prepare("SELECT on_hand_qty,reserved_qty FROM inventory WHERE grain_id=? FOR UPDATE");$s->execute([$item['grain_id']]);$inv=$s->fetch();
                    if(!$inv || ($inv['on_hand_qty']-$inv['reserved_qty']) < $item['quantity']) throw new RuntimeException('Insufficient available stock for one or more items.');
                    $pdo->prepare("UPDATE inventory SET reserved_qty=reserved_qty+? WHERE grain_id=?")->execute([$item['quantity'],$item['grain_id']]);
                    $pdo->prepare("INSERT INTO inventory_transactions(grain_id,reference_id,reference_type,transaction_type,quantity,recorded_by,remarks) VALUES(?,?,'Order','Stock Reserved',?,?,?)")
                        ->execute([$item['grain_id'],$oid,$item['quantity'],current_user_id(),'Approved order reservation']);
                }
            } elseif($o['status']==='Approved' && $new==='Released'){
                $it=$pdo->prepare("SELECT * FROM order_items WHERE order_id=?");$it->execute([$oid]);
                foreach($it as $item){
                    $pdo->prepare("UPDATE inventory SET reserved_qty=reserved_qty-?,on_hand_qty=on_hand_qty-? WHERE grain_id=?")->execute([$item['quantity'],$item['quantity'],$item['grain_id']]);
                    $pdo->prepare("INSERT INTO inventory_transactions(grain_id,reference_id,reference_type,transaction_type,quantity,recorded_by,remarks) VALUES(?,?,'Order','Order Released',?,?,?)")
                        ->execute([$item['grain_id'],$oid,$item['quantity'],current_user_id(),'Order released']);
                }
            } elseif($o['status']==='Approved' && $new==='Cancelled'){
                $it=$pdo->prepare("SELECT * FROM order_items WHERE order_id=?");$it->execute([$oid]);
                foreach($it as $item) $pdo->prepare("UPDATE inventory SET reserved_qty=reserved_qty-? WHERE grain_id=?")->execute([$item['quantity'],$item['grain_id']]);
            } elseif($o['status']!=='Pending' || !in_array($new,['Rejected','Cancelled'],true)) {
                throw new RuntimeException('Invalid order status transition.');
            }
            $pdo->prepare("UPDATE orders SET status=? WHERE order_id=?")->execute([$new,$oid]);
            $pdo->commit(); flash('Order status updated.'); redirect('orders');
        }
        if ($action==='toggle_user') {
            require_roles(['Manager']);
            $uid=(int)$_POST['user_id']; if($uid===current_user_id()) throw new RuntimeException('You cannot deactivate your own account.');
            $pdo->prepare("UPDATE users SET active=1-active WHERE user_id=?")->execute([$uid]); flash('User access updated.'); redirect('users');
        }
        if ($action==='change_password') {
            $old=$_POST['old_password'];$new=$_POST['new_password'];
            $s=$pdo->prepare("SELECT password_hash FROM users WHERE user_id=?");$s->execute([current_user_id()]);
            if(!password_verify($old,$s->fetchColumn())) throw new RuntimeException('Current password is incorrect.');
            if(strlen($new)<8) throw new RuntimeException('New password must be at least 8 characters.');
            $pdo->prepare("UPDATE users SET password_hash=? WHERE user_id=?")->execute([password_hash($new,PASSWORD_DEFAULT),current_user_id()]);
            flash('Password changed successfully.'); redirect('account');
        }
    } catch (Throwable $ex) {
        if(isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
        flash($ex instanceof PDOException ? 'Unable to save. Check for duplicate values and required fields.' : $ex->getMessage(),'error');
        redirect($_GET['page'] ?? 'dashboard');
    }
}

if ($page==='logout') { session_destroy(); header('Location: index.php?page=login'); exit; }
if (!in_array($page,['login','register'],true)) require_login();
if ($page==='login' && logged_in()) redirect('dashboard');

$allowed=['dashboard','farmers','deliveries','inventory','buyers','orders','reports','users','grains','account','login','register'];
if(!in_array($page,$allowed,true)) $page='dashboard';
$pdo=null; $flash=$_SESSION['flash']??null; unset($_SESSION['flash']);
function rows(string $sql,array $args=[]): array { $s=db()->prepare($sql);$s->execute($args);return $s->fetchAll(); }
function scalar(string $sql,array $args=[]): string { $s=db()->prepare($sql);$s->execute($args);return (string)$s->fetchColumn(); }
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?=e(page_title($page))?> | SGC Cooperative</title><link rel="stylesheet" href="assets/style.css"></head>
<body class="<?=logged_in()?'app-body':'auth-body'?>">
<?php if(logged_in()): ?>
<aside class="sidebar"><div class="brand"><div class="brand-icon">SG</div><div><b>SGC Cooperative</b><small>Management System</small></div></div>
<div class="nav-label">WORKSPACE</div>
<a class="<?= $page==='dashboard'?'active':'' ?>" href="index.php?page=dashboard">▦ Dashboard</a>
<?php if(in_array(role(),['Manager','Clerk'])): ?>
<div class="nav-label">OPERATIONS</div>
<a class="<?= $page==='farmers'?'active':'' ?>" href="index.php?page=farmers">♧ Farmers</a>
<a class="<?= $page==='deliveries'?'active':'' ?>" href="index.php?page=deliveries">⇩ Deliveries</a>
<a class="<?= $page==='inventory'?'active':'' ?>" href="index.php?page=inventory">▤ Inventory</a>
<a class="<?= $page==='buyers'?'active':'' ?>" href="index.php?page=buyers">♙ Buyers</a>
<a class="<?= $page==='orders'?'active':'' ?>" href="index.php?page=orders">▣ Orders</a>
<?php else: ?><a class="<?= $page==='inventory'?'active':'' ?>" href="index.php?page=inventory">▤ Available Grain</a><a class="<?= $page==='orders'?'active':'' ?>" href="index.php?page=orders">▣ My Orders</a><?php endif; ?>
<?php if(role()==='Manager'): ?><div class="nav-label">ADMINISTRATION</div><a href="index.php?page=grains" class="<?= $page==='grains'?'active':'' ?>">◈ Grain Types</a><a href="index.php?page=reports" class="<?= $page==='reports'?'active':'' ?>">▥ Reports</a><a href="index.php?page=users" class="<?= $page==='users'?'active':'' ?>">♙ User Management</a><?php endif; ?>
<div class="nav-bottom"><a href="index.php?page=account">⚙ My Account</a><a href="index.php?page=logout">↪ Sign out</a></div></aside>
<main class="main"><header class="topbar"><div><span class="eyebrow">SGC COOPERATIVE</span><h1><?=e(page_title($page))?></h1></div><div class="user-chip"><span class="avatar"><?=e(strtoupper(substr($_SESSION['user']['name'],0,1)))?></span><div><b><?=e($_SESSION['user']['name'])?></b><small><?=e(role())?></small></div></div></header>
<?php else: ?><main class="auth-main"><?php endif; ?>
<?php if($flash): ?><div class="alert <?=e($flash[1])?>"><?=e($flash[0])?></div><?php endif; ?>

<?php if($page==='login'): ?>
<section class="auth-card"><div class="brand centered"><div class="brand-icon">SG</div><div><b>SGC Cooperative</b><small>Grain Management System</small></div></div><h2>Welcome back</h2><p class="muted">Sign in to access your workspace.</p>
<form method="post"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="login"><label>Username</label><input name="username" required autofocus><label>Password</label><input type="password" name="password" required><button class="btn primary full">Sign in</button></form>
<p class="center muted">Buyer new to SGC? <a href="index.php?page=register">Create buyer account</a></p>
<div class="demo-note"><b>Initial demo accounts</b><br>Manager: manager / password123<br>Clerk: clerk / password123</div></section>
<?php elseif($page==='register'): ?>
<section class="auth-card wide"><div class="brand centered"><div class="brand-icon">SG</div><div><b>SGC Cooperative</b><small>Buyer Registration</small></div></div><h2>Create buyer account</h2><form method="post"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="register_buyer"><div class="form-grid"><div><label>Business name</label><input name="business_name" required></div><div><label>Contact person</label><input name="contact_person" required></div><div><label>Username</label><input name="username" required></div><div><label>Email</label><input type="email" name="email"></div><div><label>Contact number</label><input name="contact_number"></div><div><label>Address</label><input name="address"></div><div class="span-2"><label>Password (at least 8 characters)</label><input type="password" name="password" minlength="8" required></div></div><button class="btn primary full">Register account</button></form><p class="center"><a href="index.php?page=login">Back to login</a></p></section>
<?php else: ?>

<?php if($page==='dashboard'):
$stock=scalar("SELECT COALESCE(SUM(on_hand_qty-reserved_qty),0) FROM inventory");
$deliveries=scalar("SELECT COUNT(*) FROM deliveries WHERE DATE(delivery_date)=CURDATE()");
$pending=scalar("SELECT COUNT(*) FROM orders WHERE status='Pending'");
$orderCount=scalar("SELECT COUNT(*) FROM orders");
?>
<div class="welcome"><div><h2>Good day, <?=e(explode(' ',$_SESSION['user']['name'])[0])?>!</h2><p>Here is the current overview of cooperative operations.</p></div><span class="date-pill"><?=date('F j, Y')?></span></div>
<div class="stats"><div class="stat"><span>AVAILABLE GRAIN</span><strong><?=money_qty($stock)?> <small>kg*</small></strong><em>On-hand less reserved stock</em></div><div class="stat"><span>TODAY'S DELIVERIES</span><strong><?=e($deliveries)?></strong><em>Recorded delivery entries</em></div><div class="stat"><span>PENDING ORDERS</span><strong><?=e($pending)?></strong><em>Awaiting review</em></div><div class="stat"><span>TOTAL ORDERS</span><strong><?=e($orderCount)?></strong><em>All recorded orders</em></div></div>
<div class="content-grid"><section class="panel"><div class="panel-head"><div><h3>Inventory overview</h3><p>Current stock by grain type</p></div><a href="index.php?page=inventory">View all →</a></div><div class="table-wrap"><table><thead><tr><th>Grain</th><th>On hand</th><th>Reserved</th><th>Available</th></tr></thead><tbody><?php foreach(rows("SELECT g.grain_name,g.unit,i.* FROM grain_types g JOIN inventory i ON i.grain_id=g.grain_id ORDER BY g.grain_name") as $r): ?><tr><td><b><?=e($r['grain_name'])?></b></td><td><?=money_qty($r['on_hand_qty'])?> <?=e($r['unit'])?></td><td><?=money_qty($r['reserved_qty'])?></td><td><b><?=money_qty($r['on_hand_qty']-$r['reserved_qty'])?></b></td></tr><?php endforeach; ?></tbody></table></div></section>
<section class="panel"><div class="panel-head"><div><h3>Recent orders</h3><p>Latest buyer requests</p></div><a href="index.php?page=orders">View all →</a></div><?php foreach(rows("SELECT o.*,b.business_name FROM orders o JOIN buyers b ON b.buyer_id=o.buyer_id ORDER BY o.order_date DESC LIMIT 5") as $r): ?><div class="list-row"><div><b><?=e($r['business_name'])?></b><small><?=e($r['order_reference'])?></small></div><span class="status <?=strtolower($r['status'])?>"><?=e($r['status'])?></span></div><?php endforeach; ?></section></div>
<?php elseif($page==='farmers'):
require_roles(['Manager','Clerk']); ?>
<div class="page-actions"><p class="muted">Maintain farmer information and retrieve delivery records.</p></div><section class="panel"><h3>Add farmer</h3><form method="post" class="form-grid"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="save_farmer"><div><label>Full name</label><input name="full_name" required></div><div><label>Contact number</label><input name="contact_number"></div><div class="span-2"><label>Address</label><input name="address"></div><div class="span-2"><button class="btn primary">Save farmer</button></div></form></section>
<section class="panel"><div class="panel-head"><h3>Farmer directory</h3><input class="search" placeholder="Search farmer..." oninput="filterTable(this,'farmers-table')"></div><div class="table-wrap"><table id="farmers-table"><thead><tr><th>ID</th><th>Farmer</th><th>Contact</th><th>Address</th><th>Deliveries</th></tr></thead><tbody><?php foreach(rows("SELECT f.*,COUNT(d.delivery_id) delivery_count FROM farmers f LEFT JOIN deliveries d ON d.farmer_id=f.farmer_id GROUP BY f.farmer_id ORDER BY f.full_name") as $r): ?><tr><td>#<?=e($r['farmer_id'])?></td><td><b><?=e($r['full_name'])?></b></td><td><?=e($r['contact_number'])?></td><td><?=e($r['address'])?></td><td><a href="index.php?page=deliveries&farmer_id=<?=$r['farmer_id']?>"><?=e($r['delivery_count'])?> records</a></td></tr><?php endforeach; ?></tbody></table></div></section>
<?php elseif($page==='deliveries'):
require_roles(['Manager','Clerk']); ?>
<section class="panel"><h3>Record farmer delivery</h3><form method="post" class="form-grid"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="save_delivery"><div><label>Farmer</label><select name="farmer_id" required><option value="">Select farmer</option><?php foreach(rows("SELECT * FROM farmers ORDER BY full_name") as $f): ?><option value="<?=$f['farmer_id']?>"><?=e($f['full_name'])?></option><?php endforeach; ?></select></div><div><label>Grain type</label><select name="grain_id" required><?php foreach(rows("SELECT * FROM grain_types WHERE active=1 ORDER BY grain_name") as $g): ?><option value="<?=$g['grain_id']?>"><?=e($g['grain_name'])?></option><?php endforeach; ?></select></div><div><label>Quantity (kg)</label><input type="number" step="0.01" min="0.01" name="quantity" required></div><div><label>Delivery date and time</label><input type="datetime-local" name="delivery_date" value="<?=date('Y-m-d\TH:i')?>"></div><div class="span-2"><label>Notes</label><textarea name="notes" rows="2"></textarea></div><div class="span-2"><button class="btn primary">Save delivery & update stock</button></div></form></section>
<section class="panel"><div class="panel-head"><h3>Delivery history</h3><input class="search" placeholder="Search records..." oninput="filterTable(this,'delivery-table')"></div><div class="table-wrap"><table id="delivery-table"><thead><tr><th>Delivery #</th><th>Date</th><th>Farmer</th><th>Grain</th><th>Quantity</th><th>Status</th></tr></thead><tbody><?php $sql="SELECT d.*,f.full_name,g.grain_name,g.unit FROM deliveries d JOIN farmers f ON f.farmer_id=d.farmer_id JOIN grain_types g ON g.grain_id=d.grain_id";$args=[];if(isset($_GET['farmer_id'])){$sql.=" WHERE d.farmer_id=?";$args[]=(int)$_GET['farmer_id'];}$sql.=" ORDER BY d.delivery_date DESC";foreach(rows($sql,$args) as $r): ?><tr><td>#<?=e($r['delivery_id'])?></td><td><?=e($r['delivery_date'])?></td><td><?=e($r['full_name'])?></td><td><?=e($r['grain_name'])?></td><td><?=money_qty($r['quantity'])?> <?=e($r['unit'])?></td><td><span class="status accepted"><?=e($r['status'])?></span></td></tr><?php endforeach; ?></tbody></table></div></section>
<?php elseif($page==='inventory'): ?>
<section class="panel"><div class="panel-head"><div><h3><?=role()==='Buyer'?'Available grain':'Inventory position'?></h3><p>Quantities are calculated from recorded transactions.</p></div></div><div class="table-wrap"><table><thead><tr><th>Grain type</th><th>On-hand</th><th>Reserved</th><th>Available</th><th>Unit</th></tr></thead><tbody><?php foreach(rows("SELECT g.grain_name,g.unit,i.* FROM grain_types g JOIN inventory i ON i.grain_id=g.grain_id WHERE g.active=1 ORDER BY g.grain_name") as $r): ?><tr><td><b><?=e($r['grain_name'])?></b></td><td><?=money_qty($r['on_hand_qty'])?></td><td><?=money_qty($r['reserved_qty'])?></td><td><b><?=money_qty($r['on_hand_qty']-$r['reserved_qty'])?></b></td><td><?=e($r['unit'])?></td></tr><?php endforeach; ?></tbody></table></div></section>
<?php elseif($page==='buyers'):
require_roles(['Manager','Clerk']); ?>
<section class="panel"><h3>Register buyer record</h3><form method="post" class="form-grid"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="save_buyer"><div><label>Business name</label><input name="business_name" required></div><div><label>Contact person</label><input name="contact_person" required></div><div><label>Contact number</label><input name="contact_number"></div><div><label>Email</label><input type="email" name="email"></div><div class="span-2"><label>Address</label><input name="address"></div><div class="span-2"><button class="btn primary">Save buyer</button></div></form></section>
<section class="panel"><h3>Buyer directory</h3><div class="table-wrap"><table><thead><tr><th>Business</th><th>Contact person</th><th>Phone</th><th>Email</th></tr></thead><tbody><?php foreach(rows("SELECT * FROM buyers ORDER BY business_name") as $b): ?><tr><td><b><?=e($b['business_name'])?></b></td><td><?=e($b['contact_person'])?></td><td><?=e($b['contact_number'])?></td><td><?=e($b['email'])?></td></tr><?php endforeach; ?></tbody></table></div></section>
<?php elseif($page==='orders'): ?>
<?php if(role()!=='Buyer'): ?><section class="panel"><h3>Encode buyer order</h3><form method="post" class="form-grid" id="order-form"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="create_order"><div class="span-2"><label>Buyer</label><select name="buyer_id" required><option value="">Select buyer</option><?php foreach(rows("SELECT * FROM buyers ORDER BY business_name") as $b): ?><option value="<?=$b['buyer_id']?>"><?=e($b['business_name'])?></option><?php endforeach; ?></select></div><?php for($i=0;$i<3;$i++): ?><div><label>Grain item</label><select name="grain_id[]"><option value="">Select grain</option><?php foreach(rows("SELECT * FROM grain_types WHERE active=1") as $g): ?><option value="<?=$g['grain_id']?>"><?=e($g['grain_name'])?></option><?php endforeach; ?></select></div><div><label>Quantity (kg)</label><input type="number" name="quantity[]" min="0" step="0.01"></div><?php endfor; ?><div class="span-2"><label>Notes</label><textarea name="notes" rows="2"></textarea></div><div class="span-2"><button class="btn primary">Submit order for review</button></div></form></section>
<?php else: ?><section class="panel"><h3>Place a grain order</h3><form method="post" class="form-grid"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="create_order"><?php for($i=0;$i<3;$i++): ?><div><label>Grain type</label><select name="grain_id[]"><option value="">Select grain</option><?php foreach(rows("SELECT g.*,i.on_hand_qty,i.reserved_qty FROM grain_types g JOIN inventory i ON i.grain_id=g.grain_id WHERE g.active=1") as $g): ?><option value="<?=$g['grain_id']?>"><?=e($g['grain_name'])?> — <?=money_qty($g['on_hand_qty']-$g['reserved_qty'])?> kg available</option><?php endforeach; ?></select></div><div><label>Quantity (kg)</label><input type="number" name="quantity[]" min="0" step="0.01"></div><?php endfor; ?><div class="span-2"><label>Notes / requested delivery details</label><textarea name="notes" rows="2"></textarea></div><div class="span-2"><button class="btn primary">Submit order</button></div></form></section><?php endif; ?>
<section class="panel"><div class="panel-head"><h3><?=role()==='Buyer'?'My orders':'All buyer orders'?></h3><input class="search" placeholder="Search order..." oninput="filterTable(this,'orders-table')"></div><div class="table-wrap"><table id="orders-table"><thead><tr><th>Reference</th><th>Buyer</th><th>Date</th><th>Items</th><th>Status</th><th>Action</th></tr></thead><tbody><?php $sql="SELECT o.*,b.business_name FROM orders o JOIN buyers b ON b.buyer_id=o.buyer_id";$args=[];if(role()==='Buyer'){$sql.=" WHERE o.buyer_id=?";$args[]=(int)$_SESSION['user']['buyer_id'];}$sql.=" ORDER BY o.order_date DESC";foreach(rows($sql,$args) as $o):$items=rows("SELECT oi.quantity,g.grain_name,g.unit FROM order_items oi JOIN grain_types g ON g.grain_id=oi.grain_id WHERE oi.order_id=?",[$o['order_id']]);?><tr><td><b><?=e($o['order_reference'])?></b></td><td><?=e($o['business_name'])?></td><td><?=e($o['order_date'])?></td><td><?php foreach($items as $it): ?><?=e($it['grain_name'])?>: <?=money_qty($it['quantity'])?> <?=e($it['unit'])?><br><?php endforeach; ?></td><td><span class="status <?=strtolower($o['status'])?>"><?=e($o['status'])?></span></td><td><a class="btn small" target="_blank" href="print_order.php?id=<?=$o['order_id']?>">Print slip</a><?php if(role()!=='Buyer' && $o['status']==='Pending'): ?><form method="post" class="inline-form"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="order_status"><input type="hidden" name="order_id" value="<?=$o['order_id']?>"><button name="status" value="Approved" class="btn small primary">Approve</button><button name="status" value="Rejected" class="btn small danger">Reject</button></form><?php elseif(role()!=='Buyer' && $o['status']==='Approved'): ?><form method="post" class="inline-form"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="order_status"><input type="hidden" name="order_id" value="<?=$o['order_id']?>"><button name="status" value="Released" class="btn small primary">Mark released</button><button name="status" value="Cancelled" class="btn small danger">Cancel</button></form><?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div></section>
<?php elseif($page==='reports'):
require_roles(['Manager']);$from=$_GET['from']??date('Y-m-01');$to=$_GET['to']??date('Y-m-t'); ?>
<section class="panel"><div class="panel-head"><div><h3>Operational reports</h3><p>Choose a date range to review cooperative transactions.</p></div><button class="btn" onclick="window.print()">Print report</button></div><form method="get" class="form-inline"><input type="hidden" name="page" value="reports"><label>From</label><input type="date" name="from" value="<?=e($from)?>"><label>To</label><input type="date" name="to" value="<?=e($to)?>"><button class="btn primary">Generate</button></form></section>
<div class="stats"><div class="stat"><span>DELIVERY ENTRIES</span><strong><?=e(scalar("SELECT COUNT(*) FROM deliveries WHERE DATE(delivery_date) BETWEEN ? AND ?",[$from,$to]))?></strong></div><div class="stat"><span>DELIVERED QUANTITY</span><strong><?=money_qty(scalar("SELECT COALESCE(SUM(quantity),0) FROM deliveries WHERE status='Accepted' AND DATE(delivery_date) BETWEEN ? AND ?",[$from,$to]))?></strong><em>kg</em></div><div class="stat"><span>ORDERS PLACED</span><strong><?=e(scalar("SELECT COUNT(*) FROM orders WHERE DATE(order_date) BETWEEN ? AND ?",[$from,$to]))?></strong></div><div class="stat"><span>APPROVED / RELEASED</span><strong><?=e(scalar("SELECT COUNT(*) FROM orders WHERE status IN ('Approved','Released') AND DATE(order_date) BETWEEN ? AND ?",[$from,$to]))?></strong></div></div>
<section class="panel"><h3>Delivery summary by grain</h3><div class="table-wrap"><table><thead><tr><th>Grain</th><th>Accepted deliveries</th><th>Quantity (kg)</th></tr></thead><tbody><?php foreach(rows("SELECT g.grain_name,COUNT(d.delivery_id) entries,COALESCE(SUM(d.quantity),0) qty FROM grain_types g LEFT JOIN deliveries d ON d.grain_id=g.grain_id AND d.status='Accepted' AND DATE(d.delivery_date) BETWEEN ? AND ? GROUP BY g.grain_id",[$from,$to]) as $r): ?><tr><td><?=e($r['grain_name'])?></td><td><?=e($r['entries'])?></td><td><?=money_qty($r['qty'])?></td></tr><?php endforeach; ?></tbody></table></div></section>
<?php elseif($page==='grains'):
require_roles(['Manager']); ?>
<section class="panel"><h3>Add grain type</h3><form method="post" class="form-grid"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="save_grain"><div><label>Grain name</label><input name="grain_name" required></div><div><label>Unit</label><select name="unit"><option>kg</option><option>ton</option><option>sack</option></select></div><div class="span-2"><label>Description</label><input name="description"></div><div class="span-2"><button class="btn primary">Add grain type</button></div></form></section><section class="panel"><h3>Grain catalog</h3><div class="table-wrap"><table><thead><tr><th>Grain</th><th>Description</th><th>Unit</th></tr></thead><tbody><?php foreach(rows("SELECT * FROM grain_types ORDER BY grain_name") as $g): ?><tr><td><?=e($g['grain_name'])?></td><td><?=e($g['description'])?></td><td><?=e($g['unit'])?></td></tr><?php endforeach; ?></tbody></table></div></section>
<?php elseif($page==='users'):
require_roles(['Manager']); ?>
<section class="panel"><h3>System users</h3><p class="muted">Buyer accounts are created through public registration. Manager and clerk accounts should be provisioned by the system administrator.</p><div class="table-wrap"><table><thead><tr><th>Name</th><th>Username</th><th>Role</th><th>Access</th><th>Action</th></tr></thead><tbody><?php foreach(rows("SELECT * FROM users ORDER BY role,full_name") as $u): ?><tr><td><?=e($u['full_name'])?></td><td><?=e($u['username'])?></td><td><?=e($u['role'])?></td><td><?= $u['active']?'Active':'Inactive' ?></td><td><?php if($u['user_id']!==current_user_id()): ?><form method="post"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="toggle_user"><input type="hidden" name="user_id" value="<?=$u['user_id']?>"><button class="btn small"><?= $u['active']?'Deactivate':'Activate' ?></button></form><?php else: ?>Current account<?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div></section>
<?php elseif($page==='account'): ?>
<section class="panel narrow"><h3>Change password</h3><form method="post"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="change_password"><label>Current password</label><input type="password" name="old_password" required><label>New password</label><input type="password" name="new_password" minlength="8" required><button class="btn primary">Update password</button></form></section>
<?php endif; ?>
<?php endif; ?>
<footer>SGC Cooperative Management System <span>• Local development build</span></footer></main>
<script src="assets/app.js"></script></body></html>
