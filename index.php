<?php
require __DIR__.'/lib.php';
require __DIR__.'/reports.php';
try { $db=db(); start_app_session($db); } catch (Throwable $ex) { http_response_code(500); exit('اتصال به پایگاه داده برقرار نشد. تنظیمات config.php و فایل schema.sql را بررسی کنید.'); }
$page=$_GET['page']??'dashboard'; $error='';
// Always re-check the account, so deleted, changed and reset users cannot retain old sessions.
if (isset($_SESSION['user'])) {
 $s=$db->prepare('SELECT id,username,role,is_deleted,must_change_password,session_version FROM users WHERE id=?');$s->execute([$_SESSION['user']['id']]);$account=$s->fetch();
 if (!$account || $account['is_deleted'] || (int)$account['session_version'] !== (int)($_SESSION['user']['session_version']??-1)) { unset($_SESSION['user']); }
 else { $_SESSION['user']['username']=$account['username'];$_SESSION['user']['role']=$account['role']; }
}
if (isset($_SESSION['user']) && !empty($account['must_change_password'])) {
 $action=$_POST['action']??'';
 if ($_SERVER['REQUEST_METHOD']==='POST' && !in_array($action,['change_password','logout'],true)) redirect('password');
 if ($_SERVER['REQUEST_METHOD']!=='POST' && $page!=='password') redirect('password');
}

