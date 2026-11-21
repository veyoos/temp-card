<?php
require_once __DIR__.'/config.php';
function db(): PDO { static $db; if (!$db) { $db = new PDO('mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4', DB_USER, DB_PASS, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES=>false]); $db->prepare('SET time_zone = ?')->execute([date('P')]); } return $db; }
// Database-backed sessions are not removed by PHP's normal inactivity garbage collection.
class PersistentSessions implements SessionHandlerInterface {
    private PDO $db;
    private ?string $lock = null;
    public function __construct(PDO $db) { $this->db = $db; }
    public function open(string $path, string $name): bool { return true; }
    public function close(): bool { if ($this->lock !== null) { $s=$this->db->prepare('SELECT RELEASE_LOCK(?)'); $s->execute([$this->lock]); $this->lock=null; } return true; }
    public function read(string $id): string|false {
        $this->lock='tc_session_'.hash('sha256', $id); // MySQL lock names are limited to 64 bytes.
        $this->lock=substr($this->lock,0,64);
        $s=$this->db->prepare('SELECT GET_LOCK(?, 15)'); $s->execute([$this->lock]);
        if ((int)$s->fetchColumn() !== 1) { $this->lock=null; return false; }
        $s=$this->db->prepare('SELECT data FROM app_sessions WHERE id=?'); $s->execute([$id]);
        $data=$s->fetchColumn(); return $data === false ? '' : (string)$data;
    }
    public function write(string $id, string $data): bool { $s=$this->db->prepare('INSERT INTO app_sessions(id,data) VALUES(?,?) ON DUPLICATE KEY UPDATE data=VALUES(data), updated_at=CURRENT_TIMESTAMP'); return $s->execute([$id,$data]); }
    public function destroy(string $id): bool { $s=$this->db->prepare('DELETE FROM app_sessions WHERE id=?'); return $s->execute([$id]); }
    public function gc(int $max_lifetime): int|false { return 0; }
}
function start_app_session(PDO $db): void {
    static $started = false;
    if ($started) return;
    // Some hosts or an older config.php start PHP's default session before this point.
    // Close it before switching handlers; never carry unauthenticated legacy state across.
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
        $_SESSION = [];
    }
    session_set_cookie_params(['httponly'=>true, 'samesite'=>'Lax', 'secure'=>!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off']);
    if (!session_set_save_handler(new PersistentSessions($db), true) || !session_start()) {
        throw new RuntimeException('شروع نشست ناموفق بود.');
    }
    $started = true;
    if (isset($_SESSION['user']) && in_array($_SESSION['user']['role'], ['user','operator'], true)) remember_session();
}
function remember_session(): void {
    $params=session_get_cookie_params();
    setcookie(session_name(), session_id(), ['expires'=>time()+10*365*86400, 'path'=>$params['path'], 'domain'=>$params['domain'], 'secure'=>$params['secure'], 'httponly'=>true, 'samesite'=>'Lax']);
}
function e($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function csrf(): string { if (empty($_SESSION['csrf'])) $_SESSION['csrf']=bin2hex(random_bytes(32)); return $_SESSION['csrf']; }
function check_csrf(): void { if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) { http_response_code(403); exit('درخواست نامعتبر است.'); } }
function redirect($page): void { header('Location: index.php?page='.$page); exit; }
function flash($msg): void { $_SESSION['flash']=$msg; }
function role(...$roles): void { if (!isset($_SESSION['user']) || !in_array($_SESSION['user']['role'], $roles, true)) { http_response_code(403); exit('دسترسی غیرمجاز'); } }
function digits($s): string { return str_replace(['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹','٠','١','٢','٣','٤','٥','٦','٧','٨','٩'],['0','1','2','3','4','5','6','7','8','9','0','1','2','3','4','5','6','7','8','9'],trim((string)$s)); }
// Calendar conversion based on the arithmetic Jalali calendar.
function gregorian_to_jalali($gy,$gm,$gd): array { $g_d_m=[0,31,59,90,120,151,181,212,243,273,304,334]; $gy2=($gm>2)?$gy+1:$gy; $days=355666+365*$gy+intdiv($gy2+3,4)-intdiv($gy2+99,100)+intdiv($gy2+399,400)+$gd+$g_d_m[$gm-1]; $jy=-1595+33*intdiv($days,12053); $days%=12053; $jy+=4*intdiv($days,1461); $days%=1461; if($days>365){$jy+=intdiv($days-1,365);$days=($days-1)%365;} if($days<186){$jm=1+intdiv($days,31);$jd=1+$days%31;}else{$jm=7+intdiv($days-186,30);$jd=1+($days-186)%30;} return [$jy,$jm,$jd]; }
function jalali_to_gregorian($jy,$jm,$jd): array { $jy+=1595; $days=-355668+365*$jy+intdiv($jy,33)*8+intdiv(($jy%33)+3,4)+$jd+(($jm<7)?($jm-1)*31:(($jm-7)*30+186)); $gy=400*intdiv($days,146097); $days%=146097; if($days>36524){$gy+=100*intdiv(--$days,36524);$days%=36524;if($days>=365)$days++;} $gy+=4*intdiv($days,1461);$days%=1461;if($days>365){$gy+=intdiv($days-1,365);$days=($days-1)%365;} $gd=$days+1; $leap=($gy%4==0 && $gy%100!=0)||$gy%400==0; $sal=[0,31,$leap?29:28,31,30,31,30,31,31,30,31,30,31]; for($gm=1;$gm<=12 && $gd>$sal[$gm];$gm++)$gd-=$sal[$gm]; return [$gy,$gm,$gd]; }
function jalali($date): string { if (!$date) return '—'; $t=strtotime($date); if (!$t) return '—'; return sprintf('%04d/%02d/%02d', ...gregorian_to_jalali((int)date('Y',$t),(int)date('n',$t),(int)date('j',$t))); }
function parse_jalali($input): ?string { $s=digits($input); if(!preg_match('~^(\d{4})[/-](\d{1,2})[/-](\d{1,2})$~',$s,$m)) return null; $y=(int)$m[1];$mo=(int)$m[2];$d=(int)$m[3]; if($y<1300||$y>1500||$mo<1||$mo>12||$d<1||$d>31)return null; [$gy,$gm,$gd]=jalali_to_gregorian($y,$mo,$d); if(!checkdate($gm,$gd,$gy)||gregorian_to_jalali($gy,$gm,$gd)!==[$y,$mo,$d])return null; return sprintf('%04d-%02d-%02d',$gy,$gm,$gd); }
function card_status($c): array { $today=date('Y-m-d'); if(!$c['is_active']) return ['غیرفعال', 'danger', $c['disabled_reason'] ? 'کارت غیرفعال شده است: '.$c['disabled_reason'] : 'این کارت غیرفعال شده است.']; if($today<$c['start_date'])return ['هنوز معتبر نیست','warning','تاریخ مجاز این کارت هنوز آغاز نشده است.']; if($today>$c['end_date'])return ['منقضی شده','danger','تاریخ کارت موقت شما به اتمام رسیده است.']; return ['مجاز','success','این کارت در بازه مجاز قرار دارد.']; }
// Code 128 C encodes pairs of digits compactly; Code 128 B preserves older alphanumeric card IDs.
function barcode_svg($value): string {
    $value=(string)$value;
    if ($value === '' || !preg_match('/^[\x20-\x7E]+$/D', $value)) throw new InvalidArgumentException('Invalid barcode value');
    $patterns=['11011001100','11001101100','11001100110','10010011000','10010001100','10001001100','10011001000','10011000100','10001100100','11001001000','11001000100','11000100100','10110011100','10011011100','10011001110','10111001100','10011101100','10011100110','11001110010','11001011100','11001001110','11011100100','11001110100','11101101110','11101001100','11100101100','11100100110','11101100100','11100110100','11100110010','11011011000','11011000110','11000110110','10100011000','10001011000','10001000110','10110001000','10001101000','10001100010','11010001000','11000101000','11000100010','10110111000','10110001110','10001101110','10111011000','10111000110','10001110110','11101110110','11010001110','11000101110','11011101000','11011100010','11011101110','11101011000','11101000110','11100010110','11101101000','11101100010','11100011010','11101111010','11001000010','11110001010','10100110000','10100001100','10010110000','10010000110','10000101100','10000100110','10110010000','10110000100','10011010000','10011000010','10000110100','10000110010','11000010010','11001010000','11110111010','11000010100','10001111010','10100111100','10010111100','10010011110','10111100100','10011110100','10011110010','11110100100','11110010100','11110010010','11011011110','11011110110','11110110110','10101111000','10100011110','10001011110','10111101000','10111100010','11110101000','11110100010','10111011110','10111101110','11101011110','11110101110','11010000100','11010010000','11010011100'];
    $numeric=preg_match('/^[0-9]+$/D',$value) && strlen($value)%2===0;
    $codes=[$numeric ? 105 : 104];
    if ($numeric) { foreach (str_split($value,2) as $pair) $codes[]=(int)$pair; }
    else { foreach (str_split($value) as $char) $codes[]=ord($char)-32; }
    $checksum=$codes[0];
    for($i=1;$i<count($codes);$i++) $checksum += $codes[$i]*$i;
    $codes[]=$checksum%103;
    $bits=str_repeat('0',10);
    foreach($codes as $code) $bits.=$patterns[$code];
    $bits.='1100011101011'.str_repeat('0',10);
    $bars='';$len=strlen($bits);
    for($i=0;$i<$len;) {
        if($bits[$i]==='0') { $i++; continue; }
        $start=$i; while($i<$len && $bits[$i]==='1') $i++;
        $bars.='<rect x="'.$start.'" y="0" width="'.($i-$start).'" height="50"/>';
    }
    return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 '.$len.' 50" preserveAspectRatio="none" role="img" aria-label="'.e($value).'" shape-rendering="crispEdges"><rect width="'.$len.'" height="50" fill="#eff5fe"/><g fill="black">'.$bars.'</g></svg>';
}
function photo_upload($existing=''): string { if(empty($_FILES['photo']['name'])) { if($existing && is_file(__DIR__.'/uploads/'.basename($existing)))return $existing; throw new Exception('عکس پرسنلی را انتخاب کنید.'); } $f=$_FILES['photo']; if($f['error']!==UPLOAD_ERR_OK || $f['size']>3*1024*1024)throw new Exception('حجم عکس باید کمتر از ۳ مگابایت باشد.'); $type=(new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']); $ext=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'][$type]??null; if(!$ext || !getimagesize($f['tmp_name']))throw new Exception('فقط عکس JPG، PNG یا WebP مجاز است.'); $name=bin2hex(random_bytes(16)).'.'.$ext; if(!move_uploaded_file($f['tmp_name'],__DIR__.'/uploads/'.$name))throw new Exception('ذخیره عکس ناموفق بود.'); return $name; }
function mb_substr_fallback($s): string { return function_exists('mb_substr') ? mb_substr($s,0,1,'UTF-8') : (preg_match('/^./us',$s,$m)?$m[0]:'U'); }
