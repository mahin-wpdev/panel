<?php


class top_widget
{
    public function getWidget()
    {
        global $ui, $current_date, $start_date;

        require_once __DIR__.'/../autoload/ProfitSummary.php';
        $db = ORM::get_db();
        $ui->assign('iday', ProfitSummary::period($db, $current_date, $current_date)['total']);
        $ui->assign('imonth', ProfitSummary::period($db, $start_date, $current_date)['total']);

        $u_act = ORM::for_table('tbl_user_recharges')->where('status', 'on')->count();
        if (empty($u_act)) {
            $u_act = '0';
        }
        $ui->assign('u_act', $u_act);

        $u_all = ORM::for_table('tbl_user_recharges')->count();
        if (empty($u_all)) {
            $u_all = '0';
        }
        $ui->assign('u_all', $u_all);


        $c_all = ORM::for_table('tbl_customers')->count();
        if (empty($c_all)) {
            $c_all = '0';
        }
        $ui->assign('c_all', $c_all);
        return $ui->fetch('widget/top_widget.tpl');
    }
}