$hasUsers=(bool)$db->query('SELECT COUNT(*) FROM users')->fetchColumn();
if (!$hasUsers) $page='setup';
if ($_SERVER['REQUEST_METHOD']==='POST') {
 try {
  check_csrf(); $action=$_POST['action']??'';
  if($action==='setup' && !$hasUsers){$username=trim($_POST['username']??'');$password=$_POST['password']??'';if(!$username||strlen($password)<8)throw new Exception('نام کاربری و رمز عبور حداقل ۸ کاراکتری لازم است.');$s=$db->prepare("INSERT INTO users(username,password,role) VALUES(?,?,'admin')");$s->execute([$username,password_hash($password,PASSWORD_DEFAULT)]);flash('مدیر ساخته شد. وارد شوید.');redirect('login');}
  if($action==='login'){$s=$db->prepare('SELECT * FROM users WHERE username=?');$s->execute([trim($_POST['username']??'')]);$u=$s->fetch();if(!$u||$u['is_deleted']||!password_verify($_POST['password']??'',$u['password']))throw new Exception('نام کاربری یا رمز عبور نادرست است.');session_regenerate_id(true);$_SESSION['user']=['id'=>$u['id'],'username'=>$u['username'],'role'=>$u['role'],'session_version'=>(int)$u['session_version']];if(in_array($u['role'],['user','operator'],true))remember_session();redirect('dashboard');}
  if($action==='logout'){$_SESSION=[];session_destroy();$params=session_get_cookie_params();setcookie(session_name(),'', ['expires'=>time()-3600,'path'=>$params['path'],'domain'=>$params['domain'],'secure'=>$params['secure'],'httponly'=>true,'samesite'=>'Lax']);redirect('login');}
  if($action==='change_password'){
   role('admin','user','operator');$password=$_POST['new_password']??'';$confirm=$_POST['confirm_password']??'';
   if(strlen($password)<8 || $password!==$confirm || $password===$_SESSION['user']['username'])throw new Exception('رمز جدید باید حداقل ۸ کاراکتر، متفاوت از نام کاربری و مطابق تکرار رمز باشد.');
   $s=$db->prepare('UPDATE users SET password=?,must_change_password=0,session_version=session_version+1 WHERE id=? AND is_deleted=0');$s->execute([password_hash($password,PASSWORD_DEFAULT),$_SESSION['user']['id']]);
   $_SESSION['user']['session_version']++;session_regenerate_id(true);if(in_array($_SESSION['user']['role'],['user','operator'],true))remember_session();flash('رمز عبور تغییر کرد.');redirect('dashboard');
  }
  if(in_array($action,['edit_user','delete_user','reset_user'],true)){
   role('admin');$uid=(int)($_POST['id']??0);if($uid===(int)$_SESSION['user']['id'])throw new Exception('برای حفظ دسترسی، تغییر یا حذف حساب مدیر فعلی مجاز نیست.');
   $s=$db->prepare('SELECT id,username,role FROM users WHERE id=? AND is_deleted=0');$s->execute([$uid]);$target=$s->fetch();if(!$target)throw new Exception('کاربر پیدا نشد.');
   if($action==='delete_user'){$db->prepare('UPDATE users SET is_deleted=1,session_version=session_version+1 WHERE id=?')->execute([$uid]);flash('کاربر حذف شد؛ سوابق او حفظ می‌شود.');}
   elseif($action==='reset_user'){$db->prepare('UPDATE users SET password=?,must_change_password=1,session_version=session_version+1 WHERE id=?')->execute([password_hash($target['username'],PASSWORD_DEFAULT),$uid]);flash('رمز عبور به نام کاربری تغییر کرد و تغییر رمز در ورود بعدی الزامی است.');}
   else {$name=trim($_POST['username']??'');$r=$_POST['role']??'';if(!$name||strlen($name)>100||!in_array($r,['admin','user','operator'],true))throw new Exception('نام کاربری یا نقش معتبر نیست.');$db->prepare('UPDATE users SET username=?,role=?,password=CASE WHEN must_change_password=1 THEN ? ELSE password END,session_version=session_version+1 WHERE id=?')->execute([$name,$r,password_hash($name,PASSWORD_DEFAULT),$uid]);flash('اطلاعات کاربر ویرایش شد.');}
   redirect('users');
  }
  if($action==='save_card'){
   role('admin','user'); $id=(int)($_POST['id']??0);$old=null;
   if($id){$s=$db->prepare('SELECT * FROM cards WHERE id=?');$s->execute([$id]);$old=$s->fetch();if(!$old)throw new Exception('کارت پیدا نشد.');if($_SESSION['user']['role']==='user' && ((int)$old['created_by']!==(int)$_SESSION['user']['id'] || time()-strtotime($old['created_at'])>1800))throw new Exception('مهلت ویرایش این کارت پایان یافته است.');}
   $first=trim($_POST['first_name']??'');$last=trim($_POST['last_name']??'');$national=digits($_POST['national_code']??'');$areaId=(int)($_POST['access_area_id']??0);$s=$db->prepare('SELECT name FROM access_areas WHERE id=?');$s->execute([$areaId]);$area=$s->fetchColumn();$contractor=(int)($_POST['contractor_id']??0);$start=parse_jalali($_POST['start_date']??'');$end=parse_jalali($_POST['end_date']??'');
   if(!$first||!$last||!$area||!preg_match('/^\d{10}$/',$national)||!$start||!$end||$start>$end)throw new Exception('اطلاعات را کامل کنید؛ کد ملی باید ۱۰ رقم و تاریخ‌ها معتبر باشند.');$s=$db->prepare('SELECT id FROM contractors WHERE id=?');$s->execute([$contractor]);if(!$s->fetch())throw new Exception('پیمانکار معتبر انتخاب کنید.');
   $s=$db->prepare('SELECT id FROM cards WHERE national_code=? AND id<>?');$s->execute([$national,$id]);if($s->fetch())throw new Exception('این کد ملی تکراری است و قبلاً برای آن کارت صادر شده است.');
   $photo=photo_upload($old['photo']??'');
   if($id){$s=$db->prepare('UPDATE cards SET first_name=?,last_name=?,national_code=?,photo=?,contractor_id=?,start_date=?,end_date=?,access_area=? WHERE id=?');$s->execute([$first,$last,$national,$photo,$contractor,$start,$end,$area,$id]);}else{$barcode=(string)random_int(100000000000,999999999999);$s=$db->prepare('INSERT INTO cards (barcode,first_name,last_name,national_code,photo,contractor_id,start_date,end_date,access_area,created_by) VALUES (?,?,?,?,?,?,?,?,?,?)');$s->execute([$barcode,$first,$last,$national,$photo,$contractor,$start,$end,$area,$_SESSION['user']['id']]);$id=(int)$db->lastInsertId();} flash('اطلاعات کارت با موفقیت ذخیره شد.');redirect('card&id='.$id);
  }
  if($action==='toggle'){role('admin');$id=(int)$_POST['id'];$s=$db->prepare('UPDATE cards SET is_active=?, disabled_reason=? WHERE id=?');$active=(int)($_POST['active']??0)===1;$s->execute([$active?1:0,$active?null:trim($_POST['reason']??''),$id]);flash($active?'کارت فعال شد.':'کارت غیرفعال شد.');redirect('card&id='.$id);}
  if($action==='contractor'){role('admin');$name=trim($_POST['name']??'');if(!$name)throw new Exception('نام پیمانکار را وارد کنید.');$db->prepare('INSERT INTO contractors(name) VALUES(?)')->execute([$name]);flash('پیمانکار اضافه شد.');redirect('contractors');}
  if($action==='area'){role('admin');$name=trim($_POST['name']??'');if(!$name)throw new Exception('نام محدوده تردد را وارد کنید.');$db->prepare('INSERT INTO access_areas(name) VALUES(?)')->execute([$name]);flash('محدوده تردد اضافه شد.');redirect('areas');}
  if($action==='add_user'){role('admin');$name=trim($_POST['username']??'');$pass=$_POST['password']??'';$r=$_POST['role']??'';if(!$name||strlen($pass)<8||!in_array($r,['admin','user','operator'],true))throw new Exception('اطلاعات کاربر معتبر نیست؛ رمز حداقل ۸ کاراکتر باشد.');$db->prepare('INSERT INTO users(username,password,role) VALUES(?,?,?)')->execute([$name,password_hash($pass,PASSWORD_DEFAULT),$r]);flash('کاربر اضافه شد.');redirect('users');}
  if($action==='scan'){
   role('operator');$code=strtoupper(digits($_POST['barcode']??''));
   $db->beginTransaction();
   $s=$db->prepare('SELECT c.*,co.name contractor FROM cards c JOIN contractors co ON co.id=c.contractor_id WHERE c.barcode=? FOR UPDATE');$s->execute([$code]);$scanned=$s->fetch();
   if(!$scanned)throw new Exception('بارکد معتبر نیست، مجدد تلاش کنید.');
   [$label,$status,$message]=card_status($scanned);
   if($status==='success'){
    $s=$db->prepare('SELECT id FROM access_logs WHERE card_id=? AND created_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE) LIMIT 1');$s->execute([$scanned['id']]);
    if($s->fetch()){$status='warning';$label='تردد تکراری';$message='تردد این کارت در ۱۵ دقیقه گذشته ثبت شده است و دوباره ثبت نشد.';}
    else {$direction=($_POST['direction']??'entry')==='exit'?'exit':'entry';$db->prepare('INSERT INTO access_logs(card_id,operator_id,action) VALUES(?,?,?)')->execute([$scanned['id'],$_SESSION['user']['id'],$direction]);$message.= $direction==='entry'?' ورود ثبت شد.':' خروج ثبت شد.';}
   }
   $db->commit();$page='scan';
  }
 } catch (Throwable $ex) { if($db->inTransaction())$db->rollBack(); if (($action??'')==='scan') $scanned=false; $error=$ex instanceof PDOException ? (($ex->errorInfo[1]??null)==1062 && str_contains($ex->getMessage(),'unique_national_code') ? 'این کد ملی تکراری است و قبلاً برای آن کارت صادر شده است.' : 'عملیات انجام نشد. اطلاعات تکراری یا نامعتبر است.') : $ex->getMessage(); }
}
if(!isset($_SESSION['user']) && !in_array($page,['login','setup'],true))$page='login';
if(isset($_SESSION['user']) && $page==='dashboard' && $_SESSION['user']['role']==='operator')redirect('scan');
$role=$_SESSION['user']['role']??'';
if(in_array($page,['cards','form','card','print'],true))role('admin','user');
if(in_array($page,['logs','contractors','areas','users'],true))role('admin');
if($page==='scan')role('operator');
// Downloads are always admin-only, including direct requests, and use the same filters as the tables.
if (($_GET['export']??'')==='xlsx' && in_array($page,['cards','logs'],true)) {
    role('admin');
    require_once __DIR__.'/xlsx.php';
    try {
        $q=trim((string)($_GET['q']??''));
        if ($page==='cards') {
            $records=card_report($db,$q);$rows=[];
            foreach ($records as $c) $rows[]=[$c['first_name'].' '.$c['last_name'],$c['national_code'],$c['barcode'],$c['contractor'],jalali($c['created_at']),jalali($c['start_date']),jalali($c['end_date']),$c['access_area'],card_status($c)[0]];
            download_xlsx('issued-cards',['نام و نام خانوادگی','کد ملی','بارکد','پیمانکار','تاریخ صدور','شروع اعتبار','پایان اعتبار','محدوده تردد','وضعیت'],$rows);
        } else {
            $records=log_report($db,$q,(string)($_GET['type']??''),trim((string)($_GET['from']??'')),trim((string)($_GET['to']??'')));$rows=[];
            foreach ($records as $l) $rows[]=[$l['first_name'].' '.$l['last_name'],$l['national_code'],$l['barcode'],$l['action']==='entry'?'ورود':'خروج',$l['username'],jalali($l['created_at']).' '.date('H:i',strtotime($l['created_at']))];
            download_xlsx('access-logs',['نام و نام خانوادگی','کد ملی','بارکد','نوع تردد','اپراتور','تاریخ و ساعت'],$rows);
        }
    } catch (Throwable $ex) { http_response_code($ex instanceof InvalidArgumentException?400:500); exit(e($ex instanceof PDOException?'دریافت گزارش ناموفق بود.':$ex->getMessage())); }
}
$card=null;
if(in_array($page,['card','print','form'],true) && !empty($_GET['id'])){$s=$db->prepare('SELECT c.*, co.name contractor FROM cards c JOIN contractors co ON co.id=c.contractor_id WHERE c.id=?');$s->execute([(int)$_GET['id']]);$card=$s->fetch();if(!$card){http_response_code(404);exit('کارت پیدا نشد.');}if($page==='form' && $role==='user' && ((int)$card['created_by']!==(int)$_SESSION['user']['id'] || time()-strtotime($card['created_at'])>1800)){http_response_code(403);exit('مهلت ویرایش این کارت پایان یافته است.');}}
function token(){ echo '<input type="hidden" name="csrf" value="'.e(csrf()).'">'; }
function icon($name){ $icons=['dashboard'=>'◫','cards'=>'▤','form'=>'＋','scan'=>'⌗','logs'=>'◷','contractors'=>'♧','areas'=>'⌖','users'=>'♙'];return $icons[$name]??'•'; }
function card_markup($c){ ?>
<div class="physical-card" dir="rtl"><img class="card-top-image" src="assets/top.png" alt="شرکت معدنی و صنعتی گهرزمین، مدیریت حراست"><div class="card-title">کارت تردد موقت</div><div class="card-body"><img class="card-photo" src="uploads/<?=e($c['photo'])?>" alt="عکس پرسنلی"><div class="card-fields"><div><b>نام:</b> <?=e($c['first_name'])?></div><div><b>نام خانوادگی:</b> <?=e($c['last_name'])?></div><div><b>کد ملی:</b> <?=e($c['national_code'])?></div><div><b>نام پیمانکار:</b> <?=e($c['contractor'])?></div><div><b>شروع مجاز:</b> <?=e(jalali($c['start_date']))?></div><div><b>پایان مجاز:</b> <?=e(jalali($c['end_date']))?></div><div class="wide"><b>محدوده تردد:</b> <?=e($c['access_area'])?></div></div></div><div class="card-bottom"><span>تاریخ صدور: <?=e(jalali($c['created_at']))?></span><div class="barcode"><?=barcode_svg($c['barcode'])?><small><?=e($c['barcode'])?></small></div></div></div>
<?php }
?>
<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>سامانه صدور کارت موقت</title><link rel="stylesheet" href="assets/style.css"></head><body class="<?= $page==='print'?'print-page':'' ?>">
<?php if($page==='print' && $card): ?><div class="print-controls"><button onclick="window.print()">چاپ کارت</button><a href="index.php?page=card&id=<?=$card['id']?>">بازگشت</a><p>در تنظیمات چاپ، کاغذ A5 و جهت عمودی را انتخاب کنید.</p></div><?php card_markup($card); ?></body></html><?php exit; endif; ?>
<?php if(in_array($page,['login','setup'])): ?><main class="auth-wrap"><div class="auth-card"><?php if (is_file(__DIR__.'/assets/logogzh.png')): ?><img class="company-logo" src="assets/logogzh.png" alt="لوگوی شرکت گهرزمین"><?php else: ?><div class="brand-mark">G<span>●</span></div><?php endif; ?><h1>گذر<span>کارت</span></h1><p class="muted">سامانه مدیریت و صدور کارت تردد موقت</p><div class="auth-divider"></div><h2><?=$page==='setup'?'راه‌اندازی اولیه':'خوش آمدید'?></h2><p class="muted"><?=$page==='setup'?'حساب مدیر سیستم را ایجاد کنید.':'برای ورود به حساب کاربری، اطلاعات خود را وارد کنید.'?></p><?php if($error && $page!=='scan'):?><div class="alert danger"><?=e($error)?></div><?php endif; if(!empty($_SESSION['flash'])): ?><div class="alert success"><?=e($_SESSION['flash']); unset($_SESSION['flash']);?></div><?php endif;?><form method="post"><?php token();?><input type="hidden" name="action" value="<?=$page==='setup'?'setup':'login'?>"><label>نام کاربری<input name="username" required autocomplete="username" placeholder="نام کاربری خود را وارد کنید"></label><label>رمز عبور<input type="password" name="password" required autocomplete="current-password" placeholder="رمز عبور خود را وارد کنید"></label><button class="button primary full"><?=$page==='setup'?'ایجاد حساب مدیر':'ورود به سامانه'?> <span>←</span></button></form><p class="auth-footer">شرکت معدنی و صنعتی گهرزمین <span>•</span> مدیریت حراست</p></div></main></body></html><?php exit; endif; ?>
<div class="layout">

