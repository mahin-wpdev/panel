<?php

class isp_monthly_usage
{
    private function formatBytes($bytes)
    {
        $bytes = max(0, (float)$bytes);
        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        $power = $bytes > 0 ? min((int)floor(log($bytes, 1024)), count($units) - 1) : 0;
        return number_format($bytes / pow(1024, $power), $power >= 3 ? 2 : 1) . ' ' . $units[$power];
    }

    public function getWidget()
    {
        global $ui, $config;
        $resetDay = max(1, min(28, (int)($config['reset_day'] ?? 1)));
        $candidate = date('Y-m-') . sprintf('%02d', $resetDay);
        $cycleStart = date('Y-m-d') >= $candidate ? $candidate : date('Y-m-d', strtotime($candidate . ' -1 month'));
        $cycleEnd = date('Y-m-d', strtotime($cycleStart . ' +1 month -1 day'));

        $wan = ['isp1_rx' => 0, 'isp1_tx' => 0, 'isp2_rx' => 0, 'isp2_tx' => 0];
        try {
            $row = ORM::for_table('tbl_isp_usage_daily')
                ->select_expr('COALESCE(SUM(isp1_rx),0)', 'isp1_rx')
                ->select_expr('COALESCE(SUM(isp1_tx),0)', 'isp1_tx')
                ->select_expr('COALESCE(SUM(isp2_rx),0)', 'isp2_rx')
                ->select_expr('COALESCE(SUM(isp2_tx),0)', 'isp2_tx')
                ->where('cycle_start', $cycleStart)->find_one();
            if ($row) {
                foreach ($wan as $key => $unused) $wan[$key] = (float)$row[$key];
            }
        } catch (Throwable $e) {}

        $isp1 = $wan['isp1_rx'] + $wan['isp1_tx'];
        $isp2 = $wan['isp2_rx'] + $wan['isp2_tx'];
        $total = $isp1 + $isp2;

        $radiusCurrent = 0;
        $history = [];
        for ($i = 11; $i >= 0; $i--) {
            $start = date('Y-m-d', strtotime($cycleStart . " -$i months"));
            $history[$start] = ['label' => date('M Y', strtotime($start)), 'total' => 0];
        }
        try {
            $shift = $resetDay - 1;
            $sql = "SELECT DATE_FORMAT(DATE_SUB(acctstarttime, INTERVAL {$shift} DAY), '%Y-%m-01') cycle_key,
                           COALESCE(SUM(acctinputoctets + acctoutputoctets),0) total_bytes
                    FROM radacct
                    WHERE acctstarttime >= DATE_SUB(?, INTERVAL 11 MONTH)
                    GROUP BY cycle_key ORDER BY cycle_key";
            $rows = ORM::for_table('radacct', 'radius')->raw_query($sql, [$cycleStart . ' 00:00:00'])->find_array();
            foreach ($rows as $row) {
                $start = date('Y-m-d', strtotime($row['cycle_key'] . ' +' . $shift . ' days'));
                if (isset($history[$start])) $history[$start]['total'] = (float)$row['total_bytes'];
                if ($start === $cycleStart) $radiusCurrent = (float)$row['total_bytes'];
            }
        } catch (Throwable $e) {}

        $ui->assign('jmIspUsage', [
            'isp1' => $this->formatBytes($isp1), 'isp1_rx' => $this->formatBytes($wan['isp1_rx']), 'isp1_tx' => $this->formatBytes($wan['isp1_tx']),
            'isp2' => $this->formatBytes($isp2), 'isp2_rx' => $this->formatBytes($wan['isp2_rx']), 'isp2_tx' => $this->formatBytes($wan['isp2_tx']),
            'total' => $this->formatBytes($total), 'radius_total' => $this->formatBytes($radiusCurrent),
            'cycle_start' => $cycleStart, 'cycle_end' => $cycleEnd,
            'history_labels' => array_column($history, 'label'),
            'history_gb' => array_map(function ($r) { return round($r['total'] / 1073741824, 2); }, array_values($history)),
        ]);
        return $ui->fetch('widget/isp_monthly_usage.tpl');
    }
}

