<?php

require_once "../init.php";

header('Content-Type: application/json');


$input = file_get_contents("php://input");


file_put_contents(
    __DIR__.'/bkash-debug.log',
    date('Y-m-d H:i:s').
    "\nRAW:\n".
    $input.
    "\n\n",
    FILE_APPEND
);



try {


$data = json_decode($input,true);


if(!$data){

    throw new Exception("Invalid JSON");

}


$msg = trim($data['msg'] ?? '');

if(empty($msg)){

    throw new Exception("Empty SMS");

}



/*
|--------------------------------------------------------------------------
| Router
|--------------------------------------------------------------------------
*/


$router_config = ORM::for_table('tbl_appconfig')
    ->where('setting','auto_payment_router_name')
    ->find_one();


if(!$router_config){

    throw new Exception("Router missing");

}


$router_name = trim($router_config->value);



/*
|--------------------------------------------------------------------------
| bKash SMS Regex
|--------------------------------------------------------------------------
*/


$regex = '/You have received Tk\s*([0-9,.]+)\s*from\s*([0-9]+)\.\s*Ref\s*([a-zA-Z0-9_-]+)\.\s*Fee\s*Tk\s*[0-9,.]+\.\s*Balance\s*Tk\s*[0-9,.]+\.\s*TrxID\s*([A-Z0-9]+)/i';



if(!preg_match($regex,$msg,$match)){

    throw new Exception("SMS format invalid");

}




/*
|--------------------------------------------------------------------------
| Extract Data
|--------------------------------------------------------------------------
*/


$amount = str_replace(',','',$match[1]);


// FIX DECIMAL ISSUE
$amount = (int)$amount;


$sender = trim($match[2]);


// bKash Ref = username
$username = trim($match[3]);


$trxid = trim($match[4]);





file_put_contents(
    __DIR__.'/bkash-webhook.log',
    date('Y-m-d H:i:s').
    " PARSED SUCCESS\n".
    "USERNAME: ".$username."\n".
    "AMOUNT: ".$amount."\n".
    "SENDER: ".$sender."\n".
    "TRXID: ".$trxid."\n\n",
    FILE_APPEND
);






/*
|--------------------------------------------------------------------------
| Duplicate Check
|--------------------------------------------------------------------------
*/


$duplicate = ORM::for_table('tbl_transactions')
    ->where_like(
        'note',
        '%'.$trxid.'%'
    )
    ->find_one();



if($duplicate){


    echo json_encode([
        "status"=>"success",
        "message"=>"Already processed"
    ]);


    exit;

}





/*
|--------------------------------------------------------------------------
| Find Customer
|--------------------------------------------------------------------------
*/


$customer = ORM::for_table('tbl_customers')
    ->where('username',$username)
    ->find_one();



if(!$customer){

    throw new Exception(
        "User not found : ".$username
    );

}



$user_id = $customer->id;



file_put_contents(
    __DIR__.'/bkash-webhook.log',
    date('Y-m-d H:i:s').
    " CUSTOMER FOUND ID: ".$user_id."\n",
    FILE_APPEND
);







/*
|--------------------------------------------------------------------------
| Find Plan
|--------------------------------------------------------------------------
*/


$plan = ORM::for_table('tbl_plans')
    ->where('price',$amount)
    ->where('type','PPPOE')
    ->where('enabled',1)
    ->find_one();



if(!$plan){


    throw new Exception(
        "Plan not found : ".$amount
    );


}





file_put_contents(
    __DIR__.'/bkash-webhook.log',
    date('Y-m-d H:i:s').
    " PLAN FOUND\n".
    "ID: ".$plan->id."\n".
    "NAME: ".$plan->name_plan."\n".
    "PRICE: ".$plan->price."\n".
    "ROUTER: ".$router_name."\n\n",
    FILE_APPEND
);







/*
|--------------------------------------------------------------------------
| Recharge User
|--------------------------------------------------------------------------
*/


file_put_contents(
    __DIR__.'/bkash-webhook.log',
    date('Y-m-d H:i:s').
    " START RECHARGE\n",
    FILE_APPEND
);





$result = Package::rechargeUser(

    $user_id,

    $router_name,

    $plan->id,

    "bKash SMS",

    "SmsForwarder",

    "TrxID: ".$trxid

);






file_put_contents(
    __DIR__.'/bkash-webhook.log',
    date('Y-m-d H:i:s').
    " RECHARGE SUCCESS\n".
    print_r($result,true).
    "\n\n",
    FILE_APPEND
);






echo json_encode([

    "status"=>"success",

    "message"=>"Recharge completed",

    "username"=>$username,

    "amount"=>$amount,

    "trxid"=>$trxid

]);





}




catch(Throwable $e){



file_put_contents(
    __DIR__.'/bkash-webhook.log',
    date('Y-m-d H:i:s').
    " ERROR\n".
    "MESSAGE: ".$e->getMessage().
    "\nFILE: ".$e->getFile().
    "\nLINE: ".$e->getLine().
    "\n\n",
    FILE_APPEND
);




http_response_code(200);



echo json_encode([

    "status"=>"error",

    "message"=>$e->getMessage()

]);



}