<aside class="sidebar">
  <a class="brand" href="index.php">
    <?php if (is_file(__DIR__.'/assets/logogzh.png')): ?>
      <img class="company-logo" src="assets/logogzh.png" alt="لوگوی شرکت گهرزمین">
    <?php else: ?>
      <div class="brand-mark">G<span>●</span></div>
    <?php endif; ?>
    <div>
      <strong>گذر<span>کارت</span></strong>
      <small>سامانه کارت تردد موقت</small>
    </div>
  </a>

  <div class="side-caption">منوی اصلی</div>

  <nav>
    <?php
    $nav = $role === 'operator'
      ? ['scan' => 'اسکن کارت']
      : ['dashboard' => 'داشبورد', 'cards' => 'کارت‌های صادر شده', 'form' => 'صدور کارت موقت'];

    if ($role === 'admin') {
      $nav += [
        'logs'        => 'گزارش ورود و خروج',
        'contractors' => 'مدیریت پیمانکاران',
        'areas'       => 'محدوده‌های تردد',
        'users'       => 'مدیریت کاربران',
      ];
    }

    foreach ($nav as $key => $title): ?>
      <a class="nav-link <?= $page === $key ? 'active' : '' ?>" href="index.php?page=<?= $key ?>">
        <span class="nav-icon"><?= icon($key) ?></span>
        <?= $title ?>
        <?php if ($page === $key): ?><i></i><?php endif; ?>
      </a>
    <?php endforeach; ?>
  </nav>

  <div class="sidebar-bottom">
    <form method="post" class="logout">
      <?php token(); ?>
      <input type="hidden" name="action" value="logout">
      <button type="submit">↪ &nbsp; خروج از حساب کاربری</button>
    </form>
  </div>
