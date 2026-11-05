sudo php -r '
require "init.php";

$r = ORM::for_table("tbl_appconfig")
    ->raw_query("SELECT DATABASE() AS db_name, @@hostname AS db_host, @@port AS db_port, CURRENT_USER() AS db_user")
    ->find_one();

echo "DATABASE: ".$r->db_name.PHP_EOL;
echo "HOST: ".$r->db_host.PHP_EOL;
echo "PORT: ".$r->db_port.PHP_EOL;
echo "USER: ".$r->db_user.PHP_EOL;

$t = ORM::for_table("tbl_appconfig")
    ->raw_query("SELECT TABLE_SCHEMA, TABLE_NAME FROM information_schema.TABLES WHERE TABLE_NAME = '\''tbl_autorecharge_receipts'\''")
    ->find_one();

if ($t) {
    echo "SCHEMA: ".$t->TABLE_SCHEMA.PHP_EOL;
    echo "TABLE: ".$t->TABLE_NAME.PHP_EOL;
} else {
    echo "Receipt table: NOT FOUND".PHP_EOL;
}
'
