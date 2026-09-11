<?php
require_once "../config/database.php";
require_once "../includes/functions.php";
require_once __DIR__."/receipt_shared.php";
require_role('staff');
ensure_pos_product_schema($conn);
$transactionId=(int)($_GET['transaction_id']??0);
$receipt=staff_receipt_snapshot($conn,$transactionId);
if(!$receipt){http_response_code(404);exit('Receipt not found.');}
$lines=staff_receipt_text_lines($receipt);
function pdf_escape_text(string $text): string {return str_replace(['\\','(',')'],['\\\\','\\(','\\)'],$text);}
$width=334.49;
$lineHeight=13.3125;
$left=19.84;
$top=25.51;
$bottom=25.51;
$height=max(360,$top+$bottom+count($lines)*$lineHeight);
$y=$height-$top;
$stream="BT\n/F1 9.375 Tf\n";
foreach($lines as $line){
    $stream.='1 0 0 1 '.number_format($left,2,'.','').' '.number_format($y,2,'.','').' Tm ('.pdf_escape_text($line).") Tj\n";
    $y-=$lineHeight;
}
$stream.="ET\n";
$objects=[];
$objects[1]='<< /Type /Catalog /Pages 2 0 R >>';
$objects[2]='<< /Type /Pages /Kids [3 0 R] /Count 1 >>';
$objects[3]='<< /Type /Page /Parent 2 0 R /MediaBox [0 0 '.number_format($width,2,'.','').' '.number_format($height,2,'.','').'] /Resources << /Font << /F1 5 0 R >> >> /Contents 4 0 R >>';
$objects[4]='<< /Length '.strlen($stream)." >>\nstream\n".$stream.'endstream';
$objects[5]='<< /Type /Font /Subtype /Type1 /BaseFont /Courier >>';
$pdf="%PDF-1.4\n";$offsets=[0];
foreach($objects as $id=>$body){$offsets[$id]=strlen($pdf);$pdf.=$id." 0 obj\n".$body."\nendobj\n";}
$xref=strlen($pdf);$pdf.="xref\n0 6\n0000000000 65535 f \n";
for($i=1;$i<=5;$i++)$pdf.=sprintf('%010d 00000 n ', $offsets[$i])."\n";
$pdf.="trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n$xref\n%%EOF";
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="receipt-'.preg_replace('/[^A-Za-z0-9_-]/','',$receipt['receipt_number']).'.pdf"');
header('Content-Length: '.strlen($pdf));
echo $pdf;