</aside>


<div class="main-area"><header class="topbar"><div class="breadcrumb">صفحه اصلی <span> / </span> <b><?=e($nav[$page]??'جزئیات کارت')?></b></div><div class="top-actions"><div class="today">◷ &nbsp; <?=e(jalali(date('Y-m-d')))?></div><div class="top-line"></div><img class="avatar role-avatar" src="assets/<?=e($role)?>.png" alt="آواتار نقش کاربر"><div class="profile"><b><?=e($_SESSION['user']['username'])?></b><small><?=e(['admin'=>'مدیر سیستم','user'=>'کاربر صدور','operator'=>'اپراتور'][$role])?></small></div></div></header><main class="content">
<?php if($error && $page!=='scan'):?><div class="alert danger"><?=e($error)?></div><?php endif; if(!empty($_SESSION['flash'])):?><div class="alert success"><?=e($_SESSION['flash']);unset($_SESSION['flash']);?></div><?php endif; ?>
<?php if($page==='password'): role('admin','user','operator'); ?><div class="page-heading"><div><div class="eyebrow">امنیت حساب</div><h1>تغییر رمز عبور</h1><p>پیش از ادامه، رمز عبور جدید خود را انتخاب کنید.</p></div></div><div class="panel form-panel"><form method="post"><?php token();?><input type="hidden" name="action" value="change_password"><div class="form-grid"><label>رمز عبور جدید<input type="password" name="new_password" minlength="8" required autocomplete="new-password"></label><label>تکرار رمز عبور<input type="password" name="confirm_password" minlength="8" required autocomplete="new-password"></label></div><div class="form-actions"><button class="button primary">ذخیره رمز جدید</button></div></form></div>
<?php elseif($page==='dashboard'):
$total=(int)$db->query('SELECT COUNT(*) FROM cards')->fetchColumn();$active=(int)$db->query("SELECT COUNT(*) FROM cards WHERE is_active=1 AND CURDATE() BETWEEN start_date AND end_date")->fetchColumn();$expired=(int)$db->query('SELECT COUNT(*) FROM cards WHERE end_date < CURDATE()')->fetchColumn();$today=(int)$db->query('SELECT COUNT(*) FROM cards WHERE DATE(created_at)=CURDATE()')->fetchColumn();$recent=$db->query('SELECT c.*,co.name contractor FROM cards c JOIN contractors co ON co.id=c.contractor_id ORDER BY c.created_at DESC,c.id DESC LIMIT 5')->fetchAll(); ?>
<div class="page-heading"><div><div class="eyebrow">نمای کلی سامانه</div><h1>داشبورد مدیریت <span>✦</span></h1><p>خوش آمدید! در اینجا می‌توانید وضعیت کارت‌های تردد را مشاهده کنید.</p></div><a class="button primary" href="index.php?page=form"><span>＋</span> صدور کارت جدید</a></div>
<div class="welcome-banner"><div class="banner-copy"><div class="banner-tag">سامانه هوشمند تردد <span>✦</span></div><h2>مدیریت تردد، ساده و امن</h2><p>تمام کارت‌های تردد موقت را در یک مکان صادر،<br> مدیریت و پیگیری کنید.</p><a href="index.php?page=form">صدور کارت موقت <span>←</span></a></div><div class="banner-art"><div class="art-orbit orbit-one"></div><div class="art-orbit orbit-two"></div><div class="art-card"><div class="art-top"><span>G</span><i></i><i></i></div><div class="art-person">♙</div><div class="art-lines"><i></i><i></i><i></i></div><div class="art-barcode">▥ ▥ ▥ ▥ ▥</div></div><div class="art-badge">✓</div><span class="art-spark spark-one">✦</span><span class="art-spark spark-two">✧</span></div></div>
<div class="section-title"><div><h2>نمای کلی کارت‌ها</h2><p>خلاصه‌ای از وضعیت کارت‌های صادر شده</p></div><span class="section-date">◷ &nbsp; به‌روزرسانی لحظه‌ای</span></div><div class="stats"><div class="stat"><div class="stat-icon blue">▤</div><div><span>کل کارت‌های صادر شده</span><strong><?=$total?></strong><small>مجموع کارت‌ها</small></div><div class="stat-arrow">↗</div></div><div class="stat"><div class="stat-icon green">✓</div><div><span>کارت‌های فعال</span><strong><?=$active?></strong><small>در بازه مجاز تردد</small></div><div class="stat-arrow">↗</div></div><div class="stat"><div class="stat-icon orange">◷</div><div><span>کارت‌های منقضی شده</span><strong><?=$expired?></strong><small>پایان اعتبار کارت</small></div><div class="stat-arrow">↗</div></div><div class="stat"><div class="stat-icon purple">＋</div><div><span>صدور امروز</span><strong><?=$today?></strong><small>کارت صادر شده امروز</small></div><div class="stat-arrow">↗</div></div></div>
<div class="section-title recent-title"><div><h2>آخرین کارت‌های صادر شده</h2><p>لیست آخرین کارت‌های ثبت شده در سامانه</p></div><a class="text-link" href="index.php?page=cards">مشاهده همه کارت‌ها ←</a></div><?php render_table($recent,$role); ?>
<?php elseif($page==='cards'): $q=trim($_GET['q']??'');$cards=card_report($db,$q); ?>
<div class="page-heading"><div><div class="eyebrow">مدیریت کارت‌ها</div><h1>کارت‌های صادر شده</h1><p>مشاهده، جستجو و مدیریت کارت‌های تردد موقت</p></div><a class="button primary" href="index.php?page=form">＋ &nbsp; صدور کارت جدید</a></div><div class="panel"><form class="search-form" method="get"><input type="hidden" name="page" value="cards"><input name="q" value="<?=e($q)?>" placeholder="جستجو با نام، نام خانوادگی، کد ملی یا بارکد..."><button class="button primary">جستجو</button></form></div><div class="section-title"><div><h2>لیست کارت‌ها</h2><p><?=count($cards)?> کارت یافت شد</p></div><?php if($role==='admin'):?><a class="button excel" href="index.php?<?=e(http_build_query(['page'=>'cards','q'=>$q,'export'=>'xlsx']))?>">↓ دانلود اکسل</a><?php endif;?></div><?php render_table($cards,$role); ?>
<?php elseif($page==='form'): $contractors=$db->query('SELECT * FROM contractors ORDER BY name')->fetchAll(); $areas=$db->query('SELECT * FROM access_areas ORDER BY name')->fetchAll(); ?>
<div class="page-heading"><div><div class="eyebrow">مدیریت کارت‌ها</div><h1><?=$card?'ویرایش کارت':'صدور کارت موقت'?></h1><p>اطلاعات فرد و بازه مجاز تردد را وارد کنید.</p></div><a class="button soft" href="index.php?page=cards">بازگشت به لیست ←</a></div><div class="panel form-panel"><div class="panel-heading"><div class="stat-icon blue">▤</div><div><h2>اطلاعات کارت تردد</h2><p>لطفاً تمامی فیلدهای ضروری را تکمیل کنید.</p></div></div><?php if(!$contractors):?><div class="alert warning">هنوز پیمانکاری ثبت نشده است. لطفاً از مدیر بخواهید پیمانکار اضافه کند.</div><?php endif;?><?php if(!$areas):?><div class="alert warning">هنوز محدوده ترددی ثبت نشده است. لطفاً از مدیر بخواهید محدوده اضافه کند.</div><?php endif;?><form method="post" enctype="multipart/form-data"><?php token();?><input type="hidden" name="action" value="save_card"><input type="hidden" name="id" value="<?=e($card['id']??'')?>"><div class="form-grid"><label>نام <em>*</em><input name="first_name" required value="<?=e($_POST['first_name']??$card['first_name']??'')?>" placeholder="نام را وارد کنید"></label><label>نام خانوادگی <em>*</em><input name="last_name" required value="<?=e($_POST['last_name']??$card['last_name']??'')?>" placeholder="نام خانوادگی را وارد کنید"></label><label>کد ملی <em>*</em><input name="national_code" required value="<?=e($_POST['national_code']??$card['national_code']??'')?>" placeholder="۱۰ رقم" inputmode="numeric"></label><label>نام پیمانکار <em>*</em><select name="contractor_id" required><option value="">انتخاب پیمانکار</option><?php foreach($contractors as $c):?><option value="<?=$c['id']?>" <?=($c['id']==($_POST['contractor_id']??$card['contractor_id']??''))?'selected':''?>><?=e($c['name'])?></option><?php endforeach;?></select></label><label>شروع تاریخ مجاز <em>*</em><input name="start_date" required value="<?=e($_POST['start_date']??(isset($card['start_date'])?jalali($card['start_date']):jalali(date('Y-m-d'))))?>" placeholder="۱۴۰۵/۰۱/۰۱"><small>تاریخ شمسی، به شکل سال/ماه/روز</small></label><label>پایان تاریخ مجاز <em>*</em><input name="end_date" required value="<?=e($_POST['end_date']??(isset($card['end_date'])?jalali($card['end_date']):''))?>" placeholder="۱۴۰۵/۰۱/۰۱"><small>تاریخ شمسی، به شکل سال/ماه/روز</small></label><label class="wide">محدوده تردد <em>*</em><select name="access_area_id" required><option value="">انتخاب محدوده تردد</option><?php foreach($areas as $a):?><option value="<?=$a['id']?>" <?=(isset($_POST['access_area_id']) ? (int)$_POST['access_area_id']===(int)$a['id'] : ($card['access_area']??'')===$a['name'])?'selected':''?>><?=e($a['name'])?></option><?php endforeach;?></select><small>محدوده‌ها توسط مدیر تعریف می‌شوند.</small></label><label class="wide">عکس پرسنلی <em>*</em><input type="file" name="photo" accept="image/jpeg,image/png,image/webp" <?=(!empty($card['photo']) && is_file(__DIR__.'/uploads/'.basename($card['photo'])))?'':'required'?>><small>فرمت JPG، PNG یا WebP؛ حداکثر ۳ مگابایت. در ویرایش، عکس موجود حفظ می‌شود.</small></label></div><div class="form-actions"><button class="button primary">✓ &nbsp; ذخیره و مشاهده کارت</button><a class="button soft" href="index.php?page=cards">انصراف</a></div></form></div>
<?php elseif($page==='card' && $card): [$label,$status,$message]=card_status($card); ?>
<div class="page-heading"><div><div class="eyebrow">کارت‌های صادر شده</div><h1>جزئیات کارت تردد</h1><p>پیش‌نمایش و مدیریت کارت صادر شده</p></div><div class="heading-actions"><a class="button soft" href="index.php?page=cards">بازگشت</a><a class="button primary" href="index.php?page=print&id=<?=$card['id']?>">▣ &nbsp; چاپ کارت</a></div></div><div class="panel preview-panel"><div class="panel-heading"><div><h2>پیش‌نمایش کارت</h2><p>کارت در اندازه ۱۰ × ۵ سانتی‌متر چاپ خواهد شد.</p></div><span class="pill <?=$status?>"><?=$label?></span></div><div class="card-stage"><?php card_markup($card);?></div><div class="detail-actions"><?php if($role==='admin'||((int)$card['created_by']===(int)$_SESSION['user']['id'] && time()-strtotime($card['created_at'])<=1800)):?><a class="button soft" href="index.php?page=form&id=<?=$card['id']?>">✎ &nbsp; ویرایش کارت</a><?php else:?><span class="muted">مهلت ویرایش کارت به پایان رسیده است.</span><?php endif;?></div><?php if($role==='admin'):?><div class="admin-card-action"><h3>وضعیت کارت</h3><p class="muted"><?=e($message)?></p><form method="post"><?php token();?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?=$card['id']?>"><input type="hidden" name="active" value="<?=$card['is_active']?'0':'1'?>"><?php if($card['is_active']):?><input name="reason" placeholder="دلیل غیرفعال‌سازی (اختیاری)"><?php endif;?><button class="button <?=$card['is_active']?'danger-button':'primary'?>"><?=$card['is_active']?'غیرفعال کردن کارت':'فعال کردن کارت'?></button></form></div><?php endif;?></div>
<?php elseif($page==='scan'): ?><div class="page-heading"><div><div class="eyebrow">کنترل تردد</div><h1>اسکن کارت تردد</h1><p>بارکد کارت را اسکن کنید تا وضعیت مجوز بررسی شود.</p></div></div><div class="panel scan-panel"><div class="scan-symbol">⌗</div><h2>بررسی اعتبار کارت</h2><p class="muted">بارکدخوان را در فیلد زیر قرار دهید یا کد را به صورت دستی وارد کنید.</p><form method="post"><?php token();?><input type="hidden" name="action" value="scan"><label>بارکد کارت<input name="barcode" autofocus required autocomplete="off" dir="ltr" placeholder="بارکد کارت را اسکن کنید"></label>


