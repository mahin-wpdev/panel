<?php
class ResellerBalance {
 public static function apply(PDO $db,int $resellerId,int $actor,string $operation,string $raw,string $method,string $reference,string $note,int $original=0,string $operationKey=''):void {
  $raw=strtr(trim($raw),['০'=>'0','১'=>'1','২'=>'2','৩'=>'3','৪'=>'4','৫'=>'5','৬'=>'6','৭'=>'7','৮'=>'8','৯'=>'9']);
  if($operationKey===''||!preg_match('/^[a-f0-9]{32}$/D',$operationKey))throw new InvalidArgumentException('Refresh the form and retry.');
  if($operation==='debt'){require_once __DIR__.'/ResellerDebt.php';ResellerDebt::add($db,$resellerId,$actor,$raw,$note,$operationKey);return;}
  if(!in_array($operation,['payment','add','remove','reverse'],true))throw new InvalidArgumentException('Choose a valid operation.');
  if(trim($note)===''&&$operation!=='payment')throw new InvalidArgumentException('Enter a reason.');
  if(strlen($note)>1000||strlen($reference)>100||strlen($method)>80)throw new InvalidArgumentException('Text is too long.');
  if($operation!=='reverse'&&(!preg_match('/^\d{1,8}(\.\d{1,2})?$/',$raw)||(float)$raw<=0))throw new InvalidArgumentException('Enter a positive amount with up to two decimal places.');
  $db->beginTransaction();
  try{
   $q=$db->prepare('SELECT id FROM tbl_resellers WHERE id=? FOR UPDATE');$q->execute([$resellerId]);if(!$q->fetchColumn())throw new InvalidArgumentException('Reseller was not found.');
   $q=$db->prepare('SELECT id,entry_type FROM tbl_reseller_settlements WHERE operation_key=? AND reseller_id=?');$q->execute([$operationKey,$resellerId]);$prior=$q->fetch(PDO::FETCH_ASSOC);
   if($prior){if($prior['entry_type']!==$operation)throw new InvalidArgumentException('This request key was already used for another action.');$db->commit();return;}
   require_once __DIR__.'/ResellerAccounting.php';
   $earned=ResellerAccounting::earned($db,$resellerId);
   $balance=round($earned-ResellerAccounting::settled($db,$resellerId),2);
   $amount=round((float)$raw,2);$reversal=null;
   if($operation==='reverse'){
    $q=$db->prepare("SELECT amount FROM tbl_reseller_settlements WHERE id=? AND reseller_id=? AND entry_type='payment' FOR UPDATE");$q->execute([$original,$resellerId]);$paid=$q->fetchColumn();
    if($paid===false||(float)$paid<=0)throw new InvalidArgumentException('Choose an original settlement for this reseller.');
    $q=$db->prepare('SELECT id FROM tbl_reseller_settlements WHERE reversal_of=?');$q->execute([$original]);if($q->fetchColumn())throw new InvalidArgumentException('This settlement was already reversed.');
    $amount=-(float)$paid;$reversal=$original;$method='Settlement reversal';
   }elseif($operation==='add'){$amount=-$amount;$method='Profit added';}
   elseif($operation==='remove'){$method='Profit removed';}
   if($amount>0&&$amount>$balance+0.0001)throw new InvalidArgumentException('Amount cannot exceed available profit.');
   $q=$db->prepare('INSERT INTO tbl_reseller_settlements (reseller_id,amount,payment_method,reference,note,processed_by,created_at,entry_type,reversal_of,operation_key) VALUES (?,?,?,?,?,?,NOW(),?,?,?)');
   $q->execute([$resellerId,$amount,$method?:'Cash',$reference,$note,$actor,$operation,$reversal,$operationKey]);$db->commit();
  }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
 }
}
