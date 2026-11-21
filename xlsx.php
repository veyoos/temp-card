<?php
// Minimal XLSX generator: all cells are strings, preserving leading zeroes and preventing formula execution.
function xlsx_xml(string $value): string {
    $value = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $value) ?? '';
    return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function xlsx_column(int $i): string { $s=''; do { $s=chr(65+$i%26).$s; $i=intdiv($i,26)-1; } while($i>=0); return $s; }
function make_xlsx(array $headers, iterable $rows): string {
    if (!class_exists('ZipArchive')) throw new RuntimeException('برای دانلود اکسل افزونه ZIP در PHP باید فعال باشد.');
    $sheet='<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetViews><sheetView workbookViewId="0" rightToLeft="1"/></sheetViews><cols><col min="1" max="'.count($headers).'" width="22" customWidth="1"/></cols><sheetData>';
    $line=0;
    foreach ((function() use ($headers,$rows) { yield $headers; yield from $rows; })() as $row) {
        $line++;$sheet.='<row r="'.$line.'">';
        foreach (array_values($row) as $i=>$value) { $sheet.='<c r="'.xlsx_column($i).$line.'" t="inlineStr"><is><t xml:space="preserve">'.xlsx_xml((string)$value).'</t></is></c>'; }
        $sheet.='</row>';
    }
    $sheet.='</sheetData></worksheet>';
    $path=tempnam(sys_get_temp_dir(),'tcxlsx_'); if ($path===false) throw new RuntimeException('ساخت فایل موقت اکسل ناموفق بود.');
    $zip=new ZipArchive(); if ($zip->open($path,ZipArchive::CREATE|ZipArchive::OVERWRITE)!==true) { @unlink($path); throw new RuntimeException('ساخت فایل اکسل ناموفق بود.'); }
    $files=[
      '[Content_Types].xml'=>'<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>',
      '_rels/.rels'=>'<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>',
      'xl/workbook.xml'=>'<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="گزارش" sheetId="1" r:id="rId1"/></sheets></workbook>',
      'xl/_rels/workbook.xml.rels'=>'<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>',
      'xl/worksheets/sheet1.xml'=>$sheet,
    ];
    try { foreach ($files as $name=>$contents) if (!$zip->addFromString($name,$contents)) throw new RuntimeException('نوشتن فایل اکسل ناموفق بود.'); if (!$zip->close()) throw new RuntimeException('تکمیل فایل اکسل ناموفق بود.'); }
    catch (Throwable $e) { $zip->close(); @unlink($path); throw $e; }
    return $path;
}
function download_xlsx(string $filename, array $headers, iterable $rows): void {
    $path=make_xlsx($headers,$rows);
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="'.$filename.'.xlsx"');
    header('Cache-Control: private, no-store'); header('X-Content-Type-Options: nosniff');
    header('Content-Length: '.filesize($path)); readfile($path); @unlink($path); exit;
}
