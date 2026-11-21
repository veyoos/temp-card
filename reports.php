<?php
// Shared by the on-screen tables and their Excel downloads.
function card_report(PDO $db, string $q): array {
    $sql='SELECT c.*,co.name contractor FROM cards c JOIN contractors co ON co.id=c.contractor_id'; $params=[];
    if ($q!=='') { $sql.=' WHERE c.first_name LIKE ? OR c.last_name LIKE ? OR c.national_code LIKE ? OR c.barcode LIKE ? OR CONCAT(c.first_name, " ", c.last_name) LIKE ?'; $params=array_fill(0,5,'%'.digits($q).'%'); }
    $sql.=' ORDER BY c.created_at DESC,c.id DESC'; $s=$db->prepare($sql); $s->execute($params); return $s->fetchAll();
}
function log_report(PDO $db, string $q, string $type, string $from, string $to): array {
    $start=$from!==''?parse_jalali($from):null; $end=$to!==''?parse_jalali($to):null;
    if (($from!==''&&!$start)||($to!==''&&!$end)||($start&&$end&&$start>$end)||!in_array($type,['','entry','exit'],true)) throw new InvalidArgumentException('فیلترها معتبر نیستند؛ تاریخ شمسی و بازه زمانی را بررسی کنید.');
    $sql='SELECT l.*,c.first_name,c.last_name,c.national_code,c.barcode,u.username FROM access_logs l JOIN cards c ON c.id=l.card_id JOIN users u ON u.id=l.operator_id WHERE 1=1'; $params=[];
    if ($q!=='') { $sql.=' AND (c.first_name LIKE ? OR c.last_name LIKE ? OR CONCAT(c.first_name, " ", c.last_name) LIKE ? OR c.national_code LIKE ? OR c.barcode LIKE ? OR u.username LIKE ?)'; $params=array_fill(0,6,'%'.digits($q).'%'); }
    if ($type!=='') { $sql.=' AND l.action=?'; $params[]=$type; }
    if ($start) { $sql.=' AND l.created_at>=?'; $params[]=$start.' 00:00:00'; }
    if ($end) { $sql.=' AND l.created_at<?'; $params[]=date('Y-m-d',strtotime($end.' +1 day')).' 00:00:00'; }
    $sql.=' ORDER BY l.created_at DESC,l.id DESC'; $s=$db->prepare($sql); $s->execute($params); return $s->fetchAll();
}