<div style="display:none">
  <label>نوع تردد
    <select name="direction">
      <option value="entry">ورود</option>
      <option value="exit">خروج</option>
    </select>
  </label>
</div>

<button class="button primary full">بررسی کارت ←</button></form></div><?php if(!empty($scanned) || $error):?><div class="scan-overlay" id="scan-modal" data-timeout="<?=empty($scanned) && $error==='بارکد معتبر نیست، مجدد تلاش کنید.' ? 2000 : 8000?>" role="dialog" aria-modal="true" aria-label="نتیجه بررسی کارت"><div class="panel scan-result <?=!empty($scanned)?e($status):'danger'?>"><button class="scan-close" type="button" aria-label="بستن">×</button><?php if(!empty($scanned)):?><div class="result-icon"><?=$status==='success'?'✓':'✕'?></div><div><h2><?=e($label)?></h2><p><?=e($message)?></p><b><?=e($scanned['first_name'].' '.$scanned['last_name'])?></b> <span> | کد ملی: <?=e($scanned['national_code'])?> | پیمانکار: <?=e($scanned['contractor'])?></span><p>محدوده: <?=e($scanned['access_area'])?> · اعتبار: <?=e(jalali($scanned['start_date']))?> تا <?=e(jalali($scanned['end_date']))?></p></div><img src="uploads/<?=e($scanned['photo'])?>" alt="عکس پرسنلی"><div class="scan-card-preview"><?php card_markup($scanned); ?></div><?php else:?><div class="result-icon">✕</div><div><h2><?=e($error==='بارکد معتبر نیست، مجدد تلاش کنید.'?'بارکد معتبر نیست':'خطا در بررسی کارت')?></h2><p><?=e($error)?></p></div><?php endif;?></div></div><?php endif;?>
<script>
(() => { const modal=document.getElementById('scan-modal'); const input=document.querySelector('.scan-panel input[name="barcode"]'); if(!modal || !input)return; const close=()=>{modal.hidden=true;input.focus();}; modal.querySelector('.scan-close').addEventListener('click',close); window.setTimeout(close,Number(modal.dataset.timeout)); })();
</script>
<?php elseif($page==='logs'):
 $q=trim($_GET['q']??'');$filterAction=$_GET['type']??'';$from=trim($_GET['from']??'');$to=trim($_GET['to']??'');$reportError='';$logs=[];
 try { $logs=log_report($db,$q,$filterAction,$from,$to); } catch (InvalidArgumentException $ex) { $reportError=$ex->getMessage(); }
 ?><div class="page-heading"><div><div class="eyebrow">کنترل تردد</div><h1>گزارش ورود و خروج</h1><p>تاریخچه ترددهای مجاز ثبت شده توسط اپراتورها</p></div></div><div class="panel"><form method="get" class="form-grid"><input type="hidden" name="page" value="logs"><label>جستجو<input name="q" value="<?=e($q)?>" placeholder="نام، کد ملی، بارکد یا نام اپراتور"></label><label>نوع تردد<select name="type"><option value="">همه</option><option value="entry" <?=$filterAction==='entry'?'selected':''?>>ورود</option><option value="exit" <?=$filterAction==='exit'?'selected':''?>>خروج</option></select></label><label>از تاریخ شمسی<input name="from" value="<?=e($from)?>" placeholder="۱۴۰۵/۰۱/۰۱"></label><label>تا تاریخ شمسی<input name="to" value="<?=e($to)?>" placeholder="۱۴۰۵/۰۱/۳۱"></label><div class="form-actions report-actions"><button class="button primary">جستجو و فیلتر</button><a class="button soft" href="index.php?page=logs">پاک کردن فیلتر</a><?php if(!$reportError):?><a class="button excel" href="index.php?<?=e(http_build_query(['page'=>'logs','q'=>$q,'type'=>$filterAction,'from'=>$from,'to'=>$to,'export'=>'xlsx']))?>">↓ دانلود اکسل</a><?php endif;?></div></form></div><?php if($reportError):?><div class="alert danger"><?=e($reportError)?></div><?php endif;?><div class="table-wrap"><table><thead><tr><th>نام و نام خانوادگی</th><th>کد ملی</th><th>بارکد</th><th>نوع تردد</th><th>اپراتور</th><th>تاریخ و ساعت</th></tr></thead><tbody><?php foreach($logs as $l):?><tr><td><?=e($l['first_name'].' '.$l['last_name'])?></td><td><?=e($l['national_code'])?></td><td><?=e($l['barcode'])?></td><td><span class="pill success"><?=$l['action']==='entry'?'ورود':'خروج'?></span></td><td><?=e($l['username'])?></td><td><?=e(jalali($l['created_at']).' '.date('H:i',strtotime($l['created_at'])))?></td></tr><?php endforeach;?></tbody></table><?php if(!$logs):?><div class="empty">ترددی برای نمایش یافت نشد.</div><?php endif;?></div>
