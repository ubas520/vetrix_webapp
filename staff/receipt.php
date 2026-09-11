<?php
require_once "../config/database.php";
require_once "../includes/functions.php";
require_once __DIR__."/receipt_shared.php";
require_role("staff");
ensure_pos_product_schema($conn);
$transactionId=(int)($_GET['transaction_id']??0);
$receipt=staff_receipt_snapshot($conn,$transactionId);
if(!$receipt){http_response_code(404);exit('Receipt not found.');}
$tx=$receipt['tx'];$lines=staff_receipt_text_lines($receipt);
$title='Receipt #'.$transactionId;
$receiptFrom=strtolower(trim((string)($_GET['from']??'')));
if(!in_array($receiptFrom,['pos','receipts'],true)){
    $referer=(string)($_SERVER['HTTP_REFERER']??'');
    $receiptFrom=str_contains($referer,'/staff/receipts.php')?'receipts':'pos';
}
$backToReceipts=$receiptFrom==='receipts';
$backHref=app_url($backToReceipts?'staff/receipts.php':'staff/pos.php');
$backLabel=$backToReceipts?'Back to Receipts':'Back to POS';
$alternateHref=app_url($backToReceipts?'staff/pos.php':'staff/receipts.php');
$alternateLabel=$backToReceipts?'Open POS':'Receipt archive';
include "../includes/header.php";
?>
<style media="print">@page{size:4.125in 9.5in;margin:0}</style>
<main class="receipt-view-page receipt-notification-blur" id="mainContent">
<div class="receipt-view-actions no-print"><a class="button-secondary" href="<?=e($backHref)?>"><?=ui_icon('chevron-left')?><?=e($backLabel)?></a><a class="button-secondary" href="<?=e($alternateHref)?>"><?=$backToReceipts?ui_icon('cart'):ui_icon('receipt')?><?=e($alternateLabel)?></a><a class="button-secondary" href="<?=app_url('staff/receipt_pdf.php?transaction_id='.(int)$tx['id'])?>"><?=ui_icon('download')?>Download PDF</a><button class="button-primary" type="button" onclick="window.print()"><?=ui_icon('print')?>Print receipt</button></div>
<article class="receipt-paper receipt-paper-text"><pre class="receipt-text-layout"><?=e(implode("\n",$lines))?></pre></article>
</main>
<?php include "../includes/footer.php";?>
