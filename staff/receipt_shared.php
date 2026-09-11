<?php
function staff_receipt_snapshot(mysqli $conn,int $transactionId): ?array {
    $stmt=$conn->prepare("SELECT t.*,r.receipt_number,c.full_name client_name,h.full_name cashier_name FROM pos_transactions t LEFT JOIN pos_receipts r ON r.transaction_id=t.id LEFT JOIN users c ON t.client_id=c.id LEFT JOIN users h ON t.handled_by=h.id WHERE t.id=? LIMIT 1");
    $stmt->bind_param('i',$transactionId);$stmt->execute();$tx=$stmt->get_result()->fetch_assoc();
    if(!$tx)return null;
    $itemsStmt=$conn->prepare("SELECT item_name,unit_price,quantity,line_total FROM pos_transaction_items WHERE transaction_id=? ORDER BY id");
    $itemsStmt->bind_param('i',$transactionId);$itemsStmt->execute();$items=[];$result=$itemsStmt->get_result();while($item=$result->fetch_assoc())$items[]=$item;
    $cash=null;$change=null;
    if(preg_match('/Cash received:\s*₱?([0-9,.]+)\s*\|\s*Change:\s*₱?([0-9,.]+)/u',(string)$tx['notes'],$m)){
        $cash=(float)str_replace(',','',$m[1]);$change=(float)str_replace(',','',$m[2]);
    }
    $cleanNote=trim((string)preg_replace('/(?:\r?\n)?Cash received:\s*₱?[0-9,.]+\s*\|\s*Change:\s*₱?[0-9,.]+/u','',(string)$tx['notes']));
    $receiptNumber=$tx['receipt_number']?:('VTX-'.str_pad((string)$transactionId,6,'0',STR_PAD_LEFT));
    return ['tx'=>$tx,'items'=>$items,'cash'=>$cash,'change'=>$change,'note'=>$cleanNote,'receipt_number'=>$receiptNumber];
}
function staff_receipt_text_lines(array $receipt): array {
    $tx=$receipt['tx'];
    $width=52;
    $center=static function(string $text) use($width): string {
        $text=preg_replace('/[^\x20-\x7E]/','',(string)$text);
        $text=substr($text,0,$width);
        $left=max(0,(int)floor(($width-strlen($text))/2));
        return str_repeat(' ',$left).$text;
    };
    $pair=static function(string $label,string $value) use($width): string {
        $label=preg_replace('/[^\x20-\x7E]/','',(string)$label);
        $value=preg_replace('/[^\x20-\x7E]/','',(string)$value);
        $space=max(1,$width-strlen($label)-strlen($value));
        return substr($label.str_repeat(' ',$space).$value,0,$width);
    };
    $money=static fn(float $value): string=>'PHP '.number_format($value,2);
    $lines=[];
    $lines[]=$center('VETRIX');
    $lines[]=$center('OFFICIAL POS RECEIPT');
    $lines[]=$center($receipt['receipt_number']);
    $lines[]=str_repeat('-', $width);
    $lines[]=$pair('Transaction #',(string)(int)$tx['id']);
    $lines[]=$pair('Date',date('M d, Y h:i A',strtotime($tx['transaction_date'])));
    $lines[]=$pair('Client',($tx['client_name']?:'Walk-in'));
    $lines[]=$pair('Cashier',($tx['cashier_name']?:'Clinic staff'));
    $lines[]=$pair('Status',strtoupper((string)$tx['payment_status']));
    $lines[]=$pair('Payment',strtoupper((string)($tx['payment_method']??'cash')));
    $lines[]=str_repeat('-', $width);
    $lines[]=$pair('ITEM / QTY','AMOUNT');
    $lines[]=str_repeat('-', $width);
    foreach($receipt['items'] as $item){
        $qty=rtrim(rtrim(number_format((float)$item['quantity'],2,'.',''),'0'),'.');
        $name=preg_replace('/[^\x20-\x7E]/','',(string)$item['item_name']);
        foreach(str_split($name, $width) as $nameLine)$lines[]=$nameLine;
        $detail=$qty.' x '.$money((float)$item['unit_price']);
        $lines[]=$pair('  '.$detail,$money((float)$item['line_total']));
    }
    $lines[]=str_repeat('-', $width);
    $lines[]=$pair('TOTAL',$money((float)$tx['total_amount']));
    if($receipt['cash']!==null){
        $lines[]=$pair('Cash received',$money((float)$receipt['cash']));
        $lines[]=$pair('Change',$money((float)$receipt['change']));
    }
    if($receipt['note']!==''){
        $lines[]='';
        $lines[]='NOTE';
        foreach(preg_split('/\R/u',$receipt['note']) as $noteLine){
            $noteLine=preg_replace('/[^\x20-\x7E]/','',(string)$noteLine);
            foreach(str_split($noteLine,$width) as $part)$lines[]=$part;
        }
    }
    $lines[]=str_repeat('-', $width);
    $lines[]=$center('Thank you for your purchase.');
    $lines[]=$center('Please keep this receipt for your records.');
    return $lines;
}