<?php elseif($page==='contractors'): $list=$db->query('SELECT * FROM contractors ORDER BY name')->fetchAll();?><div class="page-heading"><div><div class="eyebrow">تنظیمات سامانه</div><h1>مدیریت پیمانکاران</h1><p>افزودن پیمانکار برای انتخاب در زمان صدور کارت</p></div></div><div class="panel"><h2>افزودن پیمانکار</h2><form class="inline-form" method="post"><?php token();?><input type="hidden" name="action" value="contractor"><input name="name" required placeholder="نام پیمانکار"><button class="button primary">＋ افزودن پیمانکار</button></form></div><div class="panel list-panel"><h2>پیمانکاران ثبت شده</h2><?php foreach($list as $item):?><div class="list-item"><span>♧</span><?=e($item['name'])?></div><?php endforeach; if(!$list):?><div class="empty">پیمانکاری ثبت نشده است.</div><?php endif;?></div>
<?php elseif($page==='areas'): $list=$db->query('SELECT * FROM access_areas ORDER BY name')->fetchAll();?><div class="page-heading"><div><div class="eyebrow">تنظیمات سامانه</div><h1>مدیریت محدوده‌های تردد</h1><p>افزودن محدوده مجاز برای انتخاب هنگام صدور کارت</p></div></div><div class="panel"><h2>افزودن محدوده تردد</h2><form class="inline-form" method="post"><?php token();?><input type="hidden" name="action" value="area"><input name="name" maxlength="255" required placeholder="نام محدوده تردد"><button class="button primary">＋ افزودن محدوده</button></form></div><div class="panel list-panel"><h2>محدوده‌های ثبت شده</h2><?php foreach($list as $item):?><div class="list-item"><span>⌖</span><?=e($item['name'])?></div><?php endforeach; if(!$list):?><div class="empty">محدوده‌ای ثبت نشده است.</div><?php endif;?></div>
<?php elseif($page==='users'): $list=$db->query('SELECT id,username,role,created_at FROM users WHERE is_deleted=0 ORDER BY id DESC')->fetchAll();?><div class="page-heading"><div><div class="eyebrow">تنظیمات سامانه</div><h1>مدیریت کاربران</h1><p>ایجاد و مدیریت حساب کاربران</p></div></div><div class="panel"><h2>افزودن کاربر</h2><form method="post" class="form-grid"><?php token();?><input type="hidden" name="action" value="add_user"><label>نام کاربری<input name="username" maxlength="100" required></label><label>رمز عبور<input type="password" name="password" minlength="8" required></label><label>نقش<select name="role"><option value="user">کاربر صدور</option><option value="operator">اپراتور</option><option value="admin">مدیر</option></select></label><div class="align-end"><button class="button primary">＋ افزودن کاربر</button></div></form></div><div class="panel"><h2>کاربران ثبت شده</h2><?php foreach($list as $u):?><div class="user-management-row"><div class="user-identity"><img class="role-avatar" src="assets/<?=e($u['role'])?>.png" alt=""><span><?=e($u['username'])?><small><?=e(jalali($u['created_at']))?></small></span></div><?php if((int)$u['id']!==(int)$_SESSION['user']['id']):?><form method="post" class="user-edit"><?php token();?><input type="hidden" name="action" value="edit_user"><input type="hidden" name="id" value="<?=$u['id']?>"><input name="username" maxlength="100" required value="<?=e($u['username'])?>" aria-label="نام کاربری"><select name="role" aria-label="نقش"><?php foreach(['admin'=>'مدیر','user'=>'کاربر صدور','operator'=>'اپراتور'] as $key=>$name):?><option value="<?=$key?>" <?=$u['role']===$key?'selected':''?>><?=$name?></option><?php endforeach;?></select><button class="button soft">ویرایش</button></form><form method="post" onsubmit="return confirm('رمز این کاربر به نام کاربری او تغییر کند؟')"><?php token();?><input type="hidden" name="action" value="reset_user"><input type="hidden" name="id" value="<?=$u['id']?>"><button class="button soft">بازنشانی رمز</button></form><form method="post" onsubmit="return confirm('این حساب حذف شود؟ سوابق تردد حفظ می‌شوند.')"><?php token();?><input type="hidden" name="action" value="delete_user"><input type="hidden" name="id" value="<?=$u['id']?>"><button class="button danger-button">حذف</button></form><?php else:?><span class="muted">حساب فعلی</span><?php endif;?></div><?php endforeach;?></div>
<?php else: ?><div class="empty">صفحه مورد نظر یافت نشد.</div><?php endif; ?>
<footer>سامانه صدور کارت تردد موقت <span>•</span> شرکت معدنی و صنعتی گهرزمین</footer></main></div></div></body></html>
<?php function render_table($cards,$role){?><div class="table-wrap"><table><thead><tr><th>نام و نام خانوادگی</th><th>کد ملی</th><th>پیمانکار</th><th>تاریخ صدور</th><th>شروع اعتبار</th><th>اعتبار تا</th><th>محدوده تردد</th><th>وضعیت</th><th>عملیات</th></tr></thead><tbody><?php foreach($cards as $c): [$label,$status]=card_status($c);?><tr><td><div class="person-cell"><img src="uploads/<?=e($c['photo'])?>" alt=""><div><b><?=e($c['first_name'].' '.$c['last_name'])?></b><small><?=e($c['barcode'])?></small></div></div></td><td><?=e($c['national_code'])?></td><td><?=e($c['contractor'])?></td><td><?=e(jalali($c['created_at']))?></td><td><?=e(jalali($c['start_date']))?></td><td><?=e(jalali($c['end_date']))?></td><td><?=e($c['access_area'])?></td><td><span class="pill <?=$status?>"><i></i><?=e($label)?></span></td><td><div class="table-actions"><a title="مشاهده" href="index.php?page=card&id=<?=$c['id']?>">◉</a><?php if($role==='admin'||((int)$c['created_by']===(int)$_SESSION['user']['id'] && time()-strtotime($c['created_at'])<=1800)):?><a title="ویرایش" href="index.php?page=form&id=<?=$c['id']?>">✎</a><?php endif;?><a title="چاپ" href="index.php?page=print&id=<?=$c['id']?>">▣</a></div></td></tr><?php endforeach;?></tbody></table><?php if(!$cards):?><div class="empty">کارتی برای نمایش وجود ندارد.</div><?php endif;?></div><?php }
